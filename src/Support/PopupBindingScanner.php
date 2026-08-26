<?php

declare(strict_types=1);

namespace LoopPopupBridge\Support;

if (!defined('ABSPATH')) exit;

/**
 * Resolves which custom meta keys a popup's bindings need, from its saved data.
 *
 * Why this exists at all: the frontend can only discover a popup's bindings by
 * scanning the popup's DOM, and Elementor Pro removes the popup document from the
 * page on init, keeping it as an HTML string until the first open. So before a
 * popup has ever been opened its meta keys are unknowable client-side — which is
 * why preloading used to fetch every post with an empty custom_meta and fill it in
 * only after a click. Reading the saved Elementor data server-side is the only
 * place the answer exists ahead of that first open.
 *
 * Two blind spots are accepted by design, because both degrade to the old
 * behaviour rather than breaking: content owned by another document that is not a
 * plain Template widget (global widgets, shortcode-rendered templates), and keys
 * injected at runtime by a third party. The popup-show fill path still runs and
 * still reads the real DOM, so anything missed here is populated a moment later
 * exactly as it was before. The lpb_popup_meta_keys filter is the deliberate
 * escape hatch for the cases worth preloading anyway.
 */
final class PopupBindingScanner
{
    /**
     * Single transient holding every scanned popup, rather than one key per popup.
     *
     * @var string
     */
    private const TRANSIENT = 'lpb_popup_meta_keys';

    /**
     * Recursion ceiling for the element walk, shared by the nested-template hop.
     *
     * @var int
     */
    private const MAX_DEPTH = 32;

    /**
     * The dynamic tags whose saved settings carry an LPB binding.
     *
     * Gating on these names keeps the scan from decoding — and mis-resolving —
     * every unrelated dynamic tag in the popup.
     *
     * @var string[]
     */
    private const TAG_NAMES = [
        'lpb-clicked-post-field',
        'lpb-clicked-post-url',
        'lpb-clicked-post-image',
        'lpb-clicked-post-form-value',
        'lpb-clicked-post-form-select',
        'lpb-clicked-post-form-radio',
    ];

    /**
     * Per-request memo, keyed by popup ID. A page with a dozen loop items all
     * pointing at one popup resolves it once.
     *
     * @var array<int, array{fields: string[], meta_keys: string[]}>
     */
    private static array $cache = [];

    /**
     * The decoded transient, or null before the first read this request.
     *
     * @var array<int, array{stamp: string, bindings: array{fields: string[], meta_keys: string[]}}>|null
     */
    private static ?array $stored = null;

    /**
     * True once something was written to self::$stored and needs flushing.
     */
    private static bool $stored_dirty = false;

    /**
     * Returns the custom meta keys the given popup's bindings read.
     *
     * @return string[] Sanitised, unique meta keys. Empty when $popup_id is not a popup.
     */
    public static function get_meta_keys(int $popup_id): array
    {
        return self::get_bindings($popup_id)['meta_keys'];
    }

