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
     * Registers the Elementor render hook and the script enqueue hook.
     */
    public function __construct()
    {
        // Fires just before any element renders — safe point to call
        // add_render_attribute() on the element's _wrapper.
        add_action('elementor/frontend/before_render', [$this, 'add_trigger_attributes']);

        // Fires only on pages where Elementor has output — no need to check is_admin().
        add_action('elementor/frontend/after_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    /**
     * Writes data-lpb-* attributes onto the widget wrapper when the trigger is enabled.
     *
     * Skips silently if the element is not a Widget_Base instance (e.g. a container
     * or section), if the trigger setting is off, or if no popup ID is configured.
     *
     * Attributes written:
     *   data-lpb-trigger  — always "1"; used as the JS click listener selector
     *   data-lpb-post-id  — current loop post ID from get_the_ID()
     *   data-lpb-popup-id — the Elementor Pro popup ID from the control value
     *   class             — appends "lpb-trigger" (targets the cursor CSS rule)
     *   data-lpb-preload  — "1" only when the Preload Post Data control is on
     *
     * @param  Element_Base $element  The element about to be rendered.
     * @return void
     */
    public function add_trigger_attributes(Element_Base $element): void
    {
        if (!$element instanceof Widget_Base) {
            return;
        }

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
