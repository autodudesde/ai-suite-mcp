<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Middleware;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\RateLimitPolicy;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RateLimitExceededException;
use AutoDudes\AiSuiteMcp\Mcp\Http\AiSuiteMcpEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\Http\BackendLinkEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\Http\HealthCheckEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\AuthorizationEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\MetadataEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\ProtectedResourceMetadataEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\RegistrationEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\RevocationEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\TokenEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\Service\ClientIpService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RateLimiterService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;

class McpServerMiddleware implements MiddlewareInterface
{
    private const MEGABYTE = 1_048_576;
    private const MCP_PATH = '/aisuite-mcp';
    private const WELL_KNOWN_PATH = '/.well-known/oauth-authorization-server';

    /**
     * RFC 8414 §5.
     */
    private const OPENID_CONFIGURATION_PATH = '/.well-known/openid-configuration';
    private const PROTECTED_RESOURCE_PATH = '/.well-known/oauth-protected-resource';

    public function __construct(
        private readonly AiSuiteMcpEndpoint $mcpEndpoint,
        private readonly HealthCheckEndpoint $healthCheckEndpoint,
        private readonly MetadataEndpoint $metadataEndpoint,
        private readonly RevocationEndpoint $revocationEndpoint,
        private readonly TokenEndpoint $tokenEndpoint,
        private readonly AuthorizationEndpoint $authorizationEndpoint,
        private readonly ProtectedResourceMetadataEndpoint $protectedResourceMetadataEndpoint,
        private readonly RegistrationEndpoint $registrationEndpoint,
        private readonly BackendLinkEndpoint $backendLinkEndpoint,
        private readonly RateLimiterService $rateLimiter,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ClientIpService $clientIpService,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (BackendLinkEndpoint::PATH === $path || BackendLinkEndpoint::SCRIPT_PATH === $path) {
            return ($this->backendLinkEndpoint)($request);
        }

        if (!str_starts_with($path, self::MCP_PATH) && !$this->isWellKnownPath($path)) {
            return $handler->handle($request);
        }

        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');

        if (!((bool) ($extConf['enableMcp'] ?? false))) {
            return new JsonResponse([
                'error' => 'mcp_disabled',
                'error_description' => 'The MCP feature is currently disabled. Enable it in the AI Suite extension settings.',
            ], 404);
        }

        if ('OPTIONS' === $request->getMethod()) {
            return $this->addCorsHeaders(
                new Response('php://temp', 204),
                $request->getHeaderLine('Origin'),
                $extConf,
            );
        }

        $originCheck = $this->enforceOriginValidation($request, $extConf);
        if (null !== $originCheck) {
            return $originCheck;
        }

        $httpsCheck = $this->enforceHttps($request, $extConf);
        if (null !== $httpsCheck) {
            return $httpsCheck;
        }

        $sizeCheck = $this->enforceBodySizeLimit($request, $extConf);
        if (null !== $sizeCheck) {
            return $sizeCheck;
        }

        if (self::MCP_PATH === $path || $path === self::MCP_PATH.'/') {
            $rateCheck = $this->enforceRateLimit($request, $extConf);
            if (null !== $rateCheck) {
                return $rateCheck;
            }
        }

        if (\in_array($path, [self::MCP_PATH.'/oauth/token', self::MCP_PATH.'/oauth/register', self::MCP_PATH.'/oauth/revoke'], true)) {
            $rateCheck = $this->enforceOAuthRateLimit($request);
            if (null !== $rateCheck) {
                return $rateCheck;
            }
        }

        $response = $this->withSecurityHeaders($this->route($path, $request), $path);

        return $this->addCorsHeaders($response, $request->getHeaderLine('Origin'), $extConf);
    }

    private function isWellKnownPath(string $path): bool
    {
        return \in_array(
            $path,
            [self::WELL_KNOWN_PATH, self::OPENID_CONFIGURATION_PATH, self::PROTECTED_RESOURCE_PATH],
            true,
        );
    }

