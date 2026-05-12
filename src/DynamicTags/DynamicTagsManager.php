<?php

declare(strict_types=1);

namespace LoopPopupBridge\DynamicTags;

if (!defined('ABSPATH')) exit;

use Elementor\Core\DynamicTags\Manager;

/**
 * Registers Loop Popup Bridge dynamic tags with Elementor.
 */
final class DynamicTagsManager
{
    public const GROUP = 'loop-popup-bridge';

    /**
     * Hooks dynamic tag registration.
     */
    public function __construct()
    {
        add_action('elementor/dynamic_tags/register', [$this, 'register_tags']);
    }

    /**
     * Registers the dynamic tag group and all LPB tags.
     */
    public function register_tags(Manager $dynamic_tags): void
    {
        $dynamic_tags->register_group(self::GROUP, [
            'title' => esc_html__('Loop Popup Bridge', 'loop-popup-bridge'),
        ]);

        $dynamic_tags->register(new ClickedPostFieldTag());
        $dynamic_tags->register(new ClickedPostFormValueTag());
        $dynamic_tags->register(new ClickedPostUrlTag());
        $dynamic_tags->register(new ClickedPostImageTag());
    }
}
