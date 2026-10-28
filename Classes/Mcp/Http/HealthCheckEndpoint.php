<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Http;

use Mcp\Server\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\JsonResponse;

class HealthCheckEndpoint
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $ready = (bool) ($this->extensionConfiguration->get('ai_suite_mcp')['enableMcp'] ?? false) && class_exists(Server::class);

        return new JsonResponse(['status' => $ready ? 'ready' : 'not_configured']);
    }
}
