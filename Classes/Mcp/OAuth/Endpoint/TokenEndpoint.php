<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint;

use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidGrantException;
use AutoDudes\AiSuiteMcp\Mcp\Service\ClientIpService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RateLimiterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * OAuth 2.1 Token Endpoint.
 * POST /aisuite-mcp/oauth/token.
 *
 * Supports:
 * - grant_type=authorization_code (code + PKCE verifier → access_token + refresh_token)
 * - grant_type=refresh_token (refresh_token → new access_token + new refresh_token)
 */
class TokenEndpoint
{
    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly RateLimiterService $rateLimiter,
        private readonly ClientIpService $clientIpService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if ('POST' !== $request->getMethod()) {
            return new JsonResponse(['error' => 'invalid_request', 'error_description' => 'POST method required.'], 405);
        }

        /** @var array<string, mixed> $body */
        $body = (array) ($request->getParsedBody() ?? []);
        $clientId = (string) ($body['client_id'] ?? '');
        $ip = $this->clientIpService->resolve($request);

        try {
            $this->rateLimiter->checkAndIncrement('token_'.$clientId.'_'.$ip);
        } catch (\RuntimeException $e) {
            $this->logger->warning('OAuth token endpoint rate limit exceeded', [
                'client_id' => $clientId,
                'ip' => $ip,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'rate_limit_exceeded',
                'error_description' => $e->getMessage(),
            ], 429, ['Retry-After' => '60']);
        }

        $grantType = (string) ($body['grant_type'] ?? '');

        try {
            return match ($grantType) {
                'authorization_code' => $this->handleAuthorizationCode($body),
                'refresh_token' => $this->handleRefreshToken($body),
                default => new JsonResponse([
                    'error' => 'unsupported_grant_type',
                    'error_description' => 'Supported grant types: authorization_code, refresh_token.',
                ], 400),
            };
        } catch (InvalidGrantException $e) {
            $this->logger->info('Token request failed', [
                'grant_type' => $grantType,
                'client_id' => $clientId,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'invalid_grant',
                'error_description' => $e->getMessage(),
            ], 400);
        } catch (\Throwable $e) {
            $this->logger->error('Token endpoint error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'server_error'], 500);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handleAuthorizationCode(array $body): ResponseInterface
    {
        $code = (string) ($body['code'] ?? '');
        $codeVerifier = (string) ($body['code_verifier'] ?? '');
        $clientId = (string) ($body['client_id'] ?? '');
        $redirectUri = (string) ($body['redirect_uri'] ?? '');
        $resource = (string) ($body['resource'] ?? '');

        if ('' === $code || '' === $codeVerifier || '' === $clientId || '' === $redirectUri) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'Required parameters: code, code_verifier, client_id, redirect_uri.',
            ], 400);
        }

        // resource is required at the token endpoint per RFC 8707 / MCP 2025-11-25.
        if ('' === $resource) {
            return new JsonResponse([
                'error' => 'invalid_target',
                'error_description' => 'resource parameter is required (RFC 8707).',
            ], 400);
        }

        $tokenResult = $this->oauthService->exchangeCodeForToken($code, $codeVerifier, $clientId, $redirectUri, $resource);

        $this->logger->info('Token issued via authorization_code', [
            'client_id' => $clientId,
            'audience' => $resource,
        ]);

        return new JsonResponse($tokenResult);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function handleRefreshToken(array $body): ResponseInterface
    {
        $refreshToken = (string) ($body['refresh_token'] ?? '');
        $clientId = (string) ($body['client_id'] ?? '');

        $resource = (string) ($body['resource'] ?? '');

        if ('' === $refreshToken || '' === $clientId) {
            return new JsonResponse([
                'error' => 'invalid_request',
                'error_description' => 'Required parameters: refresh_token, client_id.',
            ], 400);
        }

        $tokenResult = $this->oauthService->refreshAccessToken($refreshToken, $clientId, $resource);

        $this->logger->info('Token refreshed', ['client_id' => $clientId]);

        return new JsonResponse($tokenResult);
    }
}
