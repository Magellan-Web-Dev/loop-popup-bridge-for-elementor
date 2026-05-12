<?php

declare(strict_types=1);

namespace LoopPopupBridge\Controls;

if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * Injects a "Loop Popup Bridge" section into the Advanced tab of widgets that
 * belong to a Loop Item template.
 *
 * Hook strategy - shared Advanced-tab anchors plus a generic fallback:
 *
 *   Elementor 3.x/4.x widgets inherit their Advanced-tab controls from a common
 *   widget control stack. Depending on version/experiment state, that stack can
 *   be named "common", "common-base", or "common-optimized".
 *
 *   Modern section ID: "_section_style"
 *   Legacy section ID: "_section_layout"
 *
 *   All relevant named hooks are registered, plus Elementor's generic
 *   elementor/element/after_section_end hook. The $registered map ensures the
 *   controls section is added only once even if multiple compatible hooks fire.
 *
 * Loop-item restriction:
 *   The controls must be registered so Elementor has a schema for saved lpb_*
 *   widget settings. Editor CSS hides the section only when a small editor script
 *   can identify that Elementor's current document is not a Loop Item.
 *
 * Popup selector:
 *   The popup ID control uses SELECT2 populated with all published Elementor Pro
 *   popup templates. Results are cached in a static property so the get_posts()
 *   call runs at most once per request.
 */
final class WidgetControlsManager
{
    /**
     * Tracks widget type names that have already received the LPB controls section.
     *
     * Prevents duplicate sections if both anchor hooks fire for the same stack name.
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
     * Registers control-injection hooks and the editor visibility guard.
     */
    public function __construct()
    {
        // Legacy widgets: inject LPB directly into each widget's own controls stack on
        // its first section end. Using the individual widget (not the shared common stack)
        // ensures Elementor includes the section in get_stack(false)['controls'] — the
        // array the editor actually reads per widget type.
        add_action('elementor/element/after_section_end', [$this, 'register_controls_after_section'], 10, 3);
        add_action('elementor/editor/before_enqueue_scripts', [$this, 'enqueue_editor_visibility_styles']);
        add_action('elementor/editor/after_enqueue_scripts', [$this, 'enqueue_editor_visibility_script']);

        // Atomic widgets (e-image, e-heading, etc.) bypass the legacy common-stack hooks.
        // The two filters below inject LPB props into their schema (so settings survive save)
        // and append a Loop Popup Bridge section to their editor control panel.
        add_filter('elementor/atomic-widgets/props-schema', [$this, 'add_atomic_props_schema']);
        add_filter('elementor/atomic-widgets/controls',    [$this, 'add_atomic_controls'], 10, 2);
    }

    /**
     * Adds LPB controls to each individual legacy widget's own controls stack.
     *
     * Fires after every section end for every element. Skips:
     *  - The LPB section itself (prevents infinite recursion).
     *  - Non-Widget_Base elements (sections, containers, columns).
     *  - Widget_Common_Base instances (shared common stacks). LPB must live in
     *    each widget's OWN stack so Elementor includes it in get_stack(false),
     *    the array the editor reads per widget. Injecting only into Widget_Common
     *    means the section is NOT in each widget's own get_stack(false) result and
     *    may be silently omitted from the editor panel for certain widget types.
     *  - Any widget already processed (deduplication guard).
     *
     * @param  object $element     The Elementor control stack whose section just ended.
     * @param  string $section_id  Elementor section ID.
     * @param  array  $_args       Section arguments (unused).
     * @return void
     */
    public function register_controls_after_section(object $element, string $section_id, array $_args): void
    {
        // Prevent recursion — we fire end_controls_section() ourselves for lpb_section.
        if ('lpb_section' === $section_id) {
            return;
        }

        // Skip common-stack base classes. Their controls merge into widget stacks
        // separately; we inject LPB into each individual widget instead.
        if ($element instanceof \Elementor\Widget_Common_Base) {
            return;
        }

        $this->register_controls($element, []);
    }

