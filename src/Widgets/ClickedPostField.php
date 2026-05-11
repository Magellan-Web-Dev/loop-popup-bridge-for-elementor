<?php

declare(strict_types=1);

namespace LoopPopupBridge\Widgets;

if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

/**
 * Elementor widget: "Clicked Post Field"
 *
 * Place this widget inside an Elementor Pro popup. It renders a placeholder
 * HTML element with a data-lpb-field attribute. When the popup opens,
 * loop-popup-bridge.js finds these placeholders and fills them with data from
 * the clicked Loop Grid post.
 *
 * Example outputs:
 *   <span data-lpb-field="title" data-lpb-fallback="Untitled"></span>
 *   <span data-lpb-field="meta" data-lpb-meta-key="event_date"></span>
 *   <h2 data-lpb-field="excerpt"></h2>
 */
final class ClickedPostField extends Widget_Base
{
    /**
     * Returns the unique machine name that identifies this widget type.
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'lpb_clicked_post_field';
    }

    /**
     * Returns the human-readable widget title shown in the Elementor panel.
     *
     * @return string
     */
    public function get_title(): string
    {
        return esc_html__('Clicked Post Field', 'loop-popup-bridge');
    }

    /**
     * Returns the Elementor icon class used for this widget in the panel.
     *
     * @return string
     */
    public function get_icon(): string
    {
        return 'eicon-post-content';
    }

    /**
     * Returns the panel category slugs this widget appears under.
     *
     * @return string[]
     */
    public function get_categories(): array
    {
        return ['general'];
    }

    /**
     * Returns search keywords that surface this widget in the Elementor panel.
     *
     * @return string[]
     */
    public function get_keywords(): array
    {
        return ['loop', 'popup', 'post', 'dynamic', 'field', 'lpb'];
    }

    /**
     * Registers the widget's editor controls.
     *
     * Controls:
     *   lpb_field    — which post field to display (title, excerpt, content, etc.)
     *   lpb_meta_key — meta key to look up (only visible when field = "meta")
     *   lpb_html_tag — wrapper element tag (span, div, p, h2, h3)
     *   lpb_fallback — text shown when the field value is empty
     *
     * @return void
     */
    protected function register_controls(): void
    {
        $this->start_controls_section('content_section', [
            'label' => esc_html__('Field Settings', 'loop-popup-bridge'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('lpb_field', [
            'label'   => esc_html__('Field', 'loop-popup-bridge'),
            'type'    => Controls_Manager::SELECT,
            'options' => [
                'title'     => esc_html__('Title', 'loop-popup-bridge'),
                'excerpt'   => esc_html__('Excerpt', 'loop-popup-bridge'),
                'content'   => esc_html__('Content (HTML)', 'loop-popup-bridge'),
                'permalink' => esc_html__('Permalink', 'loop-popup-bridge'),
                'date'      => esc_html__('Published Date', 'loop-popup-bridge'),
                'modified'  => esc_html__('Modified Date', 'loop-popup-bridge'),
                'meta'      => esc_html__('Custom Meta', 'loop-popup-bridge'),
            ],
            'default' => 'title',
        ]);

        $this->add_control('lpb_meta_key', [
            'label'       => esc_html__('Meta Key', 'loop-popup-bridge'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => esc_html__('e.g. event_date', 'loop-popup-bridge'),
            'description' => esc_html__('The key must also be listed in the lpb_allowed_meta_keys server filter.', 'loop-popup-bridge'),
            'condition'   => ['lpb_field' => 'meta'],
        ]);

        $this->add_control('lpb_html_tag', [
            'label'   => esc_html__('HTML Tag', 'loop-popup-bridge'),
            'type'    => Controls_Manager::SELECT,
            'options' => [
                'span' => 'span',
                'div'  => 'div',
                'p'    => 'p',
                'h2'   => 'h2',
                'h3'   => 'h3',
            ],
            'default' => 'span',
        ]);

        $this->add_control('lpb_fallback', [
            'label'       => esc_html__('Fallback Text', 'loop-popup-bridge'),
            'type'        => Controls_Manager::TEXT,
            'placeholder' => esc_html__('Shown when field is empty', 'loop-popup-bridge'),
        ]);

        $this->end_controls_section();
    }

    /**
     * Renders the widget's placeholder HTML on the frontend.
     *
     * Outputs a single element whose tag, data-lpb-field, optional data-lpb-meta-key,
     * and data-lpb-fallback attributes are read by loop-popup-bridge.js when the
     * popup opens and fills the element with live post data.
     *
     * The fallback text is used as the element's initial text content so the widget
     * is visible and identifiable inside the Elementor editor, where no active post
     * context exists yet.
     *
     * @return void
     */
    protected function render(): void
    {
        $settings = $this->get_settings_for_display();

        $field    = sanitize_key((string) ($settings['lpb_field'] ?? 'title'));
        $fallback = sanitize_text_field((string) ($settings['lpb_fallback'] ?? ''));

        $allowed_tags = ['span', 'div', 'p', 'h2', 'h3'];
        $tag = in_array($settings['lpb_html_tag'] ?? 'span', $allowed_tags, true)
            ? $settings['lpb_html_tag']
            : 'span';

        echo '<' . $tag;
        echo ' data-lpb-field="' . esc_attr($field) . '"';

        if ('meta' === $field && !empty($settings['lpb_meta_key'])) {
            echo ' data-lpb-meta-key="' . esc_attr(sanitize_key((string) $settings['lpb_meta_key'])) . '"';
        }

        if ('' !== $fallback) {
            echo ' data-lpb-fallback="' . esc_attr($fallback) . '"';
        }

        // Initial text content equals the fallback so the widget is meaningful in
        // the editor. JavaScript replaces it with live data when the popup opens.
        echo '>' . esc_html($fallback) . '</' . $tag . '>';
    }
}
