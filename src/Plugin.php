<?php

declare(strict_types=1);

namespace LoopPopupBridge;

if (!defined('ABSPATH')) exit;

/**
 * Main plugin singleton.
 *
 * Bootstraps every component after passing dependency checks. Keeps a single
 * static instance so the plugin cannot be initialised more than once per
 * WordPress request.
 *
 * Boot sequence:
 *   plugins_loaded (priority 20)
 *     └─ Plugin::instance()
 *          ├─ DependencyChecker: Elementor active?
 *          │    no  → admin notice, stop
 *          │    yes → hook elementor/loaded
 *          └─ elementor/loaded fires
 *               ├─ new WidgetControlsManager()   – editor controls for all widgets
 *               ├─ new DynamicTagsManager()       – clicked-post dynamic tags
 *               ├─ new FrontendManager()          – data-lpb-* attrs + JS enqueue
 *               ├─ rest_api_init → PostEndpoint   – custom REST route
 *               └─ Elementor Pro active?
 *                    no  → admin notice
 *                    yes → elementor/widgets/register → popup widgets
 */
final class Plugin
{
    /**
     * The single instance of this class.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Dependency checker used to test for Elementor and Elementor Pro.
     *
     * Declared readonly so it cannot be overwritten after construction.
     *
     * @var DependencyChecker
     */
    private readonly DependencyChecker $checker;

    /**
     * Private constructor — use {@see instance()} to obtain the singleton.
     *
     * Instantiates the dependency checker and begins the boot sequence.
     */
    private function __construct()
    {
        $this->checker = new DependencyChecker();
        $this->boot();
    }

    /**
     * Returns (and lazily creates) the singleton instance.
     *
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Runs the initial dependency check and schedules component initialisation.
     *
     * If Elementor is not active, an admin notice is queued and no further
     * initialisation takes place — the plugin fails gracefully.
     *
     * Timing note: this plugin loads at plugins_loaded priority 20. Elementor
     * fires elementor/loaded during its own plugin load (lower priority), so by
     * the time we run that action has already fired. We therefore check
     * did_action() and call init_components() directly when Elementor has already
     * loaded, falling back to the hook for any edge-case load orders.
     *
     * @return void
     */
    private function boot(): void
    {
        if (!$this->checker->is_elementor_active()) {
            add_action('admin_notices', [$this->checker, 'notice_elementor_missing']);
            return;
        }

        if (did_action('elementor/loaded') > 0) {
            // elementor/loaded already fired — call directly.
            $this->init_components();
        } else {
            // Elementor loaded before us; stay hooked for unusual load orders.
            add_action('elementor/loaded', [$this, 'init_components']);
        }
    }

    /**
     * Instantiates and hooks all plugin components.
     *
     * Called by the elementor/loaded action. Registers editor controls, the
     * frontend render hook, the REST endpoint, and (conditionally) the two
     * popup widgets that require Elementor Pro.
     *
     * @return void
     */
    public function init_components(): void
    {
        new Controls\WidgetControlsManager();
        new DynamicTags\DynamicTagsManager();
        new Frontend\FrontendManager();

        add_action('rest_api_init', static function (): void {
            (new REST\PostEndpoint())->register_routes();
        });

        if ($this->checker->is_elementor_pro_active()) {
            // elementor/widgets/register fires during Elementor's own init, which
            // has not yet run at this point — safe to hook here.
            add_action('elementor/widgets/register', [$this, 'register_popup_widgets']);
        } else {
            add_action('admin_notices', [$this->checker, 'notice_elementor_pro_missing']);
        }
    }

    /**
     * Registers the two popup-side placeholder widgets with Elementor.
     *
     * Hooked to elementor/widgets/register, which passes the Widgets_Manager
     * instance as the first argument.
     *
     * @param  object $manager  Elementor\Widgets_Manager instance provided by the hook.
     * @return void
     */
    public function register_popup_widgets(object $manager): void
    {
        $manager->register(new Widgets\ClickedPostField());
        $manager->register(new Widgets\ClickedPostImage());
    }

    /**
     * Prevents cloning of the singleton instance.
     *
     * @return void
     */
    public function __clone() {}

    /**
     * Prevents unserialization of the singleton instance.
     *
     * @return void
     */
    public function __wakeup() {}
}
