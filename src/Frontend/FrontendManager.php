<?php

declare(strict_types=1);

namespace LoopPopupBridge\Frontend;

if (!defined('ABSPATH')) exit;

use Elementor\Element_Base;
use Elementor\Widget_Base;
use LoopPopupBridge\Support\PopupBindingScanner;
use LoopPopupBridge\Support\PostPayload;

/**
 * Handles all frontend responsibilities for the Loop Popup Bridge plugin.
 *
 * Responsibilities:
 *  1. Adds data-lpb-* attributes to the outer wrapper of any widget whose
 *     "Enable Loop Popup Trigger" control is turned on.
 *  2. Enqueues the JavaScript bundle and injects the global context object
 *     (window.LoopPopupBridge) with the REST URL and nonce.
 *  3. Renders the full data payload for every trigger on the page inline, so the
 *     frontend never has to fetch anything before the first click.
 *
 * The data-lpb-post-id attribute is populated from get_the_ID() at render
 * time. Inside an Elementor Loop Grid this correctly returns the post ID of
 * the current iteration, not the page's main post.
 */
final class FrontendManager
{
    /**
     * LIFO stack of LPB settings for atomic widgets whose output is being buffered.
     *
     * Elementor renders widgets sequentially (never concurrently), so a plain stack is
     * sufficient even when multiple LPB-enabled atomic widgets appear in the same loop
     * item. Each entry is pushed in the before_render action and popped in after_render.
     *
     * @var array<int, array{popup_id: int, post_id: int}>
     */
    private array $atomic_capture_stack = [];

    /**
     * Every (post, popup) pairing a trigger on this page established.
     *
     * Keyed post ID → set of popup IDs, because the same post can be triggered
     * against more than one popup on a page and the payload has to satisfy all of
     * them: LPB.posts is one cache entry per post, shared by every popup.
     *
     * @var array<int, array<int, true>>
     */
    private array $preload_plan = [];

    /**
     * Registers the Elementor render hooks and the script enqueue hook.
     */
    public function __construct()
    {
        // Fires just before any element renders.
        // – Legacy widgets:  add_render_attribute() on the _wrapper div.
        // – Atomic widgets:  start an output buffer so we can wrap the Twig output.
        add_action('elementor/frontend/before_render', [$this, 'add_trigger_attributes']);

        // Atomic widgets suppress before_render()/after_render() on Widget_Base, so their
        // Twig output is never wrapped in a <div _wrapper>.  We close the buffer here,
        // inject the data-lpb-* wrapper div, and echo the result.
        add_action('elementor/frontend/widget/after_render', [$this, 'close_atomic_capture']);

        // Fires only on pages where Elementor has output — no need to check is_admin().
        add_action('elementor/frontend/after_enqueue_scripts', [$this, 'enqueue_assets']);

        // Late enough that every trigger on the page has rendered and registered its
        // (post, popup) pairing. Still early enough to matter: the bundle defers all
        // of its work to DOMContentLoaded, so a script printed after it in the footer
        // is guaranteed to run first.
        add_action('wp_footer', [$this, 'print_preload_payload'], 999);
    }

    /**
     * Dispatches to the correct trigger-attribute strategy for this element.
     *
     * – Legacy widgets: writes data-lpb-* directly on the _wrapper div via
     *   add_render_attribute(), which Widget_Base::before_render() then prints.
     * – Atomic widgets: Widget_Base::before_render() is empty (Twig handles the full
     *   render), so add_render_attribute() is a no-op.  Instead, start an output
     *   buffer here; close_atomic_capture() wraps the buffered content later.
     *
     * @param  Element_Base $element  The element about to be rendered.
     * @return void
     */
    public function add_trigger_attributes(Element_Base $element): void
    {
        if (!$element instanceof Widget_Base) {
            return;
        }

        // Atomic widgets expose get_atomic_setting(); legacy widgets do not.
        if (method_exists($element, 'get_atomic_setting')) {
            $this->start_atomic_capture($element);
            return;
        }

        // ── Legacy widget path ────────────────────────────────────────────────────
        $settings = $element->get_settings_for_display();

        if (empty($settings['lpb_enable_trigger']) || 'yes' !== $settings['lpb_enable_trigger']) {
            return;
        }

        $popup_id = absint($settings['lpb_popup_id'] ?? 0);
        if (0 === $popup_id) {
            return;
        }

        $post_id = (int) get_the_ID();

        $element->add_render_attribute('_wrapper', [
            'data-lpb-trigger'  => '1',
            'data-lpb-post-id'  => (string) $post_id,
            'data-lpb-popup-id' => (string) $popup_id,
            'class'             => 'lpb-trigger',
        ]);

        $this->record_preload($post_id, $popup_id);
    }

