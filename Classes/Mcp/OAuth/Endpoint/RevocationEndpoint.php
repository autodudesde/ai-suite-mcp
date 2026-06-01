<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use AutoDudes\AiSuiteMcp\Mcp\Service\ClientIpService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\Response;

/**
 * OAuth 2.1 Token Revocation Endpoint (S18).
 * POST /aisuite-mcp/oauth/revoke.
 */
class RevocationEndpoint
{
    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly ClientIpService $clientIpService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $token = (string) ($body['token'] ?? '');

        if ('' !== $token) {
            try {
                $this->oauthService->revokeToken($token);

                $this->logger->info('MCP token revoked', [
                    'ip' => $this->clientIpService->resolve($request),
                ]);
            } catch (\Throwable $e) {
                $this->logger->notice('Token revocation for unknown/invalid token', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Always 200 — RFC 7009 Section 2.2
        return new Response('php://temp', 200);
    }
}
