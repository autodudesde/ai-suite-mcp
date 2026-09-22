<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Http\BackendLinkEndpoint;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class BackendLinkBounceService
{
    private const HMAC_SECRET = 'ai_suite_mcp_backend_link';

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function wrap(string $url): string
    {
        if (!$this->isEnabled()) {
            return $url;
        }

        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $target = ($parts['path'] ?? '/').(isset($parts['query']) && '' !== $parts['query'] ? '?'.$parts['query'] : '');
        if (!$this->isBackendPath($target)) {
            return $url;
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.BackendLinkEndpoint::PATH.'?'.http_build_query(
            ['target' => $target, 'signature' => $this->hmac($target)],
            '',
            '&',
            PHP_QUERY_RFC3986,
        );
    }

    public function resolveTarget(string $target, string $signature): ?string
    {
        if ('' === $signature || !$this->isBackendPath($target)) {
            return null;
        }

        return hash_equals($this->hmac($target), $signature) ? $target : null;
    }

    private function hmac(string $input): string
    {
        $encryptionKey = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';

        return hash_hmac('sha1', $input, (\is_string($encryptionKey) ? $encryptionKey : '').self::HMAC_SECRET);
    }

    private function isBackendPath(string $target): bool
    {
        return str_starts_with($target, '/')
            && !str_starts_with($target, '//')
            && 1 !== preg_match('/[\\\\\x00-\x20\x7f]/', $target);
    }

    private function isEnabled(): bool
    {
        try {
            $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return true;
        }

        return !\is_array($extConf) || (bool) ($extConf['mcpBackendLinkBounce'] ?? true);
    }
}