    /**
     * Opens an output buffer for an atomic widget with LPB enabled.
     *
     * Elementor's render pipeline calls ob_start()/ob_get_clean() internally to
     * capture the Twig template output, then echoes the result.  Our ob_start() runs
     * before Elementor's, so it captures that final echo.  close_atomic_capture()
     * retrieves the buffer and wraps it in a <div data-lpb-*>.
     *
     * @param  Widget_Base $element  An atomic widget instance.
     * @return void
     */
    private function start_atomic_capture(Widget_Base $element): void
    {
        $enabled = $element->get_atomic_setting('lpb_enable_trigger');
        if (true !== $enabled) {
            return;
        }

        $popup_id = absint($element->get_atomic_setting('lpb_popup_id') ?? 0);
        if (0 === $popup_id) {
            return;
        }

        $post_id = (int) get_the_ID();

        $this->atomic_capture_stack[] = [
            'popup_id' => $popup_id,
            'post_id'  => $post_id,
        ];

        $this->record_preload($post_id, $popup_id);

        ob_start();
    }

    /**
     * Closes the output buffer started by start_atomic_capture() and wraps the
     * captured HTML in a <div data-lpb-trigger="1" …>.
     *
     * The JS click handler uses event.target.closest('[data-lpb-trigger="1"]'), so
     * nesting the Twig output inside this div works just like the _wrapper div that
     * legacy Widget_Base::before_render() produces.
     *
     * Fires on elementor/frontend/widget/after_render for EVERY widget; returns
     * immediately unless this widget started a capture.
     *
     * @param  Element_Base $element  The widget that just finished rendering.
     * @return void
     */
    public function close_atomic_capture(Element_Base $element): void
    {
        if (empty($this->atomic_capture_stack) || !method_exists($element, 'get_atomic_setting')) {
            return;
        }

        // Only pop when this widget is actually the one that opened the buffer.
        $enabled  = $element->get_atomic_setting('lpb_enable_trigger');
        $popup_id = absint($element->get_atomic_setting('lpb_popup_id') ?? 0);

        if (true !== $enabled || 0 === $popup_id) {
            return;
        }

        $data    = array_pop($this->atomic_capture_stack);
        $content = ob_get_clean();

        if (empty($content)) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div data-lpb-trigger="1"'
            . ' data-lpb-post-id="'  . esc_attr((string) $data['post_id'])  . '"'
            . ' data-lpb-popup-id="' . esc_attr((string) $data['popup_id']) . '"'
            . ' class="lpb-trigger"'
            . '>' . $content . '</div>';
    }

    // ── Preload payload ───────────────────────────────────────────────────────────

    /**
     * Notes that a trigger on this page pairs a post with a popup.
     *
     * @return void
     */
    private function record_preload(int $post_id, int $popup_id): void
    {
        if ($post_id <= 0 || $popup_id <= 0) {
            return;
        }

        $this->preload_plan[$post_id][$popup_id] = true;
    }

