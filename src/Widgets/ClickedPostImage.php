<?php

declare(strict_types=1);

namespace LoopPopupBridge\Widgets;

if (!defined('ABSPATH')) exit;

use Elementor\Controls_Manager;
use Elementor\Utils;
use Elementor\Widget_Base;

/**
 * Elementor widget: "Clicked Post Image"
 *
 * Place this widget inside an Elementor Pro popup. It renders an <img>
 * placeholder with data-lpb-field="featured_image". When the popup opens,
 * loop-popup-bridge.js replaces the src and alt with data from the clicked
 * Loop Grid post.
 *
 * Example output:
 *   <img data-lpb-field="featured_image"
 *        data-lpb-alt-source="image_alt"
 *        data-lpb-size="full"
 *        src="/path/to/fallback.jpg"
 *        alt=""
 *        loading="lazy"
 *        style="max-width:100%;">
 */
final class ClickedPostImage extends Widget_Base
{
    /**
     * Returns the unique machine name that identifies this widget type.
     *
     * @return string
     */
    public function get_name(): string
    {
        return 'lpb_clicked_post_image';
    }

    /**
     * Returns the human-readable widget title shown in the Elementor panel.
     *
     * @return string
     */
    public function get_title(): string
    {
        return esc_html__('Clicked Post Image', 'loop-popup-bridge');
    }

    /**
     * Returns the Elementor icon class used for this widget in the panel.
     *
     * @return string
     */
    public function get_icon(): string
    {
        return 'eicon-image';
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
        return ['loop', 'popup', 'post', 'image', 'thumbnail', 'featured', 'lpb'];
    }

    /**
     * Registers the widget's editor controls.
     *
     * Controls:
     *   lpb_fallback_image  — media picker for the image shown before data loads
     *   lpb_alt_source      — whether alt text comes from the image attachment or post title
     *   lpb_image_size_type — WordPress image size or custom CSS width
     *   lpb_image_css_width — custom CSS width value (only when size_type = "custom")
     *
     * @return void
     */
    protected function register_controls(): void
    {
        $this->start_controls_section('content_section', [
            'label' => esc_html__('Image Settings', 'loop-popup-bridge'),
            'tab'   => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('lpb_fallback_image', [
            'label'       => esc_html__('Fallback Image', 'loop-popup-bridge'),
            'type'        => Controls_Manager::MEDIA,
            'default'     => ['url' => Utils::get_placeholder_image_src()],
            'description' => esc_html__('Displayed while post data loads or if no featured image is set.', 'loop-popup-bridge'),
        ]);

        $this->add_control('lpb_alt_source', [
            'label'   => esc_html__('Alt Text Source', 'loop-popup-bridge'),
            'type'    => Controls_Manager::SELECT,
            'options' => [
                'image_alt'  => esc_html__('Featured Image Alt Text', 'loop-popup-bridge'),
                'post_title' => esc_html__('Post Title', 'loop-popup-bridge'),
            ],
            'default' => 'image_alt',
        ]);

        $this->add_control('lpb_image_size_type', [
            'label'   => esc_html__('Size', 'loop-popup-bridge'),
            'type'    => Controls_Manager::SELECT,
            'options' => [
                'full'   => esc_html__('Full', 'loop-popup-bridge'),
                'large'  => esc_html__('Large', 'loop-popup-bridge'),
                'medium' => esc_html__('Medium', 'loop-popup-bridge'),
                'custom' => esc_html__('Custom CSS Width', 'loop-popup-bridge'),
            ],
            'default' => 'full',
        ]);

        $this->add_control('lpb_image_css_width', [
            'label'     => esc_html__('CSS Width', 'loop-popup-bridge'),
            'type'      => Controls_Manager::TEXT,
            'default'   => '100%',
            'condition' => ['lpb_image_size_type' => 'custom'],
        ]);

        $this->end_controls_section();
    }

    /**
     * Renders the widget's placeholder <img> HTML on the frontend.
     *
     * Outputs an <img> whose data-lpb-field="featured_image" attribute is
     * targeted by loop-popup-bridge.js. The fallback image src is shown before
     * the popup opens and while the REST fetch is in flight. The alt attribute
     * is intentionally empty at render time; JavaScript fills it from the post
     * data according to the data-lpb-alt-source value.
     *
     * @return void
     */
    protected function render(): void
    {
        $settings   = $this->get_settings_for_display();
        $fallback   = esc_url((string) ($settings['lpb_fallback_image']['url'] ?? ''));

        $alt_source = in_array($settings['lpb_alt_source'] ?? 'image_alt', ['image_alt', 'post_title'], true)
            ? $settings['lpb_alt_source']
            : 'image_alt';

        $size_type  = $settings['lpb_image_size_type'] ?? 'full';
        $css_width  = ('custom' === $size_type)
            ? sanitize_text_field((string) ($settings['lpb_image_css_width'] ?? '100%'))
            : '100%';

        printf(
            '<img data-lpb-field="featured_image" data-lpb-alt-source="%s" data-lpb-size="%s" src="%s" alt="" loading="lazy" style="max-width:%s;">',
            esc_attr($alt_source),
            esc_attr(sanitize_key($size_type)),
            $fallback,
            esc_attr($css_width)
        );
    }
}
