<?php

declare(strict_types=1);

namespace LoopPopupBridge;

if (!defined('ABSPATH')) exit;

/**
 * Checks for required plugin dependencies and surfaces admin notices when they are missing.
 *
 * This class is intentionally free of any Elementor API calls so it remains
 * safe to instantiate even when Elementor is not active.
 */
final class DependencyChecker
{
    /**
     * Returns true when the Elementor core plugin is active.
     *
     * Uses the ELEMENTOR_VERSION constant, which Elementor defines at the top of
     * its main plugin file — reliably present after plugins_loaded regardless of
     * plugin load order.
     *
     * @return bool
     */
    public function is_elementor_active(): bool
    {
        return defined('ELEMENTOR_VERSION');
    }

    /**
     * Returns true when the Elementor Pro plugin is active.
     *
     * Checks both the ELEMENTOR_PRO_VERSION constant and the Pro main class as a
     * fallback, covering edge cases where constants may not yet be defined.
     *
     * @return bool
     */
    public function is_elementor_pro_active(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION') || class_exists('\ElementorPro\Plugin', false);
    }

    /**
     * Outputs an error-level admin notice when Elementor core is not active.
     *
     * Hooked to admin_notices. Uses wp_kses_post() to allow the plugin link
     * anchor while stripping any other markup.
     *
     * @return void
     */
    public function notice_elementor_missing(): void
    {
        echo '<div class="notice notice-error"><p>';
        echo wp_kses_post(
            sprintf(
                /* translators: %s: Elementor plugin link */
                __('<strong>Loop Popup Bridge for Elementor</strong> requires %s to be installed and activated.', 'loop-popup-bridge'),
                '<a href="https://wordpress.org/plugins/elementor/" target="_blank" rel="noopener">Elementor</a>'
            )
        );
        echo '</p></div>';
    }

    /**
     * Outputs a warning-level admin notice when Elementor Pro is not active.
     *
     * Popup widgets and the click-to-open behaviour are disabled but the plugin
     * itself stays active, so the editor controls remain visible.
     *
     * @return void
     */
    public function notice_elementor_pro_missing(): void
    {
        echo '<div class="notice notice-warning"><p>';
        echo wp_kses_post(
            sprintf(
                /* translators: %s: Elementor Pro plugin name */
                __('<strong>Loop Popup Bridge for Elementor</strong> requires %s for popup functionality. Popup widgets and the click-to-open behaviour will not work until Elementor Pro is activated.', 'loop-popup-bridge'),
                '<strong>Elementor Pro</strong>'
            )
        );
        echo '</p></div>';
    }
}