    /**
     * Returns everything the given popup's bindings read, in two sets:
     *   fields    — base post fields (title, content, permalink, …)
     *   meta_keys — custom/ACF meta keys
     *
     * The meta keys are deliberately NOT intersected with the allowlist. A key that
     * is requested but not allowlisted comes back with an empty value, and the
     * frontend treats that as "resolved and empty" — which renders the binding's
     * configured fallback. Filtering it out here instead would leave the binding
     * permanently "unknown", so every popup open would refetch it and never fill.
     * PostPayload enforces the allowlist on the values, which is the gate that
     * actually matters.
     *
     * The fields set exists so the page can skip building `content` for the popups
     * that never bind it — the one field expensive enough to be worth asking about.
     *
     * @return array{fields: string[], meta_keys: string[]}
     */
    public static function get_bindings(int $popup_id): array
    {
        if ($popup_id <= 0) {
            return ['fields' => [], 'meta_keys' => []];
        }

        if (isset(self::$cache[$popup_id])) {
            return self::$cache[$popup_id];
        }

        // Guards the REST popup_id parameter: without this, any post ID would be a
        // request to walk that post's Elementor data.
        if (!self::is_popup($popup_id)) {
            return self::$cache[$popup_id] = ['fields' => [], 'meta_keys' => []];
        }

        $stamp  = self::get_stamp($popup_id);
        $stored = self::read_store();

        if (isset($stored[$popup_id]) && $stored[$popup_id]['stamp'] === $stamp) {
            return self::$cache[$popup_id] = $stored[$popup_id]['bindings'];
        }

        $visited = [$popup_id => true];
        $found   = ['fields' => [], 'meta_keys' => []];

        self::walk(self::load_elements($popup_id), $found, $visited, 0);

        $keys   = self::clean($found['meta_keys']);
        $fields = self::clean($found['fields']);

        /**
         * Filters the meta keys preloaded for a popup.
         *
         * The scan cannot see bindings that live in another document (global
         * widgets, shortcode-rendered templates). Add those keys here to have them
         * preloaded rather than fetched on the first open.
         *
         * @param string[] $keys     Keys resolved from the popup's saved data.
         * @param int      $popup_id The popup being scanned.
         */
        $keys = self::clean((array) apply_filters('lpb_popup_meta_keys', $keys, $popup_id));

        /**
         * Filters the base post fields preloaded for a popup.
         *
         * @param string[] $fields   Base field names resolved from the popup's saved data.
         * @param int      $popup_id The popup being scanned.
         */
        $fields = self::clean((array) apply_filters('lpb_popup_fields', $fields, $popup_id));

        $bindings = ['fields' => $fields, 'meta_keys' => $keys];

        self::write_store($popup_id, $stamp, $bindings);

        return self::$cache[$popup_id] = $bindings;
    }

    /**
     * sanitize_key + drop empties + dedupe, reindexed.
     *
     * @param  array<int, mixed> $values
     * @return string[]
     */
    private static function clean(array $values): array
    {
        return array_values(array_unique(array_filter(array_map('sanitize_key', $values))));
    }

    // ── Scanning ──────────────────────────────────────────────────────────────────

    /**
     * Walks a list of Elementor element nodes, collecting bindings as it goes.
     *
     * @param mixed                                          $nodes
     * @param array{fields: string[], meta_keys: string[]}    $found   Accumulator.
     * @param array<int, true>                               $visited Document IDs already walked; stops template cycles.
     */
    private static function walk(mixed $nodes, array &$found, array &$visited, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || !is_array($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $settings = isset($node['settings']) && is_array($node['settings']) ? $node['settings'] : [];

            if (!empty($settings)) {
                self::collect_dynamic_tag_bindings($settings, $found);
                self::collect_literal_marker_bindings($settings, $found, 0);
                self::follow_nested_template($node, $settings, $found, $visited, $depth);
            }

            if (isset($node['elements'])) {
                self::walk($node['elements'], $found, $visited, $depth + 1);
            }
        }
    }

    /**
     * Files a resolved binding into the right set.
     *
     * @param array{field: string, meta_key: string}      $binding
     * @param array{fields: string[], meta_keys: string[]} $found
     */
    private static function record(array $binding, array &$found): void
    {
        if ('meta' === $binding['field']) {
            if ('' !== $binding['meta_key']) {
                $found['meta_keys'][] = $binding['meta_key'];
            }
            return;
        }

        if ('' !== $binding['field']) {
            $found['fields'][] = $binding['field'];
        }
    }

