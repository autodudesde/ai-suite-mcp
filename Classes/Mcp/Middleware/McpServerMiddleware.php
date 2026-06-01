<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Middleware;

use AutoDudes\AiSuiteMcp\Mcp\Http\AiSuiteMcpEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\Http\HealthCheckEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\AuthorizationEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\MetadataEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\ProtectedResourceMetadataEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\RegistrationEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\RevocationEndpoint;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Endpoint\TokenEndpoint;
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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

/**
 *   /aisuite-mcp                             → AiSuiteMcpEndpoint (MCP protocol)
 *   /aisuite-mcp/oauth/register              → RegistrationEndpoint (RFC 7591)
 *   /aisuite-mcp/oauth/authorize             → AuthorizationEndpoint
 *   /aisuite-mcp/oauth/token                 → TokenEndpoint
 *   /aisuite-mcp/oauth/revoke                → RevocationEndpoint
 *   /.well-known/oauth-authorization-server  → MetadataEndpoint
 *   /.well-known/oauth-protected-resource    → ProtectedResourceMetadataEndpoint (RFC 9728)
 *   /aisuite-mcp/health                      → HealthCheckEndpoint.
 */
class McpServerMiddleware implements MiddlewareInterface
{
    private const MAX_REQUEST_BODY_SIZE = 1_048_576; // 1 MB
    private const MCP_PATH = '/aisuite-mcp';
    private const WELL_KNOWN_PATH = '/.well-known/oauth-authorization-server';
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
        private readonly RateLimiterService $rateLimiter,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ('/favicon.ico' === $path) {
            return $this->serveFavicon();
        }

        if (!str_starts_with($path, self::MCP_PATH) && self::WELL_KNOWN_PATH !== $path && self::PROTECTED_RESOURCE_PATH !== $path) {
            return $handler->handle($request);
        }

        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');

        if (!((bool) ($extConf['enableMcp'] ?? false))) {
            return new JsonResponse([
                'error' => 'mcp_disabled',
                'error_description' => 'The MCP feature is currently disabled. Enable it in the AI Suite extension settings.',
            ], 404);
        }

        // OPTIONS preflight (CORS)
        if ('OPTIONS' === $request->getMethod()) {
            return $this->addCorsHeaders(
                new Response('php://temp', 204),
                $request->getHeaderLine('Origin'),
                $extConf,
            );
        }

        // Origin validation (MCP 2025-11-25 spec: DNS-rebinding protection)
        $originCheck = $this->enforceOriginValidation($request, $extConf);
        if (null !== $originCheck) {
            return $originCheck;
        }

        // HTTPS enforcement
        $httpsCheck = $this->enforceHttps($request, $extConf);
        if (null !== $httpsCheck) {
            return $httpsCheck;
        }

        // Request body size limit
        $sizeCheck = $this->enforceBodySizeLimit($request);
        if (null !== $sizeCheck) {
            return $sizeCheck;
        }

        // Rate limiting for MCP endpoint, not for health/OAuth
        if (self::MCP_PATH === $path || $path === self::MCP_PATH.'/') {
            $rateCheck = $this->enforceRateLimit($request);
            if (null !== $rateCheck) {
                return $rateCheck;
            }
        }

        // Route to handler
        $response = $this->route($path, $request);

        // Add CORS headers to response
        return $this->addCorsHeaders($response, $request->getHeaderLine('Origin'), $extConf);
    }

    private function route(string $path, ServerRequestInterface $request): ResponseInterface
    {
        // Health check (no auth required)
        if ($path === self::MCP_PATH.'/health') {
            return ($this->healthCheckEndpoint)($request);
        }

        // Well-known OAuth metadata
        if (self::WELL_KNOWN_PATH === $path) {
            return ($this->metadataEndpoint)($request);
        }

        // Protected Resource Metadata (RFC 9728)
        if (self::PROTECTED_RESOURCE_PATH === $path) {
            return ($this->protectedResourceMetadataEndpoint)($request);
        }

        // OAuth: Dynamic Client Registration (RFC 7591)
        if ($path === self::MCP_PATH.'/oauth/register') {
            return ($this->registrationEndpoint)($request);
        }

        // OAuth: Token revocation
        if ($path === self::MCP_PATH.'/oauth/revoke') {
            return ($this->revocationEndpoint)($request);
        }

        // OAuth: Token endpoint
        if ($path === self::MCP_PATH.'/oauth/token') {
            return ($this->tokenEndpoint)($request);
        }

        // OAuth: Authorization endpoint
        if ($path === self::MCP_PATH.'/oauth/authorize') {
            return ($this->authorizationEndpoint)($request);
        }

        // Unknown OAuth endpoint
        if (str_starts_with($path, self::MCP_PATH.'/oauth/')) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        // Main MCP endpoint
        if (self::MCP_PATH === $path || $path === self::MCP_PATH.'/') {
            return ($this->mcpEndpoint)($request);
        }

        return new JsonResponse(['error' => 'not_found'], 404);
    }

    private function serveFavicon(): ResponseInterface
    {
        $svgPath = ExtensionManagementUtility::extPath('ai_suite', 'Resources/Public/Icons/Extension.svg');
        if (!file_exists($svgPath)) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }

        $response = new Response('php://temp', 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'public, max-age=86400',
        ]);
        $response->getBody()->write((string) file_get_contents($svgPath));

        return $response;
    }

    /**
     * @param array<string, mixed> $extConf
     */
    private function enforceHttps(ServerRequestInterface $request, array $extConf): ?ResponseInterface
    {
        $host = $request->getUri()->getHost();
        $isLocalhost = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        $isDdev = str_ends_with($host, '.ddev.site');
        $isHttps = 'https' === $request->getUri()->getScheme()
            || 'https' === $request->getHeaderLine('X-Forwarded-Proto');

        if (!$isHttps && !$isLocalhost && !$isDdev && !((bool) ($extConf['mcpAllowHttp'] ?? false))) {
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

        // Local development hosts always accepted (parity with HTTPS exemption).
        if (in_array($originHost, ['localhost', '127.0.0.1', '[::1]'], true) || str_ends_with($originHost, '.ddev.site')) {
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

    private function enforceBodySizeLimit(ServerRequestInterface $request): ?ResponseInterface
    {
        $contentLength = (int) $request->getHeaderLine('Content-Length');

        if ($contentLength > self::MAX_REQUEST_BODY_SIZE) {
            return new JsonResponse([
                'error' => 'request_too_large',
                'error_description' => 'Request body exceeds maximum size of 1 MB.',
            ], 413);
        }

        return null;
    }

    private function enforceRateLimit(ServerRequestInterface $request): ?ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ('' !== $authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $tokenPrefix = substr($authHeader, 7, 16); // First 16 chars for rate limit key
            $identifier = 'mcp_'.hash('sha256', $tokenPrefix);

            try {
                $this->rateLimiter->checkAndIncrement($identifier);
            } catch (\RuntimeException $e) {
                $this->logger->warning('MCP rate limit exceeded for bearer token', [
                    'identifier' => $identifier,
                    'path' => $request->getUri()->getPath(),
                    'reason' => $e->getMessage(),
                ]);

                return new JsonResponse([
                    'error' => 'rate_limit_exceeded',
                    'error_description' => $e->getMessage(),
                ], 429, [
                    'Retry-After' => '60',
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
            ->withHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->withHeader('Access-Control-Max-Age', '86400')
        ;
    }
}
