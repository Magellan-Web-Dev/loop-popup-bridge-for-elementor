<?php

declare(strict_types=1);

namespace LoopPopupBridge;

if (!defined('ABSPATH')) exit;

/**
 * PSR-4 autoloader for the LoopPopupBridge namespace.
 *
 * Maps class names under the LoopPopupBridge\ root namespace to PHP files
 * inside the plugin's src/ directory using the PSR-4 standard:
 *
 *   LoopPopupBridge\Foo\Bar  →  {LPB_PATH}src/Foo/Bar.php
 *
 * Usage (in the main plugin file, after requiring this file):
 *   \LoopPopupBridge\Autoloader::register();
 */
final class Autoloader
{
    /**
     * The root namespace prefix handled by this autoloader.
     *
     * @var string
     */
    private const NAMESPACE_PREFIX = 'LoopPopupBridge\\';

    /**
     * Absolute path to the src/ directory (with trailing separator).
     *
     * Populated once in {@see register()} so the constant is expanded only at
     * registration time, not on every autoload call.
     *
     * @var string
     */
    private static string $src_path = '';

    /**
     * Registers this autoloader with the SPL autoload stack.
     *
     * Must be called exactly once, after the plugin's LPB_PATH constant is
     * defined and after this file has been required manually.
     *
     * @return void
     */
    public static function register(): void
    {
        self::$src_path = LPB_PATH . 'src' . DIRECTORY_SEPARATOR;
        spl_autoload_register([static::class, 'load']);
    }

    /**
     * Attempts to load the file for the given fully-qualified class name.
     *
     * Called automatically by PHP's SPL stack. Returns without doing anything
     * when the class does not belong to the LoopPopupBridge namespace, allowing
     * other registered autoloaders to handle it.
     *
     * @param  string $class  Fully-qualified class name (e.g. "LoopPopupBridge\Plugin").
     * @return void
     */
    public static function load(string $class): void
    {
        if (!str_starts_with($class, self::NAMESPACE_PREFIX)) {
            return;
        }

        $relative = substr($class, strlen(self::NAMESPACE_PREFIX));
        $file     = self::$src_path . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }

    /**
     * Private constructor — this class is used statically only.
     */
    private function __construct() {}
}