    /**
     * Collects keys from the dynamic tags Elementor saved on this element.
     *
     * Elementor stores a dynamic tag as a control value under __dynamic__, shaped
     * like:
     *
     *   [elementor-tag id="a1b2c3" name="lpb-clicked-post-field" settings="%7B%22field%22%3A%22meta%3Aevent_date%22%7D"]
     *
     * The inner settings are url-encoded JSON, so the meta key never appears as a
     * plain literal in the saved data — decoding is the only way to see it. Once
     * decoded, resolve_selection() is the same call the tag itself makes when it
     * renders, which is what keeps the two in step (including the "custom" +
     * custom_key indirection, where the key lives in a sibling control).
     *
     * @param array<string, mixed>                        $settings
     * @param array{fields: string[], meta_keys: string[]} $found Accumulator.
     */
    private static function collect_dynamic_tag_bindings(array $settings, array &$found): void
    {
        $dynamic = $settings['__dynamic__'] ?? null;

        if (!is_array($dynamic)) {
            return;
        }

        foreach ($dynamic as $value) {
            if (!is_string($value) || '' === $value) {
                continue;
            }

            if (!preg_match('/name="([a-z0-9\-]+)"/i', $value, $name_match)) {
                continue;
            }

            if (!in_array($name_match[1], self::TAG_NAMES, true)) {
                continue;
            }

            if (!preg_match('/settings="([^"]*)"/', $value, $settings_match)) {
                continue;
            }

            $config = json_decode(urldecode($settings_match[1]), true);

            if (!is_array($config)) {
                continue;
            }

            $binding = FieldRegistry::resolve_selection(
                (string) ($config['field'] ?? ''),
                (string) ($config['custom_key'] ?? '')
            );

            if (null !== $binding) {
                self::record($binding, $found);
            }
        }
    }

