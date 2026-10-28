<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\SingletonInterface;

class McpSessionStoreService implements SingletonInterface
{
    public const WARN_THRESHOLD = 500;

    private const RETENTION_FACTOR = 2;

    private const RETENTION_MIN_SECONDS = 3600;

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function getDirectory(): string
    {
        return Environment::getVarPath().'/aisuite_mcp_sessions/';
    }

    public function isWritable(): bool
    {
        $path = $this->getDirectory();

        return is_dir($path) && is_writable($path);
    }

    public function countFiles(): int
    {
        if (!is_dir($this->getDirectory())) {
            return 0;
        }

        $files = glob($this->getDirectory().'session-*.json');

        return false === $files ? 0 : count($files);
    }

    public function getRetentionSeconds(): int
    {
        try {
            $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        } catch (\Throwable) {
            $extConf = [];
        }

        $timeout = (int) (is_array($extConf) ? $extConf['mcpSessionTimeoutSeconds'] ?? 0 : 0);
        if ($timeout <= 0) {
            $timeout = 3600;
        }

        return max($timeout * self::RETENTION_FACTOR, self::RETENTION_MIN_SECONDS);
    }

    public function deleteExpiredSessions(): int
    {
        return $this->deleteFilesOlderThan($this->getDirectory(), $this->getRetentionSeconds());
    }

    public function deleteLeftoverTaskFiles(int $maxAgeSeconds): int
    {
        return $this->deleteFilesOlderThan(Environment::getVarPath().'/mcp_tasks/', $maxAgeSeconds);
    }

    private function deleteFilesOlderThan(string $path, int $maxAgeSeconds): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $deleted = 0;
        $maxAge = time() - $maxAgeSeconds;

        foreach (new \DirectoryIterator($path) as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            if ($file->getMTime() < $maxAge) {
                unlink($file->getPathname());
                ++$deleted;
            }
        }

        return $deleted;
    }
}