    private function route(string $path, ServerRequestInterface $request): ResponseInterface
    {
        if ($path === self::MCP_PATH.'/health') {
            return ($this->healthCheckEndpoint)($request);
        }

        if (self::WELL_KNOWN_PATH === $path || self::OPENID_CONFIGURATION_PATH === $path) {
            return ($this->metadataEndpoint)($request);
        }

        if (self::PROTECTED_RESOURCE_PATH === $path) {
            return ($this->protectedResourceMetadataEndpoint)($request);
        }

        if ($path === self::MCP_PATH.'/oauth/register') {
            return ($this->registrationEndpoint)($request);
        }

        if ($path === self::MCP_PATH.'/oauth/revoke') {
            return ($this->revocationEndpoint)($request);
        }

        if ($path === self::MCP_PATH.'/oauth/token') {
            return ($this->tokenEndpoint)($request);
        }

        if ($path === self::MCP_PATH.'/oauth/authorize') {
            return ($this->authorizationEndpoint)($request);
        }

        if (str_starts_with($path, self::MCP_PATH.'/oauth/')) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        if (self::MCP_PATH === $path || $path === self::MCP_PATH.'/') {
            return ($this->mcpEndpoint)($request);
        }

        return new JsonResponse(['error' => 'not_found'], 404);
    }

    /**
     * @param array<string, mixed> $extConf
     */
    private function enforceHttps(ServerRequestInterface $request, array $extConf): ?ResponseInterface
    {
        $isHttps = 'https' === $request->getUri()->getScheme()
            || ('https' === $request->getHeaderLine('X-Forwarded-Proto') && $this->clientIpService->isFromTrustedProxy($request));

        if (!$isHttps && !$this->isLocalDevelopmentHost($request->getUri()->getHost()) && !((bool) ($extConf['mcpAllowHttp'] ?? false))) {
            return new JsonResponse([
                'error' => 'https_required',
                'error_description' => 'MCP endpoint requires HTTPS. Please use a secure connection.',
            ], 400, [
                'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
            ]);
        }

        return null;
    }

    /**
     * Spec (MCP 2025-11-25, Transports §Security Warning).
     *
     * @param array<string, mixed> $extConf
     */
    private function enforceOriginValidation(ServerRequestInterface $request, array $extConf): ?ResponseInterface
    {
        $origin = trim($request->getHeaderLine('Origin'));

        // No Origin header → not a browser request → no DNS-rebinding risk.
        if ('' === $origin) {
            return null;
        }

        $originHost = (string) (parse_url($origin, PHP_URL_HOST) ?: '');

        if ($this->isLocalDevelopmentHost($originHost)) {
            return null;
        }

        if ($originHost === $request->getUri()->getHost()) {
            return null;
        }

        if (Environment::getContext()->isDevelopment()) {
            $this->logger->warning('MCP middleware accepted cross-origin request (Development context bypass)', [
                'origin' => $origin,
                'path' => $request->getUri()->getPath(),
            ]);

            return null;
        }

        $allowedOrigins = array_filter(
            array_map('trim', explode(',', (string) ($extConf['mcpAllowedOrigins'] ?? ''))),
        );

        if (in_array($origin, $allowedOrigins, true)) {
            return null;
        }

        $this->logger->warning('MCP middleware rejected request: Origin not in allowlist', [
            'origin' => $origin,
            'path' => $request->getUri()->getPath(),
            'allowlist_size' => count($allowedOrigins),
        ]);

        return new JsonResponse([
            'error' => 'forbidden_origin',
            'error_description' => sprintf(
                "Origin '%s' is not permitted. Add it to ext_conf 'mcpAllowedOrigins' if this connector should be trusted.",
                $origin,
            ),
        ], 403);
    }

