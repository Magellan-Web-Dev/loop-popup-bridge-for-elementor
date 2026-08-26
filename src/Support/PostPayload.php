<?php

declare(strict_types=1);

namespace LoopPopupBridge\Support;

if (!defined('ABSPATH')) exit;

/**
 * Builds the curated, sanitised payload the frontend uses to populate a popup.
 *
 * Two callers share this class, and that sharing is the point: the payload the
 * page renders inline at load time and the payload the REST endpoint returns for
 * an AJAX-inserted item must be byte-for-byte the same shape, run the same access
 * checks, and pass through the same sanitisers. Duplicating any of that would let
 * the two paths drift — a field escaped one way inline and another way over REST
 * is exactly the kind of divergence that only shows up in production.
 *
 * Access control lives here rather than in the REST layer for the same reason:
 * the inline path has no permission_callback, so the checks have to sit with the
 * payload itself to be unskippable.
 */
final class PostPayload
{
    /**
     * Every base field the payload can carry.
     *
     * `id`, `custom_meta` and `meta_keys_loaded` are deliberately absent: they are
     * structural rather than content, and the frontend's cache bookkeeping reads
     * all three unconditionally.
     *
     * @var string[]
     */
    public const BASE_FIELDS = [
        'title',
        'excerpt',
        'content',
        'permalink',
        'featured_image',
        'featured_image_alt',
        'post_type',
        'date',
        'modified',
    ];

    /**
     * Builds the payload for one post, or a WP_Error when it must not be exposed.
     *
     * Access checks, in order:
     *   1. Post exists.
     *   2. Post status is "publish".
     *   3. Post is not password-protected.
     *   4. Post type is publicly accessible.
     *
     * @param  int           $post_id             Post to describe.
     * @param  string[]      $requested_meta_keys Meta keys the caller wants, pre-allowlist.
     * @param  string[]|null $fields              Base fields to include; null means all of
     *                                            them. REST passes null so its response is
     *                                            always complete and can serve as the
     *                                            frontend's fallback for anything the
     *                                            inline payload left out.
     * @return array<string, mixed>|\WP_Error
     */
    public static function build(int $post_id, array $requested_meta_keys, ?array $fields = null): array|\WP_Error
    {
        $post = get_post($post_id);

        if (!($post instanceof \WP_Post)) {
            return new \WP_Error(
                'lpb_not_found',
                esc_html__('Post not found.', 'loop-popup-bridge'),
                ['status' => 404]
            );
        }

        if ('publish' !== $post->post_status) {
            return new \WP_Error(
                'lpb_not_published',
                esc_html__('Post is not publicly available.', 'loop-popup-bridge'),
                ['status' => 403]
            );
        }

        if (post_password_required($post)) {
            return new \WP_Error(
                'lpb_password_required',
                esc_html__('Post is password-protected.', 'loop-popup-bridge'),
                ['status' => 403]
            );
        }

        $post_type = get_post_type_object($post->post_type);
        if (null === $post_type || !$post_type->public) {
            return new \WP_Error(
                'lpb_private_type',
                esc_html__('Post type is not publicly accessible.', 'loop-popup-bridge'),
                ['status' => 403]
            );
        }

        $requested_meta_keys = array_values(array_unique(array_filter(
            array_map('sanitize_key', $requested_meta_keys)
        )));

        $allowed_keys = self::resolve_allowed_meta_keys($requested_meta_keys);
        $fields       = self::resolve_fields($fields);

        $data = ['id' => $post->ID];

        // Built per requested field rather than all at once: `content` is by far the
        // most expensive one — it runs the whole the_content filter chain, which on
        // an Elementor post means rendering a document — so a caller that does not
        // want it must not pay for it.
        foreach ($fields as $field) {
            $data[$field] = match ($field) {
                'title'              => wp_kses_post(get_the_title($post)),
                'excerpt'            => wp_kses_post(get_the_excerpt($post)),
                'content'            => wp_kses_post(self::render_content($post)),
                'permalink'          => esc_url((string) get_permalink($post)),
                'featured_image'     => self::get_featured_image_url($post->ID),
                'featured_image_alt' => self::get_featured_image_alt($post->ID),
                'post_type'          => sanitize_key($post->post_type),
                'date'               => esc_html((string) get_the_date('', $post)),
                'modified'           => esc_html((string) get_the_modified_date('', $post)),
                default              => null,
            };
        }

        $data['custom_meta'] = self::get_meta($post->ID, $allowed_keys);

        // The keys the caller ASKED for, not the ones that survived the allowlist.
        // The frontend uses this to tell "loaded and empty" from "never requested"
        // (see isUnloadedMetaBinding in loop-popup-bridge.js): a key that can never
        // arrive because it is not allowlisted still counts as resolved, so the
        // binding renders its configured fallback instead of waiting forever.
        $data['meta_keys_loaded'] = $requested_meta_keys;

        return $data;
    }

