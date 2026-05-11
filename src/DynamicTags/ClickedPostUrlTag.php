<?php

declare(strict_types=1);

namespace LoopPopupBridge\DynamicTags;

if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use LoopPopupBridge\Support\FieldRegistry;

/**
 * URL dynamic tag that renders a hash marker later replaced by popup JS.
 */
final class ClickedPostUrlTag extends Data_Tag
{
    public function get_name(): string
    {
        return 'lpb-clicked-post-url';
    }

    public function get_title(): string
    {
        return esc_html__('Clicked Post URL', 'loop-popup-bridge');
    }

    public function get_group(): string
    {
        return DynamicTagsManager::GROUP;
    }

    public function get_categories(): array
    {
        return [TagsModule::URL_CATEGORY];
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
                'label'   => esc_html__('Field', 'loop-popup-bridge'),
                'type'    => Controls_Manager::SELECT,
                'options' => FieldRegistry::get_url_options(),
                'default' => 'permalink',
            ]
        );

        $this->add_control(
            'custom_key',
            [
                'label'       => esc_html__('Custom Key', 'loop-popup-bridge'),
                'type'        => Controls_Manager::TEXT,
                'placeholder' => esc_html__('e.g. registration_url', 'loop-popup-bridge'),
                'description' => esc_html__('Custom keys must be allowed via the lpb_allowed_meta_keys filter.', 'loop-popup-bridge'),
                'condition'   => ['field' => 'custom'],
            ]
        );
    }

    protected function get_value(array $options = []): string
    {
        $binding = FieldRegistry::resolve_selection(
            (string) $this->get_settings('field'),
            (string) $this->get_settings('custom_key')
        );

        return null === $binding ? '#' : FieldRegistry::get_hash_marker($binding);
    }
}