    /**
     * Adds the Loop Popup Bridge controls section to the widget's Advanced tab.
     *
     * Returns immediately when:
     *   - The element is not a Widget_Base instance (sections, columns, containers).
     *   - This widget name has already been processed (deduplication guard).
     *
     * @param  object $element  The Elementor control stack to inject controls into.
     * @param  array  $_args    Unused.
     * @return void
     */
    public function register_controls(object $element, array $_args): void
    {
        if (!$element instanceof Widget_Base) {
            return;
        }

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

    // ── Atomic widget support ─────────────────────────────────────────────────────

    /**
     * Adds LPB props to every atomic widget's schema so the editor can save them.
     *
     * The filter receives the schema array without a widget-type argument, so the
     * props are added to ALL atomic widgets. They are nullable (not required) so
     * existing widgets that never touched LPB settings pass schema validation fine.
     *
     * @param  array $schema  The widget's prop-type schema.
     * @return array          Modified schema.
     */
    public function add_atomic_props_schema(array $schema): array
    {
        if (!class_exists('Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Boolean_Prop_Type')) {
            return $schema;
        }

        $schema['lpb_enable_trigger'] = \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Boolean_Prop_Type::make();
        $schema['lpb_popup_id']       = \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\String_Prop_Type::make();
        $schema['lpb_preload_data']   = \Elementor\Modules\AtomicWidgets\PropTypes\Primitives\Boolean_Prop_Type::make();

        return $schema;
    }

    /**
     * Appends the Loop Popup Bridge section to every atomic widget's control panel.
     *
     * Controls must bind to props that exist in the schema; those are added by
     * add_atomic_props_schema() above.
     *
     * @param  array  $controls  The controls array from define_atomic_controls().
     * @param  object $widget    The atomic widget instance.
     * @return array             Modified controls array.
     */
    public function add_atomic_controls(array $controls, object $widget): array
    {
        if (!class_exists('Elementor\Modules\AtomicWidgets\Controls\Section')) {
            return $controls;
        }

        $controls[] = \Elementor\Modules\AtomicWidgets\Controls\Section::make()
            ->set_id('lpb_section')
            ->set_label(esc_html__('Loop Popup Bridge', 'loop-popup-bridge'))
            ->set_items([
                \Elementor\Modules\AtomicWidgets\Controls\Types\Switch_Control::bind_to('lpb_enable_trigger')
                    ->set_label(esc_html__('Enable Loop Popup Trigger', 'loop-popup-bridge'))
                    ->set_description(esc_html__('Clicking this widget opens the selected popup and populates it with data from the current loop post.', 'loop-popup-bridge')),
                \Elementor\Modules\AtomicWidgets\Controls\Types\Select_Control::bind_to('lpb_popup_id')
                    ->set_label(esc_html__('Popup', 'loop-popup-bridge'))
                    ->set_options($this->get_atomic_popup_options())
                    ->set_placeholder(esc_html__('Select a popup…', 'loop-popup-bridge')),
                \Elementor\Modules\AtomicWidgets\Controls\Types\Switch_Control::bind_to('lpb_preload_data')
                    ->set_label(esc_html__('Preload Post Data', 'loop-popup-bridge'))
                    ->set_description(esc_html__('Marks the element so JavaScript pre-fetches this post\'s data on page load rather than waiting for the first click.', 'loop-popup-bridge')),
            ]);

        return $controls;
    }

    /**
     * Converts the legacy key→label popup options map into the atomic Select_Control
     * format: an array of ['value' => id_string, 'label' => display_label] entries.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function get_atomic_popup_options(): array
    {
        $options = [];
        foreach ($this->get_popup_options() as $id => $label) {
            $options[] = ['value' => (string) $id, 'label' => $label];
        }
        return $options;
    }

    // ── Private helpers ───────────────────────────────────────────────────────────

    /**
     * Adds editor CSS that hides LPB controls only while JS marks the editor non-loop.
     *
     * @return void
     */
    public function enqueue_editor_visibility_styles(): void
    {
        wp_register_style('lpb-editor', false, ['elementor-editor'], LPB_VERSION);
        wp_enqueue_style('lpb-editor');
        wp_add_inline_style(
            'lpb-editor',
            'body.lpb-hide-loop-popup-bridge .elementor-control-lpb_section,
             body.lpb-hide-loop-popup-bridge .elementor-control-lpb_enable_trigger,
             body.lpb-hide-loop-popup-bridge .elementor-control-lpb_popup_id,
             body.lpb-hide-loop-popup-bridge .elementor-control-lpb_preload_data,
             body.lpb-dynamic-tags-ready:not(.lpb-popup-context) .elementor-tags-list__item[data-tag-name="lpb-clicked-post-field"],
             body.lpb-dynamic-tags-ready:not(.lpb-popup-context) .elementor-tags-list__item[data-tag-name="lpb-clicked-post-form-value"],
             body.lpb-dynamic-tags-ready.lpb-popup-context.lpb-form-widget-context .elementor-tags-list__item[data-tag-name="lpb-clicked-post-field"],
             body.lpb-dynamic-tags-ready.lpb-popup-context:not(.lpb-form-widget-context) .elementor-tags-list__item[data-tag-name="lpb-clicked-post-form-value"] { display: none !important; }'
        );
    }

    /**
     * Adds editor JS that toggles the CSS hiding class as Elementor switches documents.
     *
     * @return void
     */
    public function enqueue_editor_visibility_script(): void
    {
        wp_register_script('lpb-editor', false, ['elementor-editor'], LPB_VERSION, true);
        wp_enqueue_script('lpb-editor');
        wp_add_inline_script('lpb-editor', $this->get_editor_visibility_script());
    }

    /**
     * Returns the inline editor script that hides LPB outside loop-item documents.
     *
     * @return string
     */
    private function get_editor_visibility_script(): string
    {
        return <<<'JS'
(function () {
    'use strict';

    var HIDE_CLASS = 'lpb-hide-loop-popup-bridge';
    var POPUP_CLASS = 'lpb-popup-context';
    var FORM_WIDGET_CLASS = 'lpb-form-widget-context';
    var DYNAMIC_TAGS_READY_CLASS = 'lpb-dynamic-tags-ready';
    var LOOP_TYPES = ['loop-item', 'loop_item'];
    var POPUP_TYPES = ['popup'];
    var FORM_WIDGET_TYPES = ['form'];

    function isLoopType(type) {
        return LOOP_TYPES.indexOf(type) !== -1;
    }

    function isPopupType(type) {
        return POPUP_TYPES.indexOf(type) !== -1;
    }

    function isFormWidgetType(widgetType) {
        return FORM_WIDGET_TYPES.indexOf(widgetType) !== -1;
    }

    function getModelValue(model, key) {
        if (!model) {
            return '';
        }

        if (typeof model.get === 'function') {
            return model.get(key) || '';
        }

        return model[key] || '';
    }

    function getCurrentDocumentType() {
        var editor = window.elementor;
        var current;

        if (!editor) {
            return '';
        }

        if (editor.documents && typeof editor.documents.getCurrent === 'function') {
            current = editor.documents.getCurrent();
            if (current && current.config && current.config.type) {
                return current.config.type;
            }
        }

        if (editor.config && editor.config.document && editor.config.document.type) {
            return editor.config.document.type;
        }

        return '';
    }

    function getSelectedWidgetType() {
        var editor = window.elementor;
        var selectedView;
        var selectedContainers;
        var selectedModel;
        var currentElement;

        if (!editor) {
            return '';
        }

        if (editor.channels && editor.channels.panelElements && typeof editor.channels.panelElements.request === 'function') {
            selectedView = editor.channels.panelElements.request('element:selected');
            if (selectedView && selectedView.model) {
                selectedModel = selectedView.model;
            }
        }

        if (!selectedModel && editor.selection && typeof editor.selection.getElements === 'function') {
            selectedContainers = editor.selection.getElements();
            if (selectedContainers && selectedContainers.length && selectedContainers[0].model) {
                selectedModel = selectedContainers[0].model;
            }
        }

        if (!selectedModel && typeof editor.getCurrentElement === 'function') {
            currentElement = editor.getCurrentElement();
            if (currentElement && currentElement.model) {
                selectedModel = currentElement.model;
            }
        }

        return getModelValue(selectedModel, 'widgetType');
    }

    function refreshVisibility() {
        var type = getCurrentDocumentType();
        var isPopup = isPopupType(type);
        var widgetType = getSelectedWidgetType();

        document.body.classList.toggle(HIDE_CLASS, Boolean(type) && !isLoopType(type));
        document.body.classList.toggle(POPUP_CLASS, isPopup);
        document.body.classList.toggle(FORM_WIDGET_CLASS, isPopup && isFormWidgetType(widgetType));
        document.body.classList.add(DYNAMIC_TAGS_READY_CLASS);
    }

    function bindChannel(channel, events) {
        if (channel && typeof channel.on === 'function') {
            channel.on(events, function () {
                window.setTimeout(refreshVisibility, 0);
            });
        }
    }

    function bind() {
        if (bind.bound) {
            return;
        }

        bind.bound = true;
        refreshVisibility();

        if (window.elementor && typeof window.elementor.on === 'function') {
            window.elementor.on('document:loaded document:changed preview:loaded', refreshVisibility);
        }

        if (window.elementor && window.elementor.channels) {
            bindChannel(window.elementor.channels.editor, 'element:edit section:activated panel:activated change:status');
            bindChannel(window.elementor.channels.data, 'document:loaded document:changed');
        }

        document.addEventListener('click', function () {
            window.setTimeout(refreshVisibility, 0);
        }, true);

        window.setInterval(refreshVisibility, 1000);
    }

    if (window.elementor) {
        bind();
    } else if (window.jQuery) {
        window.jQuery(window).on('elementor:init', bind);
    } else {
        document.addEventListener('DOMContentLoaded', bind);
    }
}());
JS;
    }

    /**
     * Returns SELECT2-compatible options for all published Elementor Pro popup templates.
     *
     * Results are cached in the static $popup_options_cache property so the
     * get_posts() database query runs at most once per PHP request.
     *
     * Skips the query entirely on pure frontend renders to avoid unnecessary DB overhead.
     *
     * @return array<string, string>  Map of popup post ID (string key) to display label.
     */
    private function get_popup_options(): array
    {
        if (null !== self::$popup_options_cache) {
            return self::$popup_options_cache;
        }

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
            $options[(string) $popup->ID] = esc_html(
                sprintf('%s  (ID: %d)', $popup->post_title, $popup->ID)
            );
        }

        return self::$popup_options_cache = $options;
    }
}
