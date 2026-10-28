<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

final class CanonicalResource
{
    private const PATH = '/aisuite-mcp';

    public static function get(ServerRequestInterface $request): string
    {
        return self::host($request).self::PATH;
    }

    public static function host(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            $normalizedParams = NormalizedParams::createFromRequest($request);
        }

        return rtrim($normalizedParams->getRequestHost(), '/');
    }

    public static function matches(string $candidate, string $canonicalResource): bool
    {
        $expected = self::normalize($canonicalResource);
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
