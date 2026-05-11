<?php

declare(strict_types=1);

namespace LoopPopupBridge\Controls;

if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Element_Base;
use Elementor\Widget_Base;

/**
 * Injects a "Loop Popup Bridge" section into the Advanced tab of every Elementor widget.
 *
 * Hook strategy — dual anchor for cross-version compatibility:
 *
 *   Elementor 4.x:    the first Advanced-tab section in common-base.php is "_section_style"
 *     →  elementor/element/common/_section_style/after_section_end
 *
 *   Elementor ≤ 3.x:  the section ID was "_section_layout" in the older common.php
 *     →  elementor/element/common/_section_layout/after_section_end
 *
 *   Both hooks are registered. The $registered map ensures the controls section is
 *   added only once per widget type even if both hooks fire for the same widget.
 *
 * Popup selector:
 *   The popup ID control uses SELECT2 populated with all published Elementor Pro popup
 *   templates at editor-load time. Results are cached in a static property so the
 *   get_posts() call runs at most once per request regardless of how many widget types
 *   the editor registers.
 *
 * Loop-item restriction:
 *   The section is hidden via injected CSS whenever the editor is not editing a template
 *   whose _elementor_template_type meta is "loop-item". This keeps the panel clean —
 *   editors only see the Loop Popup Bridge controls in the one context where they work.
 */
final class WidgetControlsManager
{
    /**
     * Tracks widget type names that have already received the LPB controls section.
     *
     * Prevents duplicate sections if both anchor hooks fire for the same widget type.
     *
     * @var array<string, true>
     */
    private array $registered = [];

    /**
     * Cached SELECT2 options array for the popup picker.
     *
     * Populated once on first call to get_popup_options(); null means not yet fetched.
     *
     * @var array<string, string>|null
     */
    private static ?array $popup_options_cache = null;

    /**
     * Registers all hooks: controls injection (dual version) and the editor CSS guard.
     */
    public function __construct()
    {
        // Elementor 4.x anchor section.
        add_action(
            'elementor/element/common/_section_style/after_section_end',
            [$this, 'register_controls'],
            10,
            2
        );

        // Elementor ≤ 3.x anchor section — no-op on 4.x where it no longer exists.
        add_action(
            'elementor/element/common/_section_layout/after_section_end',
            [$this, 'register_controls'],
            10,
            2
        );

        // Hide the LPB panel section when the editor is not editing a Loop Item template.
        add_action('elementor/editor/before_enqueue_scripts', [$this, 'maybe_hide_section_in_editor']);
    }

    /**
     * Adds the Loop Popup Bridge controls section to a widget's Advanced tab.
     *
     * Fires once per widget type during the editor's control-registration pass.
     * Guards against non-widget elements (sections, columns, containers) and against
     * being called a second time for the same widget type when both anchor hooks fire.
     *
     * Controls registered:
     *   lpb_enable_trigger — master switcher that gates the remaining controls
     *   lpb_popup_id       — SELECT2 searchable picker populated with published popups
     *   lpb_preload_data   — optional flag to pre-fetch post data on page load
     *
     * @param  Element_Base $element  The widget instance currently being registered.
     * @param  array        $_args    Section arguments passed by Elementor (not used).
     * @return void
     */
    public function register_controls(Element_Base $element, array $_args): void
    {
        if (!$element instanceof Widget_Base) {
            return;
        }

        // Prevent duplicate sections if both anchor hooks fire for the same widget type.
        $widget_name = $element->get_name();
        if (isset($this->registered[$widget_name])) {
            return;
        }
        $this->registered[$widget_name] = true;

        $element->start_controls_section(
            'lpb_section',
            [
                'label' => esc_html__('Loop Popup Bridge', 'loop-popup-bridge'),
                'tab'   => Controls_Manager::TAB_ADVANCED,
            ]
        );

        // ── Master on/off toggle ──────────────────────────────────────────────
        $element->add_control(
            'lpb_enable_trigger',
            [
                'label'        => esc_html__('Enable Loop Popup Trigger', 'loop-popup-bridge'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'loop-popup-bridge'),
                'label_off'    => esc_html__('No', 'loop-popup-bridge'),
                'return_value' => 'yes',
                'default'      => '',
                'description'  => esc_html__('Clicking this widget opens the selected popup and populates it with data from the current loop post.', 'loop-popup-bridge'),
            ]
        );

        // ── Popup picker (SELECT2 searchable by name) ─────────────────────────
        $element->add_control(
            'lpb_popup_id',
            [
                'label'       => esc_html__('Popup', 'loop-popup-bridge'),
                'type'        => Controls_Manager::SELECT2,
                'options'     => $this->get_popup_options(),
                'label_block' => true,
                'description' => esc_html__('Start typing to search your Elementor Pro popups by name.', 'loop-popup-bridge'),
                'condition'   => ['lpb_enable_trigger' => 'yes'],
            ]
        );

        // ── Preload option ────────────────────────────────────────────────────
        $element->add_control(
            'lpb_preload_data',
            [
                'label'        => esc_html__('Preload Post Data', 'loop-popup-bridge'),
                'type'         => Controls_Manager::SWITCHER,
                'label_on'     => esc_html__('Yes', 'loop-popup-bridge'),
                'label_off'    => esc_html__('No', 'loop-popup-bridge'),
                'return_value' => 'yes',
                'default'      => '',
                'description'  => esc_html__("Marks the element so JavaScript pre-fetches this post's data on page load rather than waiting for the first click.", 'loop-popup-bridge'),
                'condition'    => ['lpb_enable_trigger' => 'yes'],
            ]
        );

        $element->end_controls_section();
    }

