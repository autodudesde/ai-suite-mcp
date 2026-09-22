<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Http;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\IconService;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\TokenData;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\McpBackendUserInitializer;
use AutoDudes\AiSuiteMcp\Mcp\McpServerFactory;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidTokenException;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use AutoDudes\AiSuiteMcp\Mcp\Service\ServerInstructionsService;
use AutoDudes\AiSuiteMcp\Mcp\Service\SessionTrackerService;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpMessage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AiSuiteMcpEndpoint
{
    /**
     * Spec: https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http#protocol-version-header.
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private const LEGACY_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private const LATEST_LEGACY_PROTOCOL_VERSION = '2025-11-25';

    private const METHOD_INITIALIZE = 'initialize';

    private const METHOD_DISCOVER = 'server/discover';

    private const META_SERVER_INFO = 'io.modelcontextprotocol/serverInfo';

    private const META_PROTOCOL_VERSION = 'io.modelcontextprotocol/protocolVersion';

    private const MODERN_PROTOCOL_VERSIONS = ['2026-07-28'];

    private ?FileSessionStore $sessionStore = null;

    public function __construct(
        private readonly McpServerFactory $serverFactory,
        private readonly OAuthService $oauthService,
        private readonly McpUserContext $userContext,
        private readonly BackendUserService $backendUserService,
        private readonly McpBackendUserInitializer $backendUserInitializer,
        private readonly SessionTrackerService $creditTracker,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly ServerInstructionsService $serverInstructions,
        private readonly IconService $iconService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $rawToken = $this->extractBearerToken($request);
            $tokenData = $this->oauthService->validateToken($rawToken);

            $versionResponse = $this->validateProtocolVersionHeader($request);
            if (null !== $versionResponse) {
                return $versionResponse;
            }

            $this->validateBackendUserStatus($tokenData, $rawToken);

            $this->validateMcpAccessPermission($tokenData);

            $this->backendUserInitializer->initialize($tokenData->beUserUid, $tokenData->workspaceUid);

            $this->userContext->initialize(
                $tokenData->beUserUid,
                $tokenData->scopes,
                $tokenData->clientId,
                $tokenData->tokenId,
                $tokenData->issuedVersion,
            );
            $this->userContext->setServerRequest($request);

            $this->creditTracker->initializeFromToken($tokenData->tokenId, $this->maxCreditsPerSession());

            $rawBody = (string) $request->getBody();
            $payload = json_decode($rawBody);
            $payload = $payload instanceof \stdClass ? $payload : null;

            $isModern = $this->isModernRequest($request, $payload);
            $mcpSessionId = $isModern ? '' : trim($request->getHeaderLine('Mcp-Session-Id'));
            if ('' !== $mcpSessionId) {
                $this->userContext->setSessionKey('mcp:'.$mcpSessionId);
            }

            $httpMessage = new HttpMessage($rawBody);
            $httpMessage->setMethod($request->getMethod());
            $httpMessage->setUri((string) $request->getUri());
            $httpMessage->setQueryParams($request->getQueryParams());
            foreach ($request->getHeaders() as $name => $values) {
                $httpMessage->setHeader($name, implode(', ', $values));
            }

            if (!$isModern) {
                $this->mintSessionForStatelessClient($httpMessage, $request, $mcpSessionId, $payload);
            }

            $sdkResponse = $this->createRunner()->handleRequest($httpMessage);

            $body = $sdkResponse->getBody();
            if (null !== $body) {
                $body = $this->enrichServerIdentity($body, $this->rpcMethod($payload));
            }

            $this->logger->info('MCP endpoint response', [
                'status' => $sdkResponse->getStatusCode(),
                'body' => substr((string) $body, 0, 500),
                'headers' => $sdkResponse->getHeaders(),
                'request_method' => $request->getMethod(),
                'request' => $this->describeRequest($payload, $rawBody),
            ]);

            $response = new Response('php://temp', $sdkResponse->getStatusCode());
            foreach ($sdkResponse->getHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            if (null !== $body) {
                $response->getBody()->write($body);
            }

            return $response;
        } catch (InvalidTokenException $e) {
            $hasAuthHeader = '' !== $this->resolveAuthorizationHeader($request);
            $this->logger->log($hasAuthHeader ? LogLevel::WARNING : LogLevel::INFO, 'MCP endpoint rejected request: invalid token', [
                'reason' => $e->getMessage(),
                'path' => $request->getUri()->getPath(),
                'has_auth_header' => $hasAuthHeader,
            ]);

            return new JsonResponse([
                'error' => 'unauthorized',
                'error_description' => 'Bearer token required. Use the OAuth 2.1 flow to obtain a token.',
            ], 401, [
                'WWW-Authenticate' => $this->bearerChallenge(),
            ]);
        } catch (InsufficientPermissionException $e) {
            $this->logger->warning('MCP endpoint denied request: insufficient permission', [
                'reason' => $e->getMessage(),
                'be_user_uid' => $tokenData->beUserUid,
                'client_id' => $tokenData->clientId,
            ]);

            return new JsonResponse(['error' => 'access_denied', 'error_description' => $e->getMessage()], 403, [
                'WWW-Authenticate' => $this->bearerChallenge('insufficient_scope'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->critical('MCP endpoint error', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['error' => 'server_error'], 500);
        }
    }

    private function createRunner(): HttpServerRunner
    {
        $server = $this->serverFactory->createServer();

        $initOptions = $server->createInitializationOptions();

        // Stateless HTTP only: SSE would pin one PHP-FPM worker per client.
        $httpOptions = [
            'auto_detect' => false,
            'enable_sse' => false,
            'shared_hosting' => true,
            'session_timeout' => $this->sessionTimeout(),
        ];

        return new HttpServerRunner(
            $server,
            $initOptions,
            $httpOptions,
            null,
            $this->sessionStore(),
        );
    }

    private function sessionStore(): FileSessionStore
    {
        if (null === $this->sessionStore) {
            $sessionPath = Environment::getVarPath().'/aisuite_mcp_sessions/';
            if (!is_dir($sessionPath)) {
                GeneralUtility::mkdir_deep($sessionPath);
            }

            $this->sessionStore = new FileSessionStore($sessionPath);
        }

        return $this->sessionStore;
    }

    private function sessionTimeout(): int
    {
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $sessionTimeout = (int) ($extConf['mcpSessionTimeoutSeconds'] ?? 1800);

        return $sessionTimeout > 0 ? $sessionTimeout : 3600;
    }

    private function mintSessionForStatelessClient(
        HttpMessage $httpMessage,
        ServerRequestInterface $request,
        string $mcpSessionId,
        ?\stdClass $payload,
    ): void {
        if ('POST' !== $request->getMethod()) {
            return;
        }

        // `initialize` is the one request that is allowed to create a session itself.
        if ('initialize' === $this->rpcMethod($payload)) {
            return;
        }

        if ('' !== $mcpSessionId && !$this->needsSessionRecovery($mcpSessionId, $payload)) {
            return;
        }

        $sessionId = $this->establishSessionId($request);
        if (null === $sessionId) {
            return;
        }

        $httpMessage->setHeader('Mcp-Session-Id', $sessionId);
        $this->userContext->setSessionKey('mcp:'.$sessionId);
    }

    private function needsSessionRecovery(string $mcpSessionId, ?\stdClass $payload): bool
    {
        if ('tools/call' !== $this->rpcMethod($payload)) {
            return false;
        }

        $session = $this->sessionStore()->load($mcpSessionId);

        return null === $session || $session->isExpired($this->sessionTimeout());
    }

    private function establishSessionId(ServerRequestInterface $request): ?string
    {
        $protocolVersion = trim($request->getHeaderLine('MCP-Protocol-Version'));
        if (!in_array($protocolVersion, self::LEGACY_PROTOCOL_VERSIONS, true)) {
            $protocolVersion = self::LATEST_LEGACY_PROTOCOL_VERSION;
        }

        $initMessage = new HttpMessage((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => $protocolVersion,
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'ai-suite-mcp session recovery', 'version' => '1.0'],
            ],
        ]));
        $initMessage->setMethod('POST');
        $initMessage->setUri((string) $request->getUri());
        $initMessage->setHeader('Content-Type', 'application/json');
        $initMessage->setHeader('Accept', 'application/json');

        $sessionId = $this->createRunner()->handleRequest($initMessage)->getHeader('Mcp-Session-Id');

        return \is_string($sessionId) && '' !== $sessionId ? $sessionId : null;
    }

    private function rpcMethod(?\stdClass $payload): string
    {
        $method = $payload->method ?? null;

        return \is_string($method) ? $method : '';
    }

    private function bearerChallenge(?string $error = null): string
    {
        $baseUrl = rtrim(\is_string($host = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST')) ? $host : '', '/');

        $parameters = [];
        if (null !== $error) {
            $parameters[] = 'error="'.$error.'"';
        }
        $parameters[] = 'scope="'.implode(' ', PermissionService::supportedScopes()).'"';
        $parameters[] = 'resource_metadata="'.$baseUrl.'/.well-known/oauth-protected-resource"';

        return 'Bearer '.implode(', ', $parameters);
    }

    private function isModernRequest(ServerRequestInterface $request, ?\stdClass $payload): bool
    {
        if (in_array(trim($request->getHeaderLine('MCP-Protocol-Version')), self::MODERN_PROTOCOL_VERSIONS, true)) {
            return true;
        }

        if (self::METHOD_DISCOVER === $this->rpcMethod($payload)) {
            return true;
        }

        $metaVersion = $payload->params->_meta->{self::META_PROTOCOL_VERSION} ?? null;

        return \is_string($metaVersion) && in_array($metaVersion, self::MODERN_PROTOCOL_VERSIONS, true);
    }

    /**
     * @return array<string, string>
     */
    private function describeRequest(?\stdClass $payload, string $rawBody): array
    {
        if (null === $payload) {
            return ['raw' => substr($rawBody, 0, 300)];
        }

        $described = ['method' => $this->rpcMethod($payload)];

        $params = $payload->params ?? null;
        if (!$params instanceof \stdClass) {
            return $described;
        }

        $tool = $params->name ?? null;
        if (\is_string($tool)) {
            $described['tool'] = $tool;
        }

        $arguments = $params->arguments ?? null;
        if (null !== $arguments) {
            $described['arguments'] = substr((string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 0, 500);
        }

        return $described;
    }

    private function enrichServerIdentity(string $body, string $rpcMethod): string
    {
        if (!in_array($rpcMethod, [self::METHOD_INITIALIZE, self::METHOD_DISCOVER], true)) {
            return $body;
        }

        $json = json_decode($body);
        if (!$json instanceof \stdClass) {
            return $body;
        }

        $result = $json->result ?? null;
        if (!$result instanceof \stdClass) {
            return $body;
        }

        $serverInfo = $this->resolveServerInfo($result);
        if ($serverInfo instanceof \stdClass) {
            $baseUrl = rtrim(\is_string($host = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST')) ? $host : '', '/');
            $iconPath = $this->iconService->getPublicIconUrl('tx-aisuite-extension');

            if ('' !== $iconPath) {
                $serverInfo->icons = [
                    (object) ['src' => $baseUrl.$iconPath, 'mimeType' => $this->iconMimeType($iconPath)],
                ];
            }
        }

        $result->instructions = $this->serverInstructions->build();

        return (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function resolveServerInfo(\stdClass $result): ?\stdClass
    {
        if (($result->serverInfo ?? null) instanceof \stdClass) {
            return $result->serverInfo;
        }

        $meta = $result->_meta ?? null;
        if (!$meta instanceof \stdClass) {
            return null;
        }

        $serverInfo = $meta->{self::META_SERVER_INFO} ?? null;

        return $serverInfo instanceof \stdClass ? $serverInfo : null;
    }

    private function iconMimeType(string $iconPath): string
    {
        $extension = strtolower(pathinfo(parse_url($iconPath, PHP_URL_PATH) ?: $iconPath, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/svg+xml',
        };
    }

    private function validateProtocolVersionHeader(ServerRequestInterface $request): ?ResponseInterface
    {
        $version = trim($request->getHeaderLine('MCP-Protocol-Version'));

        if ('' === $version) {
            return null;
        }

        if (in_array($version, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            return null;
        }

        $this->logger->warning('MCP endpoint rejected request: unsupported MCP-Protocol-Version', [
            'requested' => $version,
            'supported' => self::SUPPORTED_PROTOCOL_VERSIONS,
        ]);

        return new JsonResponse([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32600,
                'message' => sprintf('Unsupported MCP-Protocol-Version: %s', $version),
                'data' => ['supported' => self::SUPPORTED_PROTOCOL_VERSIONS],
            ],
        ], 400);
    }

    private function resolveAuthorizationHeader(ServerRequestInterface $request): string
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if ('' === $authHeader) {
            $serverParams = $request->getServerParams();
            foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
                if (isset($serverParams[$key]) && '' !== $serverParams[$key]) {
                    $authHeader = (string) $serverParams[$key];

                    break;
                }
            }
        }

        if ('' === $authHeader && function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            if (is_array($apacheHeaders)) {
                foreach ($apacheHeaders as $name => $value) {
                    if (0 === strcasecmp((string) $name, 'Authorization')) {
                        $authHeader = (string) $value;

                        break;
                    }
                }
            }
        }

        return $authHeader;
    }

    private function extractBearerToken(ServerRequestInterface $request): string
    {
        $authHeader = $this->resolveAuthorizationHeader($request);

        if ('' === $authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            throw new InvalidTokenException('Please provide a valid API token using the Authorization: Bearer header.');
        }

        return substr($authHeader, 7);
    }

    private function validateBackendUserStatus(TokenData $tokenData, string $rawToken): void
    {
        $beUser = BackendUtility::getRecord('be_users', $tokenData->beUserUid);

        if (null === $beUser || 0 !== (int) ($beUser['disable'] ?? 0) || 0 !== (int) ($beUser['deleted'] ?? 0)) {
            $this->oauthService->revokeToken($rawToken);

            throw new InsufficientPermissionException(
                'Your backend account is currently inactive. Please contact your administrator to restore access.',
            );
        }
    }

    private function validateMcpAccessPermission(TokenData $tokenData): void
    {
        $this->backendUserInitializer->initialize($tokenData->beUserUid, $tokenData->workspaceUid);

        if (!$this->backendUserService->checkPermissions('tx_aisuite_features:enable_mcp_access')) {
            throw new InsufficientPermissionException(
                'MCP access needs to be enabled for your user group. Contact your administrator to enable it.',
            );
        }
    }

    private function maxCreditsPerSession(): int
    {
        try {
            $configured = (int) ($this->extensionConfiguration->get('ai_suite_mcp')['mcpMaxCreditsPerSession'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }

        return max(0, $configured);
    }
}
