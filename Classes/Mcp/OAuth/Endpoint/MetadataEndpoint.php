<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

class MetadataEndpoint
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = CanonicalResource::host($request);

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
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => $this->permissionService->supportedScopes(),
        ]);
    }
}
