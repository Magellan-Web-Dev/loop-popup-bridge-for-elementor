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
     *
     * Skips registration entirely when the Elementor editor is open for a loop-item
     * template — LPB tags are only meaningful inside popup templates, and showing
     * them in the loop-item picker would be misleading.
     *
     * On the frontend (is_admin() === false) we always register so that saved
     * dynamic tag references in popup templates render their placeholder HTML.
     */
    public function register_tags(Manager $dynamic_tags): void
    {
        if ($this->is_loop_item_editor()) {
            return;
        }

        $dynamic_tags->register_group(self::GROUP, [
            'title' => esc_html__('Loop Popup Bridge', 'loop-popup-bridge'),
        ]);

        $dynamic_tags->register(new ClickedPostFieldTag());
        $dynamic_tags->register(new ClickedPostFormValueTag());
        $dynamic_tags->register(new ClickedPostFormSelectTag());
        $dynamic_tags->register(new ClickedPostFormRadioTag());
        $dynamic_tags->register(new ClickedPostUrlTag());
        $dynamic_tags->register(new ClickedPostImageTag());
    }

    /**
     * Returns true when the Elementor editor is open for a loop-item template.
     *
     * Uses $_REQUEST['post'] — the same parameter Elementor's own editor reads —
     * so it works for both GET and POST delivery of the post ID. Falls back to
     * $_POST['editor_post_id'] for AJAX requests dispatched from within the editor.
     *
     * Returns false on frontend requests (where is_admin() is false) so that tags
     * always register for popup template rendering.
     *
     * @return bool
     */
    private function is_loop_item_editor(): bool
    {
        if (!is_admin() && !wp_doing_ajax()) {
            return false;
        }

        $post_id = absint($_REQUEST['post'] ?? $_POST['editor_post_id'] ?? 0);

        if (!$post_id) {
            return false;
        }

        $template_type = (string) get_post_meta($post_id, '_elementor_template_type', true);

        return in_array($template_type, ['loop-item', 'loop_item'], true);
    }
}