    private function isLocalDevelopmentHost(string $host): bool
    {
        if (Environment::getContext()->isProduction()) {
            return false;
        }

        return in_array($host, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($host, '.ddev.site');
    }

    private function withSecurityHeaders(ResponseInterface $response, string $path): ResponseInterface
    {
        $response = $response
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-Content-Type-Options', 'nosniff')
        ;
        if (!$response->hasHeader('Content-Security-Policy')) {
            $response = $response->withHeader('Content-Security-Policy', "frame-ancestors 'none'");
        }
        if (str_starts_with($path, self::MCP_PATH.'/oauth/') && !$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-store')->withHeader('Pragma', 'no-cache');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $extConf
     */
    private function enforceBodySizeLimit(ServerRequestInterface $request, array $extConf): ?ResponseInterface
    {
        $limitMb = max(1, (int) ($extConf['mcpMaxRequestBodyMb'] ?? 1));
        $declared = (int) $request->getHeaderLine('Content-Length');
        $actual = $this->bodySize($request);

        if (max($declared, $actual) > $limitMb * self::MEGABYTE) {
            return new JsonResponse([
                'error' => 'request_too_large',
                'error_description' => sprintf(
                    'Request body exceeds maximum size of %d MB. Raise ext_conf \'mcpMaxRequestBodyMb\' to accept larger uploads.',
                    $limitMb,
                ),
            ], 413);
        }

        return null;
    }

    private function bodySize(ServerRequestInterface $request): int
    {
        try {
            // Stream::getSize() warns on a failing fstat() before TYPO3 v13
            return @$request->getBody()->getSize() ?? 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function enforceOAuthRateLimit(ServerRequestInterface $request): ?ResponseInterface
    {
        $ip = $this->clientIpService->resolve($request);
        $path = $request->getUri()->getPath();
        $bucket = match (true) {
            str_ends_with($path, '/register') => 'oauth_register_',
            str_ends_with($path, '/revoke') => 'oauth_revoke_',
            default => 'oauth_token_',
        };

        try {
            $this->rateLimiter->checkAndIncrement($bucket.$ip, RateLimitPolicy::oauthEndpoint());
        } catch (RateLimitExceededException $e) {
            $this->logger->warning('OAuth endpoint rate limit exceeded', [
                'ip' => $ip,
                'path' => $path,
                'reason' => $e->getMessage(),
            ]);

            return new JsonResponse([
                'error' => 'rate_limit_exceeded',
                'error_description' => $e->getMessage(),
            ], 429, [
                'Retry-After' => (string) $e->retryAfter,
            ]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extConf
     */
    private function enforceRateLimit(ServerRequestInterface $request, array $extConf): ?ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ('' !== $authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $tokenPrefix = substr($authHeader, 7, 16);
            $identifier = 'mcp_'.hash('sha256', $tokenPrefix);

            try {
                $this->rateLimiter->checkAndIncrement($identifier, RateLimitPolicy::forBearerToken($extConf));
            } catch (RateLimitExceededException $e) {
                $this->logger->warning('MCP rate limit exceeded for bearer token', [
                    'identifier' => $identifier,
                    'path' => $request->getUri()->getPath(),
                    'reason' => $e->getMessage(),
                ]);

                return new JsonResponse([
                    'error' => 'rate_limit_exceeded',
                    'error_description' => $e->getMessage().' An administrator can raise or disable this limit with ext_conf \'mcpRateLimitRequests\'.',
                ], 429, [
                    'Retry-After' => (string) $e->retryAfter,
                ]);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $extConf
     */
    private function addCorsHeaders(ResponseInterface $response, string $origin, array $extConf): ResponseInterface
    {
        $allowedOrigins = array_filter(
            array_map('trim', explode(',', (string) ($extConf['mcpAllowedOrigins'] ?? ''))),
        );

        if (!Environment::getContext()->isDevelopment()) {
            if (empty($allowedOrigins) && Environment::getContext()->isProduction()) {
                return $response;
            }
            if (!empty($allowedOrigins) && '' !== $origin && !in_array($origin, $allowedOrigins, true)) {
                return $response;
            }
        }

        $effectiveOrigin = $origin ?: '*';

        return $response
            ->withHeader('Access-Control-Allow-Origin', $effectiveOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'POST, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, MCP-Protocol-Version, Mcp-Session-Id, Mcp-Method, Mcp-Name')
            ->withHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id')
            ->withHeader('Access-Control-Max-Age', '86400')
        ;
    }
}
