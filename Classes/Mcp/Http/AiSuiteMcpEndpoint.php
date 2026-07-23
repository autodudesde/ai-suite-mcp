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
use AutoDudes\AiSuiteMcp\Mcp\Service\SessionOrientationService;
use AutoDudes\AiSuiteMcp\Mcp\Utility\OperatingGuidelines;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpMessage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class AiSuiteMcpEndpoint
{
    /**
     * Spec: https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#protocol-version-header
     * — Server MUST respond 400 for unknown values; SHOULD assume 2025-03-26 when header is absent.
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(
        private readonly McpServerFactory $serverFactory,
        private readonly OAuthService $oauthService,
        private readonly McpUserContext $userContext,
        private readonly BackendUserService $backendUserService,
        private readonly McpBackendUserInitializer $backendUserInitializer,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly SessionOrientationService $sessionOrientation,
        private readonly IconService $iconService,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $versionResponse = $this->validateProtocolVersionHeader($request);
        if (null !== $versionResponse) {
            return $versionResponse;
        }

        try {
            // Extract and validate Bearer token
            $rawToken = $this->extractBearerToken($request);
            $tokenData = $this->oauthService->validateToken($rawToken);

            // Backend user status live check
            $this->validateBackendUserStatus($tokenData, $rawToken);

            // Check MCP access permission
            $this->validateMcpAccessPermission($tokenData);

            // Initialize backend user context
            $this->backendUserInitializer->initialize($tokenData->beUserUid, $tokenData->workspaceUid);

            // Initialize MCP user context
            $this->userContext->initialize(
                $tokenData->beUserUid,
                $tokenData->scopes,
                $tokenData->clientId,
                $tokenData->tokenId,
                $tokenData->issuedVersion,
            );
            $this->userContext->setServerRequest($request);

            $mcpSessionId = trim($request->getHeaderLine('Mcp-Session-Id'));
            if ('' !== $mcpSessionId) {
                $this->userContext->setSessionKey('mcp:'.$mcpSessionId);
            }

            $rawBody = (string) $request->getBody();
            $payload = json_decode($rawBody);
            $payload = $payload instanceof \stdClass ? $payload : null;

            // Build HttpMessage from PSR-7 request
            $httpMessage = new HttpMessage($rawBody);
            $httpMessage->setMethod($request->getMethod());
            $httpMessage->setUri((string) $request->getUri());
            $httpMessage->setQueryParams($request->getQueryParams());
            foreach ($request->getHeaders() as $name => $values) {
                $httpMessage->setHeader($name, implode(', ', $values));
            }

            $this->mintSessionForStatelessClient($httpMessage, $request, $mcpSessionId, $payload);

            // process the JSON-RPC request
            $sdkResponse = $this->createRunner()->handleRequest($httpMessage);

            // Inject server icon into initialize response (SDK doesn't support this natively)
            $body = $sdkResponse->getBody();
            if (null !== $body) {
                $body = $this->injectServerIcon($body, $request);
            }

            $this->logger->info('MCP endpoint response', [
                'status' => $sdkResponse->getStatusCode(),
                'body' => substr((string) $body, 0, 500),
                'headers' => $sdkResponse->getHeaders(),
                'request_method' => $request->getMethod(),
                'request' => $this->describeRequest($payload, $rawBody),
            ]);

            // Convert SDK HttpMessage to PSR-7 response
            $response = new Response('php://temp', $sdkResponse->getStatusCode());
            foreach ($sdkResponse->getHeaders() as $name => $value) {
                $response = $response->withHeader($name, $value);
            }
            if (null !== $body) {
                $response->getBody()->write($body);
            }

            return $response;
        } catch (InvalidTokenException $e) {
            $this->logger->warning('MCP endpoint rejected request: invalid token', [
                'reason' => $e->getMessage(),
                'path' => $request->getUri()->getPath(),
                'has_auth_header' => '' !== $request->getHeaderLine('Authorization'),
            ]);

            $baseUrl = rtrim(\is_string($host = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST')) ? $host : '', '/');

            return new JsonResponse([
                'error' => 'unauthorized',
                'error_description' => 'Bearer token required. Use the OAuth 2.1 flow to obtain a token.',
            ], 401, [
                'WWW-Authenticate' => 'Bearer resource_metadata="'.$baseUrl.'/.well-known/oauth-protected-resource"',
            ]);
        } catch (InsufficientPermissionException $e) {
            $this->logger->warning('MCP endpoint denied request: insufficient permission', [
                'reason' => $e->getMessage(),
                'be_user_uid' => $tokenData->beUserUid,
                'client_id' => $tokenData->clientId,
            ]);

            return new JsonResponse(['error' => 'access_denied', 'error_description' => $e->getMessage()], 403);
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

        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $sessionTimeout = (int) ($extConf['mcpSessionTimeoutSeconds'] ?? 1800);

        $initOptions = $server->createInitializationOptions();

        $sessionPath = Environment::getVarPath().'/aisuite_mcp_sessions/';
        if (!is_dir($sessionPath)) {
            GeneralUtility::mkdir_deep($sessionPath);
        }

        // Stateless HTTP only: no long-lived SSE streams that would pin a
        // PHP-FPM worker per client. auto_detect would otherwise flip
        // enable_sse on in non-shared-hosting environments.
        $httpOptions = [
            'auto_detect' => false,
            'enable_sse' => false,
            'shared_hosting' => true,
            'session_timeout' => $sessionTimeout > 0 ? $sessionTimeout : 3600,
        ];

        return new HttpServerRunner(
            $server,
            $initOptions,
            $httpOptions,
            null,
            new FileSessionStore($sessionPath),
        );
    }

    private function mintSessionForStatelessClient(
        HttpMessage $httpMessage,
        ServerRequestInterface $request,
        string $mcpSessionId,
        ?\stdClass $payload,
    ): void {
        if ('POST' !== $request->getMethod() || '' !== $mcpSessionId) {
            return;
        }

        // `initialize` is the one request that is allowed to create a session itself.
        if ('initialize' === $this->rpcMethod($payload)) {
            return;
        }

        $sessionId = $this->establishSessionId($request);
        if (null === $sessionId) {
            return;
        }

        $httpMessage->setHeader('Mcp-Session-Id', $sessionId);
        $this->userContext->setSessionKey('mcp:'.$sessionId);
    }

    private function establishSessionId(ServerRequestInterface $request): ?string
    {
        $protocolVersion = trim($request->getHeaderLine('MCP-Protocol-Version'));
        if (!in_array($protocolVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
            $protocolVersion = self::SUPPORTED_PROTOCOL_VERSIONS[0];
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

    private function injectServerIcon(string $body, ServerRequestInterface $request): string
    {
        $json = json_decode($body);
        if (!$json instanceof \stdClass) {
            return $body;
        }

        $result = $json->result ?? null;
        if (!$result instanceof \stdClass) {
            return $body;
        }

        $serverInfo = $result->serverInfo ?? null;
        if (!$serverInfo instanceof \stdClass) {
            return $body;
        }

        $baseUrl = rtrim(\is_string($host = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST')) ? $host : '', '/');
        // Through the icon registry, so a white-label package re-registering the identifier wins.
        $iconPath = $this->iconService->getPublicIconUrl('tx-aisuite-extension');

        if ('' !== $iconPath) {
            $serverInfo->icons = [
                (object) ['src' => $baseUrl.$iconPath, 'mimeType' => $this->iconMimeType($iconPath)],
            ];
        }

        $result->instructions = $this->getServerInstructions();

        return (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    private function getServerInstructions(): string
    {
        $instructions = 'You are connected to a TYPO3 CMS via AI Suite MCP.'
            ."\n\n"
            .OperatingGuidelines::getForInstructions();

        $orientation = $this->sessionOrientation->buildInstructionBlock();
        if ('' !== $orientation) {
            $instructions .= "\n\n".$orientation;
        }

        return $instructions;
    }

    /**
     * Validate the MCP-Protocol-Version request header.
     *
     * Spec (MCP 2025-11-25, Transports §Protocol Version Header)
     */
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

    private function extractBearerToken(ServerRequestInterface $request): string
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
}
