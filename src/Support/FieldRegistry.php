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
     * ACF field types whose stored value is an email address.
     *
     * Used to tag URL bindings so the frontend can prepend `mailto:`.
     */
    private const EMAIL_FIELD_TYPES = ['email'];

    /**
     * Field types whose stored value is a telephone number.
     *
     * Core ACF ships no native phone field, so this list covers the type
     * identifiers registered by the common third-party phone add-ons. Generic
     * `text` and `number` fields are deliberately excluded — use the
     * `lpb_url_binding_value_type` filter to opt those in explicitly.
     */
    private const PHONE_FIELD_TYPES = ['phone_number', 'phone', 'tel', 'telephone'];

    /** Value types the frontend understands for URL bindings. */
    private const URL_VALUE_TYPES = ['email', 'phone'];

    /** @var array<string, array{label: string, location_label: string, type: string}>|null */
    private static ?array $acf_field_data_cache = null;

    // ── Public option builders ────────────────────────────────────────────────────

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
     * Returns a grouped options array for text-capable SELECT controls.
     *
     * Uses Elementor's `groups` control key format (numeric-indexed array of
     * {label, options} objects), which renders each entry as an <optgroup>
     * section header in the dropdown.
     *
     * @return list<array{label: string, options: array<string, string>}>
     */
    public static function get_text_groups(): array
    {
        $groups = [];

        $groups[] = [
            'label'   => esc_html__('Post Fields', 'loop-popup-bridge'),
            'options' => [
                'title'     => esc_html__('Post Title', 'loop-popup-bridge'),
                'excerpt'   => esc_html__('Post Excerpt', 'loop-popup-bridge'),
                'content'   => esc_html__('Post Content', 'loop-popup-bridge'),
                'date'      => esc_html__('Published Date', 'loop-popup-bridge'),
                'modified'  => esc_html__('Modified Date', 'loop-popup-bridge'),
                'post_type' => esc_html__('Post Type', 'loop-popup-bridge'),
                'id'        => esc_html__('Post ID', 'loop-popup-bridge'),
                'permalink' => esc_html__('Permalink', 'loop-popup-bridge'),
            ],
        ];

        $acf_data    = self::get_acf_field_data();
        $manual_keys = array_map('sanitize_key', (array) apply_filters('lpb_allowed_meta_keys', []));
        $manual_keys = array_values(array_filter($manual_keys, static fn(string $k): bool => '' !== $k));
        $seen        = [];

        if (!empty($manual_keys)) {
            $custom_options = [];
            foreach ($manual_keys as $key) {
                $label = isset($acf_data[$key]) ? $acf_data[$key]['label'] : self::label_from_key($key);
                $custom_options[self::META_PREFIX . $key] = $label;
                $seen[$key] = true;
            }
            $groups[] = [
                'label'   => esc_html__('Custom Fields', 'loop-popup-bridge'),
                'options' => $custom_options,
            ];
        }

        // ACF fields grouped by their post-type location.
        $by_location = [];
        foreach ($acf_data as $key => $data) {
            if (isset($seen[$key])) {
                continue;
            }
            $by_location[$data['location_label']][self::META_PREFIX . $key] = $data['label'];
        }

        foreach ($by_location as $location => $fields) {
            $label = '' !== $location
                ? sprintf(
                    /* translators: %s: post type label */
                    esc_html__('%s (ACF)', 'loop-popup-bridge'),
                    $location
                )
                : esc_html__('ACF Fields', 'loop-popup-bridge');

            $groups[] = [
                'label'   => $label,
                'options' => $fields,
            ];
        }

        $groups[] = [
            'label'   => esc_html__('Other', 'loop-popup-bridge'),
            'options' => ['custom' => esc_html__('Custom Key…', 'loop-popup-bridge')],
        ];

        /** @var list<array{label: string, options: array<string, string>}> $filtered */
        $filtered = (array) apply_filters('lpb_dynamic_tag_field_groups', $groups, 'text');

        return $filtered;
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
     * Only includes ACF fields whose type is "image"; all other custom meta is excluded.
     *
     * @return array<string, string>
     */
    public static function get_image_options(): array
    {
        $options = [
            'featured_image' => esc_html__('Featured Image', 'loop-popup-bridge'),
        ];

        foreach (self::get_acf_field_data() as $key => $data) {
            if ('image' !== $data['type']) {
                continue;
            }
            $options[self::META_PREFIX . $key] = sprintf(
                esc_html__('ACF: %s', 'loop-popup-bridge'),
                $data['label']
            );
        }

        $options['custom'] = esc_html__('Custom Key…', 'loop-popup-bridge');

        return self::filter_options('image', $options);
    }

    /**
     * Returns a grouped options array for image-capable SELECT controls.
     * Only includes ACF fields whose type is "image".
     *
     * @return list<array{label: string, options: array<string, string>}>
     */
    public static function get_image_groups(): array
    {
        $groups = [];

        $groups[] = [
            'label'   => esc_html__('Post Fields', 'loop-popup-bridge'),
            'options' => [
                'featured_image' => esc_html__('Featured Image', 'loop-popup-bridge'),
            ],
        ];

        $by_location = [];
        foreach (self::get_acf_field_data() as $key => $data) {
            if ('image' !== $data['type']) {
                continue;
            }
            $by_location[$data['location_label']][self::META_PREFIX . $key] = $data['label'];
        }

        foreach ($by_location as $location => $fields) {
            $label = '' !== $location
                ? sprintf(
                    /* translators: %s: post type label */
                    esc_html__('%s (ACF)', 'loop-popup-bridge'),
                    $location
                )
                : esc_html__('ACF Fields', 'loop-popup-bridge');

            $groups[] = [
                'label'   => $label,
                'options' => $fields,
            ];
        }

        $groups[] = [
            'label'   => esc_html__('Other', 'loop-popup-bridge'),
            'options' => ['custom' => esc_html__('Custom Key…', 'loop-popup-bridge')],
        ];

        /** @var list<array{label: string, options: array<string, string>}> $filtered */
        $filtered = (array) apply_filters('lpb_dynamic_tag_field_groups', $groups, 'image');

        return $filtered;
    }

    // ── Binding resolution ────────────────────────────────────────────────────────

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
     * When the binding points at a field whose value is an email address or a
     * telephone number, the marker carries an extra `lpb-value-type` segment so
     * the frontend can prepend the matching URI scheme (`mailto:` / `tel:`).
     * Ordinary URL bindings keep the original two-segment marker.
     *
     * @param array{field: string, meta_key: string} $binding
     */
    public static function get_hash_marker(array $binding): string
    {
        $marker = '#lpb-field=' . rawurlencode($binding['field']);

        if ('meta' === $binding['field'] && '' !== $binding['meta_key']) {
            $marker .= '&lpb-meta-key=' . rawurlencode($binding['meta_key']);
        }

        $value_type = self::resolve_url_value_type($binding);

        if ('' !== $value_type) {
            $marker .= '&lpb-value-type=' . rawurlencode($value_type);
        }

        return $marker;
    }

    /**
     * Resolves the value type of a URL binding: "email", "phone", or "" for an
     * ordinary URL.
     *
     * The type is derived from the registered ACF field type only — never from
     * the field label, the meta key, or the stored value itself. Fields that are
     * not registered with ACF (manually allowlisted meta, post fields such as
     * the permalink) resolve to "" unless a project opts them in.
     *
     * Filter: `lpb_url_binding_value_type`
     *   @param string                                                              $value_type Resolved type ("email", "phone", or "").
     *   @param string                                                              $meta_key   Custom field key, or "" for built-in post fields.
     *   @param array{label: string, location_label: string, type: string}|null     $field_data ACF field data when the key is a registered ACF field.
     *   @param array{field: string, meta_key: string}                              $binding    The full binding being rendered.
     *
     * Returned values outside the supported set are discarded, so a filter can
     * only ever classify a binding as email, phone, or ordinary URL.
     *
     * @param array{field: string, meta_key: string} $binding
     */
    public static function resolve_url_value_type(array $binding): string
    {
        $meta_key = 'meta' === $binding['field'] ? $binding['meta_key'] : '';

        $field_data = ('' !== $meta_key)
            ? (self::get_acf_field_data()[$meta_key] ?? null)
            : null;

        $value_type = '';

        if (null !== $field_data) {
            $acf_type = strtolower($field_data['type']);

            if (in_array($acf_type, self::EMAIL_FIELD_TYPES, true)) {
                $value_type = 'email';
            } elseif (in_array($acf_type, self::PHONE_FIELD_TYPES, true)) {
                $value_type = 'phone';
            }
        }

        $value_type = (string) apply_filters(
            'lpb_url_binding_value_type',
            $value_type,
            $meta_key,
            $field_data,
            $binding
        );

        return in_array($value_type, self::URL_VALUE_TYPES, true) ? $value_type : '';
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

    // ── Allowlist ─────────────────────────────────────────────────────────────────

    /**
     * Returns the server-side allowlisted custom field keys.
     *
     * Registered ACF fields are automatically included so they work in the REST
     * endpoint without requiring manual filter configuration.
     *
     * @return string[]
     */
    public static function get_allowed_meta_keys(): array
    {
        $keys = (array) apply_filters('lpb_allowed_meta_keys', []);
        $keys = array_merge($keys, array_keys(self::get_acf_field_data()));
        $keys = array_map('sanitize_key', $keys);
        $keys = array_filter($keys, static fn(string $key): bool => '' !== $key);

        return array_values(array_unique($keys));
    }

    // ── Private helpers ───────────────────────────────────────────────────────────

    /**
     * Appends allowlisted custom fields, ACF fields, and a manual custom-key
     * option to a flat options array (used by URL and image tag controls).
     *
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private static function append_meta_options(array $options): array
    {
        $acf_data    = self::get_acf_field_data();
        $manual_keys = array_map('sanitize_key', (array) apply_filters('lpb_allowed_meta_keys', []));
        $manual_keys = array_values(array_filter($manual_keys, static fn(string $k): bool => '' !== $k));
        $seen        = [];

        foreach ($manual_keys as $key) {
            $label = isset($acf_data[$key]) ? $acf_data[$key]['label'] : self::label_from_key($key);
            $options[self::META_PREFIX . $key] = sprintf(
                /* translators: %s: custom field label */
                esc_html__('Custom: %s', 'loop-popup-bridge'),
                $label
            );
            $seen[$key] = true;
        }

        foreach ($acf_data as $key => $data) {
            if (isset($seen[$key])) {
                continue;
            }
            $options[self::META_PREFIX . $key] = sprintf(
                /* translators: %s: ACF field label */
                esc_html__('ACF: %s', 'loop-popup-bridge'),
                $data['label']
            );
        }

        $options['custom'] = esc_html__('Custom Key…', 'loop-popup-bridge');

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
     * Returns all ACF fields keyed by field name, each carrying its label, a
     * resolved location label (post type display name or group title), and the
     * registered ACF field type.
     *
     * Results are cached for the lifetime of the request.
     *
     * @return array<string, array{label: string, location_label: string, type: string}>
     */
    private static function get_acf_field_data(): array
    {
        if (null !== self::$acf_field_data_cache) {
            return self::$acf_field_data_cache;
        }

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            self::$acf_field_data_cache = [];
            return [];
        }

        $data   = [];
        $groups = (array) acf_get_field_groups();

        foreach ($groups as $group) {
            $location_label = self::resolve_group_location_label($group);
            $fields         = (array) acf_get_fields($group);
            self::collect_acf_field_data($fields, $location_label, $data);
        }

        self::$acf_field_data_cache = $data;

        return $data;
    }

    /**
     * Resolves a human-readable location label for an ACF field group.
     *
     * Scans the group's location rules for `post_type ==` conditions and
     * returns the singular post type label(s). Falls back to the group title
     * when no post-type rule is found (e.g. options pages, user forms).
     *
     * @param array<string, mixed> $group
     */
    private static function resolve_group_location_label(array $group): string
    {
        $post_types = [];

        foreach ((array) ($group['location'] ?? []) as $rule_group) {
            foreach ((array) $rule_group as $rule) {
                if (
                    isset($rule['param'], $rule['operator'], $rule['value']) &&
                    'post_type' === $rule['param'] &&
                    '==' === $rule['operator'] &&
                    '' !== (string) $rule['value']
                ) {
                    $ptype = get_post_type_object((string) $rule['value']);
                    $label = $ptype
                        ? (string) $ptype->labels->singular_name
                        : ucwords(str_replace('_', ' ', (string) $rule['value']));
                    $post_types[$label] = true;
                }
            }
        }

        if (!empty($post_types)) {
            return implode(', ', array_keys($post_types));
        }

        return (string) ($group['title'] ?? '');
    }

    /**
     * Recursively collects field name → {label, location_label, type} entries.
     *
     * @param array<int, array<string, mixed>>                                        $fields
     * @param array<string, array{label: string, location_label: string, type: string}> $data
     */
    private static function collect_acf_field_data(array $fields, string $location_label, array &$data): void
    {
        foreach ($fields as $field) {
            $name = sanitize_key((string) ($field['name'] ?? ''));
            if ('' !== $name) {
                $data[$name] = [
                    'label'          => (string) ($field['label'] ?? self::label_from_key($name)),
                    'location_label' => $location_label,
                    'type'           => (string) ($field['type'] ?? ''),
                ];
            }

            if (!empty($field['sub_fields']) && is_array($field['sub_fields'])) {
                self::collect_acf_field_data($field['sub_fields'], $location_label, $data);
            }
        }
    }

    private static function label_from_key(string $key): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $key));
    }

    private function __construct() {}
}
