<?php

declare(strict_types=1);

namespace LoopPopupBridge\DynamicTags;

if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use LoopPopupBridge\Support\FieldRegistry;

/**
 * Text dynamic tag for use in Elementor Pro form hidden fields.
 *
 * Outputs a plain-text marker (e.g. "lpb-bind-select:colors|target=field_abc123")
 * that JavaScript reads to dynamically populate a <select> element's <option> children
 * with the clicked post's field data. An array value generates multiple options;
 * a string generates a single option.
 */
final class ClickedPostFormSelectTag extends Tag
{
    public function get_name(): string
    {
        return 'lpb-clicked-post-form-select';
    }

    public function get_title(): string
    {
        return esc_html__('Clicked Post Field (Form Select)', 'loop-popup-bridge');
    }

    public function get_group(): string
    {
        return DynamicTagsManager::GROUP;
    }

    public function get_categories(): array
    {
        return [
            TagsModule::TEXT_CATEGORY,
            TagsModule::POST_META_CATEGORY,
        ];
    }

    public function get_panel_template_setting_key(): string
    {
        return 'field';
    }

    public function is_settings_required(): bool
    {
        return true;
    }

    protected function register_controls(): void
    {
        $this->add_control(
            'field',
            [
                'label'       => esc_html__('Field', 'loop-popup-bridge'),
                'type'        => Controls_Manager::SELECT,
                'groups'      => FieldRegistry::get_text_groups(),
                'options'     => FieldRegistry::get_text_options(),
                'default'     => 'title',
                'label_block' => true,
            ]
        );

        $this->add_control(
            'custom_key',
            [
                'label'       => esc_html__('Custom Key', 'loop-popup-bridge'),
                'type'        => Controls_Manager::TEXT,
                'placeholder' => esc_html__('e.g. event_options', 'loop-popup-bridge'),
                'description' => esc_html__('Custom keys must be allowed via the lpb_allowed_meta_keys filter.', 'loop-popup-bridge'),
                'condition'   => ['field' => 'custom'],
            ]
        );

        $this->add_control(
            'usage_hint',
            [
                'type' => Controls_Manager::RAW_HTML,
                'raw'  => '<div style="padding:8px 10px;border-left:3px solid #007cba;font-size:12px;line-height:1.6">'
                    . '<strong>' . esc_html__('How to use', 'loop-popup-bridge') . '</strong><br>'
                    . esc_html__('This tag outputs a marker string. Paste that string as the Value of one option inside your Elementor form\'s Select field. JS replaces it with the clicked post\'s data at runtime and removes the marker from the page automatically.', 'loop-popup-bridge')
                    . '<br><br>'
                    . '<strong>' . esc_html__('Marker format', 'loop-popup-bridge') . '</strong><br><br>'
                    . '<code style="padding:1px 4px">lpb-bind-select:<em>field</em>|fallback=<em>value</em></code><br><br>'
                    . '<code style="padding:1px 4px">lpb-bind-select:meta:<em>key</em>|fallback=<em>value</code>'
                    . '</div>',
                'content_classes' => 'elementor-descriptor',
            ]
        );

    }

    public function render(): void
    {
        $binding = FieldRegistry::resolve_selection(
            (string) $this->get_settings('field'),
            (string) $this->get_settings('custom_key')
        );

        if (null === $binding) {
            return;
        }

        $marker = 'lpb-bind-select:' . $binding['field'];

        if ('meta' === $binding['field'] && '' !== $binding['meta_key']) {
            $marker .= ':' . $binding['meta_key'];
        }

        $fallback = sanitize_text_field((string) $this->get_settings('fallback'));
        if ('' !== $fallback) {
            $marker .= '|fallback=' . rawurlencode($fallback);
        }

        echo esc_html($marker);
    }
}
