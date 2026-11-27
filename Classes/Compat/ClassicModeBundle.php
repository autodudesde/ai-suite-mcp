<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Compat;

use Composer\Autoload\ClassLoader;
use TYPO3\CMS\Core\Core\Environment;

final class ClassicModeBundle
{
    /**
     * @var array<string, true>
     */
    private static array $registered = [];

    public static function register(string $extensionDirectory): void
    {
        if (Environment::isComposerMode()) {
            return;
        }

        $vendorDirectory = rtrim($extensionDirectory, '/').'/Resources/Private/PHP/ComposerVendor';
        $composerDirectory = $vendorDirectory.'/composer';
        if (isset(self::$registered[$vendorDirectory]) || !is_file($composerDirectory.'/autoload_psr4.php')) {
            return;
        }
        self::$registered[$vendorDirectory] = true;

        if (!class_exists(ClassLoader::class)) {
            require_once $composerDirectory.'/ClassLoader.php';
        }

        $loader = new ClassLoader($vendorDirectory);
        foreach (self::mapOf($composerDirectory.'/autoload_namespaces.php') as $prefix => $paths) {
            $loader->set($prefix, $paths);
        }
        foreach (self::mapOf($composerDirectory.'/autoload_psr4.php') as $prefix => $paths) {
            $loader->setPsr4($prefix, $paths);
        }
        $loader->addClassMap(self::mapOf($composerDirectory.'/autoload_classmap.php'));
        // Appended, not prepended: a copy of a bundled package that another extension already loads must win
        $loader->register(false);

        foreach (self::mapOf($composerDirectory.'/autoload_files.php') as $identifier => $file) {
            if (empty($GLOBALS['__composer_autoload_files'][$identifier])) {
                $GLOBALS['__composer_autoload_files'][$identifier] = true;

                require $file;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function mapOf(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $map = require $file;

        return is_array($map) ? $map : [];
    }
}
