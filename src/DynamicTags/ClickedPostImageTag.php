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
                'label'       => esc_html__('Field', 'loop-popup-bridge'),
                'type'        => Controls_Manager::SELECT,
                'groups'      => FieldRegistry::get_image_groups(),
                'options'     => FieldRegistry::get_image_options(),
                'default'     => 'featured_image',
                'label_block' => true,
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
        $url      = esc_url_raw(self::extract_fallback_url($fallback));

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

    /**
     * Reads the fallback image URL out of whichever media shape Elementor supplied.
     *
     * Legacy hands the MEDIA control value through untouched, so the URL sits in
     * `url`. Atomic resolves the same control through Image_Prop_Type before the tag
     * is ever created, and the render-time array that produces is keyed `src`
     * (Image_Transformer), with no `url` of its own.
     *
     * `src` is read first, not second, because Control_Base_Multiple::get_value()
     * merges the control's default over every media value — so on the atomic path
     * `url` is always present and always holds the placeholder default, which would
     * mask the author's actual fallback. Legacy media values never carry a `src`
     * key, so they still resolve through `url` exactly as before.
     *
     * @param array<string, mixed> $fallback
     */
    private static function extract_fallback_url(array $fallback): string
    {
        foreach (['src', 'url'] as $key) {
            $url = self::normalize_url_value($fallback[$key] ?? null);

            if ('' !== $url) {
                return $url;
            }
        }

        return '';
    }

    /**
     * Accepts a scalar URL, or one wrapped a single level deep in case Elementor
     * hands over a typed value. Anything else — a size map, an object, null — has no
     * URL to offer, and the caller falls back to the placeholder.
     */
    private static function normalize_url_value(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? $value['url'] ?? $value['src'] ?? null;
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
