<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

class ProtectedResourceMetadataEndpoint
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = CanonicalResource::host($request);

        return new JsonResponse([
            'resource' => CanonicalResource::get($request),
            'authorization_servers' => [$baseUrl],
            'scopes_supported' => $this->permissionService->supportedScopes(),
            'bearer_methods_supported' => ['header'],
        ]);
    }
}
