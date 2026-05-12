<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Protected Resource Metadata (RFC 9728).
 * GET /.well-known/oauth-protected-resource.
 *
 * Tells MCP clients which authorization server protects this resource
 * and which scopes are available. This is the entry point for the
 * OAuth 2.1 discovery flow used by Claude.ai Connectors and other
 * external MCP clients.
 */
class ProtectedResourceMetadataEndpoint
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = rtrim(\is_string($host = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST')) ? $host : '', '/');

        return new JsonResponse([
            'resource' => CanonicalResource::get(),
            'authorization_servers' => [$baseUrl],
            'scopes_supported' => [
                'mcp:read',
                'mcp:write',
                'mcp:generate',
                'mcp:translate',
                'mcp:image',
                'mcp:workflow',
                'mcp:easy-language',
                'mcp:glossary',
                'mcp:manage',
            ],
            'bearer_methods_supported' => ['header'],
        ]);
    }
}
