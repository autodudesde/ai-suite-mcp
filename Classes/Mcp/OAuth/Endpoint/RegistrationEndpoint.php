<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * Dynamic Client Registration (RFC 7591).
 * POST /aisuite-mcp/oauth/register.
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

        $clientId = 'mcp-'.bin2hex(random_bytes(16));

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
