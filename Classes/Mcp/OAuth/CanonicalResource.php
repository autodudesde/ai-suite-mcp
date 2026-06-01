<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Canonical resource URI of this MCP server, per RFC 8707 / MCP 2025-11-25.
 *
 * The same URI is published in three places that must agree:
 *   - ProtectedResourceMetadataEndpoint  (`resource` field)
 *   - AuthorizationEndpoint              (`resource` request parameter)
 *   - OAuthService::validateToken        (audience check on every request)
 */
final class CanonicalResource
{
    private const PATH = '/aisuite-mcp';

    public static function get(): string
    {
        $host = '';
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface) {
            $normalizedParams = $request->getAttribute('normalizedParams');
            if ($normalizedParams instanceof NormalizedParams) {
                $host = $normalizedParams->getRequestHost();
            }
        }
        if ('' === $host) {
            $serverHost = $_SERVER['HTTP_HOST'] ?? '';
            if (\is_string($serverHost) && '' !== $serverHost) {
                $scheme = (!empty($_SERVER['HTTPS']) && 'off' !== $_SERVER['HTTPS']) ? 'https' : 'http';
                $host = $scheme.'://'.$serverHost;
            }
        }

        return rtrim($host, '/').self::PATH;
    }

    public static function matches(string $candidate): bool
    {
        $expected = self::normalize(self::get());
        $actual = self::normalize($candidate);

        return '' !== $actual && $expected === $actual;
    }

    private static function normalize(string $uri): string
    {
        $parsed = parse_url($uri);
        if (false === $parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return '';
        }

        $scheme = strtolower($parsed['scheme']);
        $host = strtolower($parsed['host']);
        $port = isset($parsed['port']) ? ':'.$parsed['port'] : '';
        $path = rtrim($parsed['path'] ?? '', '/');

        return $scheme.'://'.$host.$port.$path;
    }
}
