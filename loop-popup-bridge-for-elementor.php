<?php
/**
 * Plugin Name:       Loop Popup Bridge for Elementor
 * Description:       Click any widget inside an Elementor Loop Grid item to open a shared Elementor Pro popup dynamically populated from that post.
 * Version:           1.0.0
 * Author:            Chris Paschall
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       loop-popup-bridge
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Elementor tested up to: 4.0.1
 * Elementor Pro tested up to: 4.0.1
 *
 * Flow overview:
 *  1. WidgetControlsManager injects "Loop Popup Bridge" controls into every widget's Advanced tab.
 *  2. FrontendManager reads those settings at render time and writes data-lpb-* attributes on the wrapper.
 *  3. loop-popup-bridge.js listens for clicks, stores the active post ID, opens the Elementor Pro popup,
 *     fetches post data from the custom REST endpoint, and fills [data-lpb-field] placeholder widgets.
 *  4. ClickedPostField / ClickedPostImage are popup-side widgets that render those placeholder elements.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;

/**
 * PHP version guard.
 *
 * This file must not use PHP 8.1+ syntax directly. PHP parses the entire file
 * before executing any branch, so 8.1+ syntax here would cause a fatal parse
 * error on older runtimes before this guard ever runs. PHP 8.1+ code is safely
 * isolated in the separately required files inside the else block below.
 */
if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>Loop Popup Bridge For Elementor</strong> requires PHP 8.1 or higher. '
            . 'Your server is running PHP %s. Please contact your host to upgrade PHP before activating this plugin.',
            esc_html(PHP_VERSION)
        );
        echo '</p></div>';
    });

/**
 * Main plugin class and bootstrap logic.
 * Contains the Plugin class which serves as the composition root for the entire plugin,
 * and the activation/deactivation hooks and plugins_loaded handler that instantiate and initialize the plugin.
 */
} else {

    /** @var string Plugin version. */
    define('LPB_VERSION', '1.0.0');

    /** @var string Absolute path to the main plugin file. */
    define('LPB_FILE', __FILE__);

    /** @var string Absolute path to the plugin root directory (with trailing slash). */
    define('LPB_PATH', plugin_dir_path(__FILE__));

    /** @var string Public URL to the plugin root directory (with trailing slash). */
    define('LPB_URL', plugin_dir_url(__FILE__));

    // Require and register the class-based PSR-4 autoloader before anything else.
    require_once LPB_PATH . 'src/Autoloader.php';
    \LoopPopupBridge\Autoloader::register();

    // Initialise after all plugins are loaded so ELEMENTOR_VERSION is already defined.
    add_action('plugins_loaded', static function (): void {
        \LoopPopupBridge\Plugin::instance();
    }, 20);
}