<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Dynamic Client Registration (RFC 7591).
 * POST /aisuite-mcp/oauth/register.
 *
 * MCP clients (Claude.ai, ChatGPT, etc.) call this endpoint to obtain
 * a client_id before starting the OAuth 2.1 authorization flow.
 * Since MCP uses public clients with PKCE, no client_secret is issued.
 */
class RegistrationEndpoint
{
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ('POST' !== $request->getMethod()) {
            return new JsonResponse(['error' => 'method_not_allowed'], 405);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];

        $clientName = (string) ($body['client_name'] ?? 'MCP Client');
        $redirectUris = (array) ($body['redirect_uris'] ?? []);

        // Generate a unique client_id
        $clientId = 'mcp-'.bin2hex(random_bytes(16));

        // MCP uses public clients with PKCE — no client_secret
        $response = [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => $redirectUris,
            'token_endpoint_auth_method' => 'none',
            'grant_types' => ['authorization_code'],
            'response_types' => ['code'],
        ];

        return new JsonResponse($response, 201);
    }
}
