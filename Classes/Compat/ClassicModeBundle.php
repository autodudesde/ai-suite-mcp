<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Compat;

use TYPO3\CMS\Core\Core\Environment;

final class ClassicModeBundle
{
    /**
     * @param class-string $probeClass
     */
    public static function register(string $extensionDirectory, string $probeClass): void
    {
        if (Environment::isComposerMode() || class_exists($probeClass)) {
            return;
        }

        // The Extension Manager regenerates ext_emconf.php from the TER t3x, which carries no autoload section
        $autoloader = rtrim($extensionDirectory, '/').'/Resources/Private/PHP/ComposerVendor/autoload.php';
        if (is_file($autoloader)) {
            require_once $autoloader;
        }
    }
}
