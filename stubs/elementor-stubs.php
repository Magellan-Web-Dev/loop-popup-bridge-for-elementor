<?php
/**
 * Elementor IDE stubs for Loop Popup Bridge for Elementor.
 *
 * These declarations exist solely to give IDEs (Intelephense, PHPStorm, etc.)
 * type information for Elementor classes that are not available as Composer
 * packages. This file is NEVER loaded at runtime — it is not required anywhere
 * in the plugin and the PSR-4 autoloader only handles the LoopPopupBridge\
 * namespace. If Elementor is active, the real classes are already in memory.
 *
 * @see https://github.com/elementor/elementor
 */

// phpcs:disable

namespace Elementor;

if (false) {

    /**
     * Base class for all Elementor elements (widgets, sections, columns, containers).
     */
    class Element_Base
    {
        /**
         * Returns the element's unique name / type slug.
         *
         * @return string
         */
        public function get_name(): string
        {
            return '';
        }

        /**
         * Returns all settings resolved for display (dynamic tags applied).
         *
         * @param  string|null $setting_key  Optional specific setting key.
         * @return array<string, mixed>|mixed
         */
        public function get_settings_for_display(?string $_setting_key = null): mixed
        {
            return [];
        }

        /**
         * Adds or merges an HTML attribute value on a named attribute group.
         *
         * @param  string                    $_element    Attribute group key (e.g. "_wrapper").
         * @param  string|array<string,mixed> $_key        Attribute name or key-value map.
         * @param  mixed                     $_value      Attribute value (when $_key is a string).
         * @param  bool                      $_overwrite  Replace existing value instead of merging.
         * @return static
         */
        public function add_render_attribute(
            string $_element,
            string|array|null $_key = null,
            mixed $_value = null,
            bool $_overwrite = false
        ): static {
            return $this;
        }

        /**
         * Opens a new controls section.
         *
         * @param  string             $_section_id  Unique section identifier.
         * @param  array<string,mixed> $_args        Section configuration (label, tab, condition…).
         * @return void
         */
        public function start_controls_section(string $_section_id, array $_args = []): void {}

        /**
         * Closes the most recently opened controls section.
         *
         * @return void
         */
        public function end_controls_section(): void {}

        /**
         * Registers a single control inside the active section.
         *
         * @param  string             $_id    Unique control identifier.
         * @param  array<string,mixed> $_args  Control configuration (type, label, default…).
         * @return void
         */
        public function add_control(string $_id, array $_args): void {}

        /**
         * Returns the element's unique runtime name (type slug + position).
         *
         * @return string
         */
        public function get_unique_name(): string
        {
            return '';
        }
    }

    /**
     * Base class for all draggable Elementor widgets.
     *
     * Extends Element_Base with widget-specific methods (title, icon, render…).
     */
    class Widget_Base extends Element_Base
    {
        /**
         * Returns the widget's human-readable display title.
         *
         * @return string
         */
        public function get_title(): string
        {
            return '';
        }

        /**
         * Returns the Elementor icon class string for the widget panel icon.
         *
         * @return string
         */
        public function get_icon(): string
        {
            return '';
        }

        /**
         * Returns the widget panel category slugs this widget belongs to.
         *
         * @return string[]
         */
        public function get_categories(): array
        {
            return [];
        }

        /**
         * Returns search keyword strings used to find this widget in the panel.
         *
         * @return string[]
         */
        public function get_keywords(): array
        {
            return [];
        }

        /**
         * Registers controls for this widget. Called once per widget type.
         *
         * @return void
         */
        protected function register_controls(): void {}

        /**
         * Renders the widget's HTML on the frontend.
         *
         * @return void
         */
        protected function render(): void {}
    }

    /**
     * Base class for Elementor's shared common-stack widgets (Widget_Common,
     * Widget_Common_Optimized). Used to detect and skip common-stack instances
     * when injecting controls directly into individual widget stacks.
     */
    class Widget_Common_Base extends Widget_Base {}

    /**
     * Manages the global registry of registered Elementor widgets.
     */
    class Widgets_Manager
    {
        /**
         * Adds a widget instance to the registry.
         *
         * @param  Widget_Base $_widget  The widget to register.
         * @return void
         */
        public function register(Widget_Base $_widget): void {}
    }

    /**
     * Provides control type constants and utility methods for building Elementor controls.
     */
    class Controls_Manager
    {
        /** @var string Advanced tab identifier. */
        const TAB_ADVANCED = 'advanced';

        /** @var string Content tab identifier. */
        const TAB_CONTENT  = 'content';

        /** @var string Style tab identifier. */
        const TAB_STYLE    = 'style';

        /** @var string On/off switcher control type. */
        const SWITCHER     = 'switcher';

        /** @var string Searchable Select2 dropdown control type. */
        const SELECT2      = 'select2';

        /** @var string Plain-text input control type. */
        const TEXT         = 'text';

        /** @var string Dropdown select control type. */
        const SELECT       = 'select';

        /** @var string Media / image picker control type. */
        const MEDIA        = 'media';

        /** @var string Number input control type. */
        const NUMBER       = 'number';
    }

    /**
     * Static utility helpers used throughout Elementor.
     */
    class Utils
    {
        /**
         * Returns the URL of Elementor's built-in placeholder image.
         *
         * @return string
         */
        public static function get_placeholder_image_src(): string
        {
            return '';
        }
    }

} // end if (false)

namespace Elementor\Core\DynamicTags;

if (false) {

    /**
     * Minimal dynamic tag manager stub.
     */
    class Manager
    {
        public function register_group(string $group_name, array $group_settings): void {}

        public function register(Base_Tag $dynamic_tag_instance): void {}
    }

    abstract class Base_Tag extends \Elementor\Element_Base
    {
        abstract public function get_categories();

        abstract public function get_group();

        abstract public function get_title();

        abstract public function get_content(array $options = []);

        abstract public function get_content_type();

        public function get_panel_template_setting_key(): string
        {
            return '';
        }

        public function is_settings_required(): bool
        {
            return false;
        }
    }

    abstract class Tag extends Base_Tag
    {
        public function get_content(array $options = []): string
        {
            return '';
        }

        final public function get_content_type(): string
        {
            return 'ui';
        }
    }

    abstract class Data_Tag extends Base_Tag
    {
        abstract protected function get_value(array $options = []);

        final public function get_content_type(): string
        {
            return 'plain';
        }

        public function get_content(array $options = []): mixed
        {
            return $this->get_value($options);
        }
    }
}

namespace Elementor\Modules\DynamicTags;

if (false) {

    class Module
    {
        const TEXT_CATEGORY      = 'text';
        const URL_CATEGORY       = 'url';
        const IMAGE_CATEGORY     = 'image';
        const MEDIA_CATEGORY     = 'media';
        const POST_META_CATEGORY = 'post_meta';
    }
}
