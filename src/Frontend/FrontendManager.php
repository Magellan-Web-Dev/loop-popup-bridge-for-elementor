<?php

declare(strict_types=1);

namespace LoopPopupBridge\Frontend;

if (!defined('ABSPATH')) exit;

use Elementor\Element_Base;
use Elementor\Widget_Base;

/**
 * Handles all frontend responsibilities for the Loop Popup Bridge plugin.
 *
 * Responsibilities:
 *  1. Adds data-lpb-* attributes to the outer wrapper of any widget whose
 *     "Enable Loop Popup Trigger" control is turned on.
 *  2. Enqueues the JavaScript bundle and injects the global context object
 *     (window.LoopPopupBridge) with the REST URL and nonce.
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
     * @var array<int, array{popup_id: int, post_id: int, preload: bool}>
     */
    private array $atomic_capture_stack = [];

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

        $element->add_render_attribute('_wrapper', [
            'data-lpb-trigger'  => '1',
            'data-lpb-post-id'  => (string) (int) get_the_ID(),
            'data-lpb-popup-id' => (string) $popup_id,
            'class'             => 'lpb-trigger',
        ]);

        if (!empty($settings['lpb_preload_data']) && 'yes' === $settings['lpb_preload_data']) {
            $element->add_render_attribute('_wrapper', 'data-lpb-preload', '1');
        }
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

        $this->atomic_capture_stack[] = [
            'popup_id' => $popup_id,
            'post_id'  => (int) get_the_ID(),
            'preload'  => true === $element->get_atomic_setting('lpb_preload_data'),
        ];

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

        $preload_attr = $data['preload'] ? ' data-lpb-preload="1"' : '';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '<div data-lpb-trigger="1"'
            . ' data-lpb-post-id="'  . esc_attr((string) $data['post_id'])  . '"'
            . ' data-lpb-popup-id="' . esc_attr((string) $data['popup_id']) . '"'
            . ' class="lpb-trigger"'
            . $preload_attr
            . '>' . $content . '</div>';
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
     *   activePostId  — null until a trigger is clicked
     *   activePopupId — null until a trigger is clicked
     *   posts         — client-side cache keyed by post ID: { [postId]: postData }
     *   postMetaKeys  — cache index of custom meta keys already loaded per post
     *   restUrl       — base URL of the custom REST endpoint
     *   nonce         — wp_rest nonce for authenticated REST requests
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
                'window.LoopPopupBridge = window.LoopPopupBridge || { activePostId: null, activePopupId: null, posts: {}, postMetaKeys: {}, restUrl: %s, nonce: %s };',
                wp_json_encode(rest_url('loop-popup-bridge/v1/post/')),
                wp_json_encode(wp_create_nonce('wp_rest'))
            ),
            'before'
        );
    }
}