    /**
     * Normalises the requested base-field list.
     *
     * Null means "everything". Unknown names are dropped rather than trusted, so a
     * typo from a caller or a filter cannot inject an unbuildable key, and the
     * result keeps BASE_FIELDS order regardless of what order it arrived in.
     *
     * @param  string[]|null $fields
     * @return string[]
     */
    private static function resolve_fields(?array $fields): array
    {
        if (null === $fields) {
            return self::BASE_FIELDS;
        }

        return array_values(array_intersect(self::BASE_FIELDS, $fields));
    }

    /**
     * Renders a post's content with that post actually in context.
     *
     * The context is the entire point. Elementor's the_content filter
     * (Frontend::apply_builder_in_content) resolves which document to render from
     * `get_the_ID()` and ignores the string it was handed, so filtering one post's
     * content while a different post is current returns *that* post's builder
     * output. In the REST context get_the_ID() is false and the mistake is
     * invisible; at wp_footer it is the page, and every entry in the payload came
     * back holding a copy of the whole page — 174 KB apiece, 23 times over on the
     * page that surfaced this.
     *
     * Restoring by re-running setup_postdata() on the previous post rather than
     * just reassigning $post matters too: setup_postdata() writes a whole set of
     * globals ($id, $authordata, $page, $pages, $multipage, $more, $numpages), and
     * leaving those pointing at a loop item would corrupt whatever renders next.
     */
    private static function render_content(\WP_Post $target): string
    {
        global $post;

        $previous = $post;

        $post = $target;
        setup_postdata($post);

        $rendered = (string) apply_filters('the_content', $target->post_content);

        $post = $previous;

        if ($previous instanceof \WP_Post) {
            setup_postdata($previous);
        } else {
            wp_reset_postdata();
        }

        return $rendered;
    }

    /**
     * Returns the full-size URL of the post's featured image.
     *
     * Returns an empty string when no thumbnail is set or the attachment URL
     * cannot be resolved.
     */
    private static function get_featured_image_url(int $post_id): string
    {
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        if ($thumb_id <= 0) {
            return '';
        }
        $src = wp_get_attachment_image_src($thumb_id, 'full');
        return $src ? esc_url($src[0]) : '';
    }

    /**
     * Returns the alt text stored on the post's featured image attachment.
     *
     * Returns an empty string when no thumbnail is set or the alt meta is empty.
     */
    private static function get_featured_image_alt(int $post_id): string
    {
        $thumb_id = (int) get_post_thumbnail_id($post_id);
        if ($thumb_id <= 0) {
            return '';
        }
        return esc_attr((string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true));
    }

    /**
     * Intersects the requested meta keys with the server-side allowlist.
     *
     * By default the allowlist holds every registered ACF field name; anything
     * else must be opted in via the lpb_allowed_meta_keys filter.
     *
     * Example (in a theme or plugin):
     *   add_filter('lpb_allowed_meta_keys', fn($keys) => [...$keys, 'event_date', 'ticket_url']);
     *
     * @param  string[] $requested  Keys requested by the caller.
     * @return string[]             Keys that pass the allowlist check.
     */
    private static function resolve_allowed_meta_keys(array $requested): array
    {
        $allowlist = FieldRegistry::get_allowed_meta_keys();

        if (empty($allowlist) || empty($requested)) {
            return [];
        }

        return array_values(array_intersect($requested, $allowlist));
    }

    /**
     * Retrieves and sanitises the value of each allowlisted meta key for a post.
     *
     * Only processes keys already verified by resolve_allowed_meta_keys().
     *
     * @param  string[] $keys  Allowlisted meta keys to fetch.
     * @return array<string, mixed>  Map of meta key to sanitised value.
     */
    private static function get_meta(int $post_id, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $key          = sanitize_key($key);
            $result[$key] = self::sanitize_meta_value(self::get_meta_value($post_id, $key));
        }
        return $result;
    }

    /**
     * Retrieves an ACF-formatted value when available, otherwise raw post meta.
     */
    private static function get_meta_value(int $post_id, string $key): mixed
    {
        if (function_exists('get_field')) {
            $acf_value = get_field($key, $post_id);

            if (null !== $acf_value && '' !== $acf_value) {
                return $acf_value;
            }
        }

        return get_post_meta($post_id, $key, true);
    }

    /**
     * Sanitizes scalar and common object/array meta values for public output.
     * Strings are passed through wp_kses_post() so HTML markup (e.g. from ACF
     * wysiwyg or textarea fields) is preserved while unsafe tags are stripped.
     */
    private static function sanitize_meta_value(mixed $value): mixed
    {
        if (is_string($value)) {
            return wp_kses_post($value);
        }

        if (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
            return $value;
        }

        if ($value instanceof \WP_Post) {
            return [
                'id'        => $value->ID,
                'title'     => esc_html(get_the_title($value)),
                'permalink' => esc_url((string) get_permalink($value)),
            ];
        }

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $key => $item) {
                $sanitized[$key] = self::sanitize_meta_value($item);
            }

            return $sanitized;
        }

        return '';
    }

    private function __construct()
    {
    }
}
