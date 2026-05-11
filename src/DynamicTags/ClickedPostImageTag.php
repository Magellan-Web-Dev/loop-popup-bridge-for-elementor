<?php

declare(strict_types=1);

namespace LoopPopupBridge\DynamicTags;

if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;
use Elementor\Utils;
use LoopPopupBridge\Support\FieldRegistry;

/**
 * Image/media dynamic tag that renders a fallback image URL with a binding marker.
 */
final class ClickedPostImageTag extends Data_Tag
{
    public function get_name(): string
    {
        return 'lpb-clicked-post-image';
    }

    public function get_title(): string
    {
        return esc_html__('Clicked Post Image', 'loop-popup-bridge');
    }

    public function get_group(): string
    {
        return DynamicTagsManager::GROUP;
    }

    public function get_categories(): array
    {
        return [
            TagsModule::IMAGE_CATEGORY,
            TagsModule::MEDIA_CATEGORY,
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
                'label'   => esc_html__('Field', 'loop-popup-bridge'),
                'type'    => Controls_Manager::SELECT,
                'options' => FieldRegistry::get_image_options(),
                'default' => 'featured_image',
            ]
        );

        $this->add_control(
            'custom_key',
            [
                'label'       => esc_html__('Custom Key', 'loop-popup-bridge'),
                'type'        => Controls_Manager::TEXT,
                'placeholder' => esc_html__('e.g. gallery_card_image', 'loop-popup-bridge'),
                'description' => esc_html__('Custom keys must be allowed via the lpb_allowed_meta_keys filter.', 'loop-popup-bridge'),
                'condition'   => ['field' => 'custom'],
            ]
        );

        $this->add_control(
            'fallback_image',
            [
                'label'   => esc_html__('Fallback Image', 'loop-popup-bridge'),
                'type'    => Controls_Manager::MEDIA,
                'default' => ['url' => Utils::get_placeholder_image_src()],
            ]
        );
    }

    protected function get_value(array $options = []): array
    {
        $binding = FieldRegistry::resolve_selection(
            (string) $this->get_settings('field'),
            (string) $this->get_settings('custom_key')
        );

        $fallback = (array) $this->get_settings('fallback_image');
        $url      = esc_url_raw((string) ($fallback['url'] ?? Utils::get_placeholder_image_src()));

        if ('' === $url) {
            $url = Utils::get_placeholder_image_src();
        }

        if (null !== $binding) {
            $url = FieldRegistry::add_marker_query_args($url, $binding);
        }

        return [
            'id'  => 0,
            'url' => $url,
        ];
    }
}