    /**
     * Collects keys from markers typed in by hand rather than placed as a tag.
     *
     * Recurses every string in the settings tree because these can land almost
     * anywhere an author can type: Advanced → Custom Attributes, an HTML or
     * Shortcode widget's markup, a Form widget's Options textarea, an atomic
     * widget's _attributes. Unlike the dynamic-tag path these are stored verbatim,
     * so a pattern match is both sufficient and necessary.
     *
     * @param array{fields: string[], meta_keys: string[]} $found Accumulator.
     */
    private static function collect_literal_marker_bindings(mixed $value, array &$found, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::collect_literal_marker_bindings($item, $found, $depth + 1);
            }
            return;
        }

        if (!is_string($value) || false === strpos($value, 'lpb-')) {
            return;
        }

        // ── Meta keys ─────────────────────────────────────────────────────────────

        // data-lpb-field="meta" data-lpb-meta-key="key"
        if (preg_match_all('/data-lpb-meta-key\s*=\s*["\']?([A-Za-z0-9_\-]+)/', $value, $matches)) {
            $found['meta_keys'] = array_merge($found['meta_keys'], $matches[1]);
        }

        // The <a href> hash marker and the <img src> query marker; values are
        // rawurlencode()d by FieldRegistry when written.
        if (preg_match_all('/[?&#]lpb-meta-key=([A-Za-z0-9_%\-]+)/', $value, $matches)) {
            foreach ($matches[1] as $encoded) {
                $found['meta_keys'][] = rawurldecode($encoded);
            }
        }

        // Form markers: lpb-bind:meta:key, lpb-bind-select:meta:key, lpb-bind-radio:meta:key
        if (preg_match_all('/lpb-bind(?:-select|-radio)?:meta:([A-Za-z0-9_\-]+)/', $value, $matches)) {
            $found['meta_keys'] = array_merge($found['meta_keys'], $matches[1]);
        }

        // ── Base fields ───────────────────────────────────────────────────────────
        // The same three marker shapes, in the branch where the selection is a post
        // field rather than "meta". `meta` itself is filtered out below because it
        // is a discriminator, not a field name — its key was collected above.

        if (preg_match_all('/data-lpb-field\s*=\s*["\']?([A-Za-z0-9_\-]+)/', $value, $matches)) {
            $found['fields'] = array_merge($found['fields'], $matches[1]);
        }

        if (preg_match_all('/[?&#]lpb-field=([A-Za-z0-9_%\-]+)/', $value, $matches)) {
            foreach ($matches[1] as $encoded) {
                $found['fields'][] = rawurldecode($encoded);
            }
        }

        // Negative lookahead on "meta:" so lpb-bind:meta:key does not also register
        // a field literally named "meta".
        if (preg_match_all('/lpb-bind(?:-select|-radio)?:(?!meta:)([A-Za-z0-9_\-]+)/', $value, $matches)) {
            $found['fields'] = array_merge($found['fields'], $matches[1]);
        }

        $found['fields'] = array_values(array_filter(
            $found['fields'],
            static fn(string $field): bool => 'meta' !== $field
        ));
    }

    /**
     * Follows a Template widget into the document it embeds.
     *
     * @param array<string, mixed>                        $node
     * @param array<string, mixed>                        $settings
     * @param array{fields: string[], meta_keys: string[]} $found   Accumulator.
     * @param array<int, true>                            $visited Guards against a template including itself.
     */
    private static function follow_nested_template(
        array $node,
        array $settings,
        array &$found,
        array &$visited,
        int $depth
    ): void {
        if ('template' !== (string) ($node['widgetType'] ?? '')) {
            return;
        }

        $template_id = absint($settings['template_id'] ?? 0);

        if ($template_id <= 0 || isset($visited[$template_id])) {
            return;
        }

        $visited[$template_id] = true;

        self::walk(self::load_elements($template_id), $found, $visited, $depth + 1);
    }

    /**
     * Reads and decodes a document's saved Elementor tree.
     *
     * Elementor stores this as a JSON string, but tolerate an already-decoded
     * array in case a filter got there first.
     *
     * @return array<int, mixed>
     */
    private static function load_elements(int $post_id): array
    {
        $raw = get_post_meta($post_id, '_elementor_data', true);

        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    // ── Identity and caching ──────────────────────────────────────────────────────

    /**
     * True when the ID belongs to an Elementor popup template.
     */
    private static function is_popup(int $post_id): bool
    {
        if ('elementor_library' !== get_post_type($post_id)) {
            return false;
        }

        return 'popup' === (string) get_post_meta($post_id, '_elementor_template_type', true);
    }

    /**
     * Cache stamp for a popup: changes whenever the popup is re-saved, and whenever
     * the plugin is updated (so a smarter scanner invalidates last version's answers).
     */
    private static function get_stamp(int $popup_id): string
    {
        return (string) get_post_field('post_modified_gmt', $popup_id) . '|' . LPB_VERSION;
    }

    /**
     * @return array<int, array{stamp: string, bindings: array{fields: string[], meta_keys: string[]}}>
     */
    private static function read_store(): array
    {
        if (null === self::$stored) {
            $stored = get_transient(self::TRANSIENT);

            self::$stored = is_array($stored) ? $stored : [];
        }

        return self::$stored;
    }

    /**
     * Records a scan result, flushing to the transient at the end of the request.
     *
     * Deferring the write means a page rendering a dozen popups performs one
     * transient write instead of a dozen.
     *
     * @param array{fields: string[], meta_keys: string[]} $bindings
     */
    private static function write_store(int $popup_id, string $stamp, array $bindings): void
    {
        self::read_store();

        self::$stored[$popup_id] = ['stamp' => $stamp, 'bindings' => $bindings];

        if (!self::$stored_dirty) {
            self::$stored_dirty = true;
            add_action('shutdown', [self::class, 'flush_store']);
        }
    }

    /**
     * Writes the accumulated scan results. Hooked on shutdown by write_store().
     *
     * @internal
     */
    public static function flush_store(): void
    {
        if (!self::$stored_dirty || null === self::$stored) {
            return;
        }

        self::$stored_dirty = false;

        set_transient(self::TRANSIENT, self::$stored, WEEK_IN_SECONDS);
    }

    private function __construct()
    {
    }
}
