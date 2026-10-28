<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class RedirectUriPolicy
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function isLocalhost(string $redirectUri): bool
    {
        $host = (string) (parse_url($redirectUri, PHP_URL_HOST) ?? '');

        return in_array(strtolower($host), self::LOCAL_HOSTS, true);
    }

    public function isAllowed(string $redirectUri): bool
    {
        $uri = $this->parse($redirectUri);
        if (null === $uri) {
            return false;
        }
        if ($this->isLocalhost($redirectUri)) {
            return true;
        }
        if ('https' !== $uri['scheme']) {
            return false;
        }

        foreach ($this->allowList() as $entry) {
            $allowed = $this->parse($entry);
            if (null !== $allowed && $this->matches($uri, $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function host(string $redirectUri): string
    {
        return (string) (parse_url($redirectUri, PHP_URL_HOST) ?? '');
    }

    /**
     * @return list<string>
     */
    private function allowList(): array
    {
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');

        return array_values(array_filter(array_map('trim', explode(',', (string) ($extConf['mcpAllowedRedirectUris'] ?? '')))));
    }

    /**
     * @return null|array{scheme: string, host: string, port: int, path: string, query: string}
     */
    private function parse(string $uri): ?array
    {
        $parts = parse_url(trim($uri));
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return [
            'scheme' => $scheme,
            'host' => strtolower($parts['host']),
            'port' => (int) ($parts['port'] ?? ('https' === $scheme ? 443 : 80)),
            'path' => (string) ($parts['path'] ?? ''),
            'query' => (string) ($parts['query'] ?? ''),
        ];
    }

    /**
     * @param array{scheme: string, host: string, port: int, path: string, query: string} $uri
     * @param array{scheme: string, host: string, port: int, path: string, query: string} $allowed
     */
    private function matches(array $uri, array $allowed): bool
    {
        if ($uri['scheme'] !== $allowed['scheme'] || $uri['host'] !== $allowed['host'] || $uri['port'] !== $allowed['port']) {
            return false;
        }
        if ('' !== $allowed['query']) {
            return $uri['path'] === $allowed['path'] && $uri['query'] === $allowed['query'];
        }

        $base = rtrim($allowed['path'], '/');

        return '' === $base || $uri['path'] === $base || str_starts_with($uri['path'], $base.'/');
    }
}
