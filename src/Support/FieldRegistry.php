<?php

declare(strict_types=1);

namespace LoopPopupBridge\Support;

if (!defined('ABSPATH')) exit;

/**
 * Shared field option and binding helpers for popup-side dynamic tags.
 */
final class FieldRegistry
{
    private const META_PREFIX = 'meta:';

    /**
     * Returns selectable fields for text-capable Elementor dynamic tag controls.
     *
     * @return array<string, string>
     */
    public static function get_text_options(): array
    {
        return self::filter_options('text', self::append_meta_options([
            'title'     => esc_html__('Post Title', 'loop-popup-bridge'),
            'excerpt'   => esc_html__('Post Excerpt', 'loop-popup-bridge'),
            'content'   => esc_html__('Post Content', 'loop-popup-bridge'),
            'date'      => esc_html__('Published Date', 'loop-popup-bridge'),
            'modified'  => esc_html__('Modified Date', 'loop-popup-bridge'),
            'post_type' => esc_html__('Post Type', 'loop-popup-bridge'),
            'id'        => esc_html__('Post ID', 'loop-popup-bridge'),
            'permalink' => esc_html__('Permalink', 'loop-popup-bridge'),
        ]));
    }

    /**
     * Returns selectable fields for URL-capable Elementor dynamic tag controls.
     *
     * @return array<string, string>
     */
    public static function get_url_options(): array
    {
        return self::filter_options('url', self::append_meta_options([
            'permalink' => esc_html__('Permalink', 'loop-popup-bridge'),
        ]));
    }

    /**
     * Returns selectable fields for image/media Elementor dynamic tag controls.
     *
     * @return array<string, string>
     */
    public static function get_image_options(): array
    {
        return self::filter_options('image', self::append_meta_options([
            'featured_image' => esc_html__('Featured Image', 'loop-popup-bridge'),
        ]));
    }

    /**
     * Converts a selected option into the frontend binding shape.
     *
     * @param string $selection  Select control value.
     * @param string $custom_key Manual custom-meta key when selection is "custom".
     * @return array{field: string, meta_key: string}|null
     */
    public static function resolve_selection(string $selection, string $custom_key = ''): ?array
    {
        if ('custom' === $selection) {
            $custom_key = sanitize_key($custom_key);

            return '' === $custom_key
                ? null
                : ['field' => 'meta', 'meta_key' => $custom_key];
        }

        if (str_starts_with($selection, self::META_PREFIX)) {
            $meta_key = sanitize_key(substr($selection, strlen(self::META_PREFIX)));

            return '' === $meta_key
                ? null
                : ['field' => 'meta', 'meta_key' => $meta_key];
        }

        $field = sanitize_key($selection);

        return '' === $field
            ? null
            : ['field' => $field, 'meta_key' => ''];
    }

    /**
     * Returns an href-safe marker for URL dynamic tags.
     *
     * @param array{field: string, meta_key: string} $binding
     */
    public static function get_hash_marker(array $binding): string
    {
        $marker = '#lpb-field=' . rawurlencode($binding['field']);

        if ('meta' === $binding['field'] && '' !== $binding['meta_key']) {
            $marker .= '&lpb-meta-key=' . rawurlencode($binding['meta_key']);
        }

        return $marker;
    }

    /**
     * Adds binding query args to a fallback image URL for image/media tags.
     *
     * @param array{field: string, meta_key: string} $binding
     */
    public static function add_marker_query_args(string $url, array $binding): string
    {
        $args = ['lpb-field' => $binding['field']];

        if ('meta' === $binding['field'] && '' !== $binding['meta_key']) {
            $args['lpb-meta-key'] = $binding['meta_key'];
        }

        return add_query_arg($args, $url);
    }

    /**
     * Returns the server-side allowlisted custom field keys.
     *
     * @return string[]
     */
    public static function get_allowed_meta_keys(): array
    {
        $keys = (array) apply_filters('lpb_allowed_meta_keys', []);
        $keys = array_map('sanitize_key', $keys);
        $keys = array_filter($keys, static fn(string $key): bool => '' !== $key);

        return array_values(array_unique($keys));
    }

    /**
     * Appends allowlisted custom fields and a manual custom-key option.
     *
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private static function append_meta_options(array $options): array
    {
        $labels = self::get_acf_field_labels();

        foreach (self::get_allowed_meta_keys() as $key) {
            $label = $labels[$key] ?? self::label_from_key($key);
            $options[self::META_PREFIX . $key] = sprintf(
                /* translators: %s: custom field label */
                esc_html__('Custom: %s', 'loop-popup-bridge'),
                $label
            );
        }

        $options['custom'] = esc_html__('Custom Key...', 'loop-popup-bridge');

        return $options;
    }

    /**
     * Allows projects to adjust the dynamic tag field options by target type.
     *
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private static function filter_options(string $target, array $options): array
    {
        /** @var array<string, string> $filtered */
        $filtered = (array) apply_filters('lpb_dynamic_tag_field_options', $options, $target);

        return $filtered;
    }

    /**
     * Maps ACF field names to labels when ACF is available.
     *
     * @return array<string, string>
     */
    private static function get_acf_field_labels(): array
    {
        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            return [];
        }

        $labels = [];
        $groups = (array) acf_get_field_groups();

        foreach ($groups as $group) {
            $fields = (array) acf_get_fields($group);
            self::collect_acf_labels($fields, $labels);
        }

        return $labels;
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @param array<string, string>            $labels
     */
    private static function collect_acf_labels(array $fields, array &$labels): void
    {
        foreach ($fields as $field) {
            $name = sanitize_key((string) ($field['name'] ?? ''));
            if ('' !== $name) {
                $labels[$name] = (string) ($field['label'] ?? self::label_from_key($name));
            }

            if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                self::collect_acf_labels($field['sub_fields'], $labels);
            }
        }
    }

    private static function label_from_key(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    private function __construct() {}
}
