<?php

declare(strict_types=1);

namespace LoopPopupBridge\REST;

if (!defined('ABSPATH')) exit;

use LoopPopupBridge\Support\FieldRegistry;

/**
 * REST endpoint: GET /wp-json/loop-popup-bridge/v1/post/{id}
 *
 * Returns a curated JSON payload for a single published post so the frontend
 * JavaScript can populate Elementor popup field widgets without embedding
 * post data in the page's HTML.
 *
 * Security model:
 *   permission_callback is __return_true (the endpoint is publicly readable,
 *   matching WordPress core's behaviour for published post data via the REST
 *   API). The callback itself performs all access-control checks:
 *     – Post must exist and be published.
 *     – Post must not be password-protected.
 *     – Post type must be marked as public.
 *   Custom meta is opt-in: callers pass ?meta_keys=key1,key2 and only keys
 *   explicitly allowed via the lpb_allowed_meta_keys filter are returned.
 */
final class PostEndpoint
{
    /**
     * REST API namespace for all routes registered by this plugin.
     *
     * @var string
     */
    private const NAMESPACE = 'loop-popup-bridge/v1';

    /**
     * Route pattern for the single-post endpoint.
     * The named capture group id matches one or more digits.
     *
     * @var string
     */
    private const ROUTE = '/post/(?P<id>[\d]+)';

    /**
     * Registers the REST route with WordPress.
     *
     * Should be called inside a rest_api_init action callback.
     *
     * @return void
     */
    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'handle_request'],
            'permission_callback' => '__return_true',
            'args'                => [
                'id'        => [
                    'required'          => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => static fn(mixed $v): bool => is_numeric($v) && (int) $v > 0,
                    'description'       => 'Numeric post ID.',
                ],
                'meta_keys' => [
                    'required'          => false,
                    'default'           => [],
                    'sanitize_callback' => static function (mixed $v): array {
                        if (!is_array($v)) {
                            $v = explode(',', (string) $v);
                        }
                        return array_values(array_filter(array_map('sanitize_key', $v)));
                    },
                    'description'       => 'Comma-separated list of meta keys to include (subject to server-side allowlist).',
                ],
            ],
        ]);
    }

    /**
     * Handles an incoming REST request and returns post data or a WP_Error.
     *
     * Access checks (in order):
     *   1. Post exists.
     *   2. Post status is "publish".
     *   3. Post is not password-protected.
     *   4. Post type is publicly accessible.
     *
     * @param  \WP_REST_Request $request  The incoming REST request object.
     * @return \WP_REST_Response|\WP_Error  200 response with post data, or an error.
     */
    public function handle_request(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $post_id = absint($request->get_param('id'));
        $post    = get_post($post_id);

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

        $requested_keys = (array) $request->get_param('meta_keys');
        $allowed_keys   = $this->resolve_allowed_meta_keys($requested_keys);

        $data = [
            'id'                 => $post->ID,
            'title'              => wp_kses_post(get_the_title($post)),
            'excerpt'            => wp_kses_post(get_the_excerpt($post)),
            'content'            => wp_kses_post((string) apply_filters('the_content', $post->post_content)),
            'permalink'          => esc_url((string) get_permalink($post)),
            'featured_image'     => $this->get_featured_image_url($post->ID),
            'featured_image_alt' => $this->get_featured_image_alt($post->ID),
            'post_type'          => sanitize_key($post->post_type),
            'date'               => esc_html((string) get_the_date('', $post)),
            'modified'           => esc_html((string) get_the_modified_date('', $post)),
            'custom_meta'        => $this->get_meta($post->ID, $allowed_keys),
        ];

        return new \WP_REST_Response($data, 200);
    }

    // ── Private helpers ───────────────────────────────────────────────────────────

    /**
     * Returns the full-size URL of the post's featured image.
     *
     * Returns an empty string when no thumbnail is set or the attachment URL
     * cannot be resolved.
     *
     * @param  int    $post_id  Post ID.
     * @return string           Escaped image URL, or empty string.
     */
    private function get_featured_image_url(int $post_id): string
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
     *
     * @param  int    $post_id  Post ID.
     * @return string           Escaped alt text, or empty string.
     */
    private function get_featured_image_alt(int $post_id): string
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
     * By default the allowlist is empty — no meta is exposed unless a developer
     * explicitly allows keys via the lpb_allowed_meta_keys filter.
     *
     * Example (in a theme or plugin):
     *   add_filter('lpb_allowed_meta_keys', fn($keys) => [...$keys, 'event_date', 'ticket_url']);
     *
     * @param  string[] $requested  Keys requested by the client.
     * @return string[]             Keys that pass the allowlist check.
     */
    private function resolve_allowed_meta_keys(array $requested): array
    {
        $allowlist = FieldRegistry::get_allowed_meta_keys();

        if (empty($allowlist) || empty($requested)) {
            return [];
        }

        return array_values(array_intersect($requested, $allowlist));
    }

    /**
     * Retrieves and escapes the value of each requested meta key for a post.
     *
     * Only processes keys that have already been verified against the allowlist
     * by resolve_allowed_meta_keys(). String values are passed through esc_html();
     * non-scalar values (arrays, objects) are returned as-is for flexibility.
     *
     * @param  int      $post_id  Post ID.
     * @param  string[] $keys     Allowlisted meta keys to fetch.
     * @return array<string, mixed>  Map of meta key to sanitised value.
     */
    private function get_meta(int $post_id, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $key          = sanitize_key($key);
            $result[$key] = $this->sanitize_meta_value($this->get_meta_value($post_id, $key));
        }
        return $result;
    }

    /**
     * Retrieves an ACF-formatted value when available, otherwise raw post meta.
     *
     * @return mixed
     */
    private function get_meta_value(int $post_id, string $key): mixed
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
     * Sanitizes scalar and common object/array meta values for public JSON output.
     * Strings are passed through wp_kses_post() so HTML markup (e.g. from ACF
     * wysiwyg or textarea fields) is preserved while unsafe tags are stripped.
     *
     * @return mixed
     */
    private function sanitize_meta_value(mixed $value): mixed
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
                $sanitized[$key] = $this->sanitize_meta_value($item);
            }

            return $sanitized;
        }

        return '';
    }
}