    /**
     * Injects CSS into the Elementor editor to hide the "Loop Popup Bridge" panel
     * section when the editor is not editing a Loop Item template.
     *
     * The check reads the _elementor_template_type post meta from the post currently
     * open in the editor (passed as ?post= in the query string). Any template type
     * that is not "loop-item" causes the section and all its child controls to be
     * hidden via display:none, keeping the Advanced tab clean for editors.
     *
     * This is a CSS-only approach — the controls are still registered so that
     * existing saved values on loop-item widgets are preserved. Only the panel UI
     * is hidden in non-loop-item contexts.
     *
     * @return void
     */
    public function maybe_hide_section_in_editor(): void
    {
        $post_id       = absint($_GET['post'] ?? 0);
        $template_type = (string) get_post_meta($post_id, '_elementor_template_type', true);

        // Accepted loop-item type variants across Elementor Pro versions.
        $loop_item_types = ['loop-item', 'loop_item'];

        if (in_array($template_type, $loop_item_types, true)) {
            return; // Correct context — allow the section to show normally.
        }

        // Not a loop-item template: hide the section toggle and all child controls.
        wp_register_style('lpb-editor', false, ['elementor-editor'], LPB_VERSION);
        wp_enqueue_style('lpb-editor');
        wp_add_inline_style(
            'lpb-editor',
            '.elementor-control-lpb_section,
             .elementor-control-lpb_enable_trigger,
             .elementor-control-lpb_popup_id,
             .elementor-control-lpb_preload_data { display: none !important; }'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────────

    /**
     * Returns SELECT2-compatible options for all published Elementor Pro popup templates.
     *
     * Results are cached in the static $popup_options_cache property so the
     * get_posts() database query runs at most once per PHP request, regardless of how
     * many widget types are processed by the editor's control-registration pass.
     *
     * Skips the query entirely in non-editorial contexts (pure frontend renders) to
     * avoid unnecessary DB overhead on public page loads.
     *
     * The stored SELECT2 value is the popup post ID as a string. absint() in
     * FrontendManager converts it back to an integer before writing data attributes.
     *
     * @return array<string, string>  Map of popup post ID (string key) to display label.
     */
    private function get_popup_options(): array
    {
        if (null !== self::$popup_options_cache) {
            return self::$popup_options_cache;
        }

        // Guard: skip the DB query on pure frontend renders where options aren't shown.
        $is_editorial = is_admin()
            || wp_doing_ajax()
            || (defined('REST_REQUEST') && REST_REQUEST);

        if (!$is_editorial) {
            return self::$popup_options_cache = [];
        }

        $popups = get_posts([
            'post_type'      => 'elementor_library',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'meta_query'     => [
                [
                    'key'   => '_elementor_template_type',
                    'value' => 'popup',
                ],
            ],
        ]);

        $options = [];
        foreach ($popups as $popup) {
            // Label includes the ID so editors can locate the popup in Elementor's library.
            $options[(string) $popup->ID] = esc_html(
                sprintf('%s  (ID: %d)', $popup->post_title, $popup->ID)
            );
        }

        return self::$popup_options_cache = $options;
    }
}
