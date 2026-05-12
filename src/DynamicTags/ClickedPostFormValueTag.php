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
 * Outputs a plain-text marker (e.g. "lpb-bind:title") that JavaScript recognises
 * in hidden <input> values and replaces with the clicked post's actual field data
 * when the popup opens. The marker survives as the HTML value attribute so JS can
 * re-apply it on every popup open without losing the binding template.
 */
final class ClickedPostFormValueTag extends Tag
{
    public function get_name(): string
    {
        return 'lpb-clicked-post-form-value';
    }

    public function get_title(): string
    {
        return esc_html__('Clicked Post Field (Form)', 'loop-popup-bridge');
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
                'placeholder' => esc_html__('e.g. event_date', 'loop-popup-bridge'),
                'description' => esc_html__('Custom keys must be allowed via the lpb_allowed_meta_keys filter.', 'loop-popup-bridge'),
                'condition'   => ['field' => 'custom'],
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

        // Plain-text sentinel: "lpb-bind:title" or "lpb-bind:meta:event_date".
        // JS reads input[type="hidden"] value attributes for this prefix and
        // replaces input.value with the actual clicked-post field data.
        $marker = 'lpb-bind:' . $binding['field'];

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
