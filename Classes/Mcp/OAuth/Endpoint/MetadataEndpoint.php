<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OAuth 2.1 Authorization Server Metadata.
 * GET /.well-known/oauth-authorization-server.
 */
class MetadataEndpoint
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = rtrim(\is_string($host = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST')) ? $host : '', '/');

        return new JsonResponse([
            'issuer' => $baseUrl,
            'authorization_endpoint' => $baseUrl.'/aisuite-mcp/oauth/authorize',
            'token_endpoint' => $baseUrl.'/aisuite-mcp/oauth/token',
            'registration_endpoint' => $baseUrl.'/aisuite-mcp/oauth/register',
            'revocation_endpoint' => $baseUrl.'/aisuite-mcp/oauth/revoke',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [
                'mcp:read',
                'mcp:write',
                'mcp:generate',
                'mcp:translate',
                'mcp:image',
                'mcp:media',
                'mcp:workflow',
            ],
        ]);
    }
}