    /**
     * Renders the complete data payload for every trigger on the page.
     *
     * This is what makes the preload real. The meta keys a popup binds can only be
     * read from the popup's own DOM, and Elementor Pro keeps that DOM out of the
     * page until the popup's first open — so the frontend could never work out what
     * to ask for ahead of a click, and preloading returned an empty custom_meta
     * every time. Here the keys come from the popup's saved data instead, and the
     * values come with them, so LPB.posts is complete before any script runs and
     * the first click needs no network at all.
     *
     * One entry per post, carrying the union of the keys of every popup that post
     * triggers. Filling only ever writes bindings that exist in the popup being
     * filled, so a payload wider than one popup needs cannot leak into it.
     *
     * @return void
     */
    public function print_preload_payload(): void
    {
        if (empty($this->preload_plan)) {
            return;
        }

        $posts           = [];
        $post_meta_keys  = [];
        $popup_meta_keys = [];

        foreach ($this->preload_plan as $post_id => $popup_ids) {
            $keys = [];

            foreach (array_keys($popup_ids) as $popup_id) {
                $popup_keys = PopupBindingScanner::get_meta_keys($popup_id);

                $popup_meta_keys[$popup_id] = $popup_keys;
                $keys                       = array_merge($keys, $popup_keys);
            }

            $payload = PostPayload::build($post_id, $keys);

            // Unpublished, password-protected or non-public: emit nothing rather
            // than a partial entry. A cached partial would never be refetched,
            // because the cache is only ever widened, never invalidated.
            if ($payload instanceof \WP_Error) {
                continue;
            }

            $posts[$post_id] = $payload;

            $flags = [];
            foreach ($payload['meta_keys_loaded'] as $key) {
                $flags[$key] = true;
            }
            $post_meta_keys[$post_id] = (object) $flags;
        }

        if (empty($posts) && empty($popup_meta_keys)) {
            return;
        }

        // Object.assign rather than assignment: the shell object is already on the
        // page, and anything a third party put in these maps stays put.
        wp_print_inline_script_tag(
            sprintf(
                'window.LoopPopupBridge = window.LoopPopupBridge || {};'
                . 'window.LoopPopupBridge.posts = Object.assign(window.LoopPopupBridge.posts || {}, %s);'
                . 'window.LoopPopupBridge.postMetaKeys = Object.assign(window.LoopPopupBridge.postMetaKeys || {}, %s);'
                . 'window.LoopPopupBridge.popupMetaKeys = Object.assign(window.LoopPopupBridge.popupMetaKeys || {}, %s);',
                self::encode_for_inline_script($posts),
                self::encode_for_inline_script($post_meta_keys),
                self::encode_for_inline_script($popup_meta_keys)
            )
        );
    }

    /**
     * JSON-encodes a post-ID-keyed map for embedding inside a <script> element.
     *
     * Two details matter and neither is cosmetic:
     *
     *  - The top level is cast to an object so PHP emits `{"512": …}` rather than a
     *    JSON array, and the cast is applied ONLY at the top level: array-valued
     *    meta (an ACF repeater, a multi-select) has to stay a JS array, which is
     *    why JSON_FORCE_OBJECT is wrong here.
     *  - JSON_HEX_TAG escapes < and > so a post whose content contains a literal
     *    "</script>" cannot terminate the tag it is embedded in. Slash escaping is
     *    deliberately left on for the same reason. Both decode back to the original
     *    characters in JS, so the payload the frontend sees is unchanged.
     *
     * @param array<int, mixed> $map
     */
    private static function encode_for_inline_script(array $map): string
    {
        return (string) wp_json_encode((object) $map, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Enqueues the frontend script and registers a minimal inline stylesheet.
     *
     * The script handle "loop-popup-bridge" depends on "elementor-frontend" so
     * it is guaranteed to execute after Elementor's own JS is parsed and
     * window.elementorFrontend is available.
     *
     * An inline script injected before the bundle initialises the global
     * window.LoopPopupBridge context object with:
     *   activePostId   — null until a trigger is clicked
     *   activePopupId  — null until a trigger is clicked
     *   posts          — cache keyed by post ID: { [postId]: postData }
     *   postMetaKeys   — cache index of custom meta keys already loaded per post
     *   popupMetaKeys  — meta keys each popup binds, resolved from its saved data:
     *                    { [popupId]: [key, …] }. This is what lets the frontend
     *                    know what a popup needs before that popup has ever been
     *                    opened, which is impossible to read from the DOM.
     *   restUrl        — base URL of the custom REST endpoint
     *   nonce          — wp_rest nonce for authenticated REST requests
     *
     * print_preload_payload() fills posts, postMetaKeys and popupMetaKeys in the
     * footer; this shell only guarantees they exist.
     *
     * @return void
     */
    public function enqueue_assets(): void
    {
        // Minimal cursor rule — avoids shipping a separate CSS file.
        wp_register_style('loop-popup-bridge', false, [], LPB_VERSION);
        wp_enqueue_style('loop-popup-bridge');
        wp_add_inline_style('loop-popup-bridge', '.lpb-trigger { cursor: pointer; }');

        wp_enqueue_script(
            'loop-popup-bridge',
            LPB_URL . 'assets/js/loop-popup-bridge.js',
            ['elementor-frontend'],
            LPB_VERSION,
            true
        );

        wp_add_inline_script(
            'loop-popup-bridge',
            sprintf(
                'window.LoopPopupBridge = window.LoopPopupBridge || { activePostId: null, activePopupId: null, posts: {}, postMetaKeys: {}, popupMetaKeys: {}, restUrl: %s, nonce: %s };',
                wp_json_encode(rest_url('loop-popup-bridge/v1/post/')),
                wp_json_encode(wp_create_nonce('wp_rest'))
            ),
            'before'
        );
    }
}
