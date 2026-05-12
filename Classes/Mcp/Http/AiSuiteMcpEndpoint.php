<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Http;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\TokenData;
use AutoDudes\AiSuiteMcp\Domain\Repository\SysWorkspaceRepository;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\McpServerFactory;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\Exception\InvalidTokenException;
use AutoDudes\AiSuiteMcp\Mcp\OperatingGuidelines;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TokenAuthenticatedBackendUserFactory;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\InitializationOptions;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpMessage;
use Mcp\Types\ServerCapabilities;
use Mcp\Types\ServerResourcesCapability;
use Mcp\Types\ServerToolsCapability;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Main MCP endpoint. Handles Bearer token validation, backend user context
 * setup, and delegates to the MCP Server for JSON-RPC processing.
 */
class AiSuiteMcpEndpoint
{
    /**
     * MCP protocol versions this server understands. Aligned with the versions
     * the bundled mcp-sdk-php (^1.2) negotiates during initialize.
     *
     * Spec: https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#protocol-version-header
     * — Server MUST respond 400 for unknown values; SHOULD assume 2025-03-26 when header is absent.
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26'];

    public function __construct(
        private readonly McpServerFactory $serverFactory,
        private readonly OAuthService $oauthService,
        private readonly McpUserContext $userContext,
        private readonly BackendUserService $backendUserService,
        private readonly TokenAuthenticatedBackendUserFactory $tokenAuthenticatedBackendUserFactory,
        private readonly Context $context,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly LocalizationService $localizationService,
        private readonly SysWorkspaceRepository $sysWorkspaceRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // 0. Validate MCP-Protocol-Version header before any auth work — a 400 here
        //    must not leak whether a token would have been valid.
        $versionResponse = $this->validateProtocolVersionHeader($request);
        if (null !== $versionResponse) {
            return $versionResponse;
        }

        try {
            // 1. Extract and validate Bearer token
            $rawToken = $this->extractBearerToken($request);
            $tokenData = $this->oauthService->validateToken($rawToken);

            // 2. Backend user status live check (Q10)
            $this->validateBackendUserStatus($tokenData, $rawToken);

            // 3. Check MCP access permission
            $this->validateMcpAccessPermission($tokenData);

            // 4. Initialize backend user context
            $this->initializeBackendUser($tokenData);

            // 5. Initialize MCP user context
            $this->userContext->initialize(
                $tokenData->beUserUid,
                $tokenData->scopes,
                $tokenData->clientId,
                $tokenData->tokenId,
            );
            $this->userContext->setServerRequest($request);

            // 6. Create and run MCP server
            $server = $this->serverFactory->createServer();

            $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
            $sessionTimeout = (int) ($extConf['mcpSessionTimeoutSeconds'] ?? 1800);

            $initOptions = new InitializationOptions(
                serverName: 'ai-suite',
                serverVersion: ExtensionManagementUtility::getExtensionVersion('ai_suite') ?: '13.0.0',
                capabilities: new ServerCapabilities(
                    tools: new ServerToolsCapability(listChanged: false),
                    resources: new ServerResourcesCapability(subscribe: false, listChanged: false),
                ),
            );

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

            $runner = new HttpServerRunner(
                $server,
                $initOptions,
                $httpOptions,
                null,
                new FileSessionStore($sessionPath),
            );

            // Build HttpMessage from PSR-7 request (not fromGlobals, which is unreliable in TYPO3 middleware)
            $httpMessage = new HttpMessage((string) $request->getBody());
            $httpMessage->setMethod($request->getMethod());
            $httpMessage->setUri((string) $request->getUri());
            $httpMessage->setQueryParams($request->getQueryParams());
            foreach ($request->getHeaders() as $name => $values) {
                $httpMessage->setHeader($name, implode(', ', $values));
            }

            // Let the SDK process the JSON-RPC request
            $sdkResponse = $runner->handleRequest($httpMessage);

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
                'request_body' => substr((string) $request->getBody(), 0, 300),
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

    /**
     * Inject AI Suite icon and server instructions into the initialize response.
     * The MCP SDK doesn't support passing icons/instructions through InitializationOptions,
     * so we patch the JSON response directly.
     */
    private function injectServerIcon(string $body, ServerRequestInterface $request): string
    {
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['result']['serverInfo'])) {
            return $body;
        }

        $baseUrl = rtrim(\is_string($host = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST')) ? $host : '', '/');
        $iconPath = PathUtility::getPublicResourceWebPath('EXT:ai_suite/Resources/Public/Icons/Extension.svg');
        $iconUrl = $baseUrl.$iconPath;

        $json['result']['serverInfo']['icons'] = [
            ['src' => $iconUrl, 'mimeType' => 'image/svg+xml'],
        ];

        $json['result']['instructions'] = $this->getServerInstructions();

        return (string) json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Server instructions sent to the AI client during initialization.
     * These guide the client's behavior when using AI Suite tools.
     */
    private function getServerInstructions(): string
    {
        return 'You are connected to a TYPO3 CMS via AI Suite MCP.'
            ."\n\n"
            .OperatingGuidelines::get();
    }

    /**
     * Validate the MCP-Protocol-Version request header.
     *
     * Spec (MCP 2025-11-25, Transports §Protocol Version Header):
     *   - Header absent → tolerate (SHOULD assume 2025-03-26). The initial
     *     `initialize` request is allowed to omit it; subsequent requests
     *     should set it but we don't hard-fail to stay compatible with
     *     legacy clients.
     *   - Header present and unsupported → MUST respond 400.
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

        // Fallback for shared hosting where Apache (mod_php/FCGI) strips the
        // Authorization header before it reaches PHP. The .htaccess rewrite
        // (RewriteRule .* - [E=HTTP_AUTHORIZATION:%1]) puts it back into
        // REDIRECT_HTTP_AUTHORIZATION; some setups also expose it via
        // HTTP_AUTHORIZATION or apache_request_headers().
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

    /**
     * Live-check backend user status. If the user is disabled or deleted,
     * revoke the token immediately.
     */
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

    /**
     * Check that the user has the MCP access permission.
     */
    private function validateMcpAccessPermission(TokenData $tokenData): void
    {
        // Initialize a temporary backend user context to check permissions
        $this->initializeBackendUser($tokenData);

        if (!$this->backendUserService->checkPermissions('tx_aisuite_features:enable_mcp_access')) {
            throw new InsufficientPermissionException(
                'MCP access needs to be enabled for your user group. Contact your administrator to enable it.',
            );
        }
    }

    /**
     * Set up TYPO3 backend user context (UserAspect + WorkspaceAspect).
     */
    private function initializeBackendUser(TokenData $tokenData): void
    {
        // Create backend user authentication (incl. anonymous session — without it
        // FormDataCompiler crashes inside Clipboard::initializeClipboard()).
        $backendUser = $this->tokenAuthenticatedBackendUserFactory->createForUid($tokenData->beUserUid);

        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUser);
        $this->context->setAspect('backend.user', new UserAspect($backendUser));

        // Workspace context. Resolution order:
        //   1. Token-bound workspace (set explicitly when issuing the token, Phase 6) — wins.
        //   2. mcpWriteMode=live → 0 (DataHandler writes live records).
        //   3. mcpWriteMode=workspace → user's default workspace (be_users.workspace_id).
        //   4. mcpWriteMode=auto + ext:workspaces loaded → user's default workspace, with fallback
        //      to the first accessible non-live workspace if the user hasn't picked one. This makes
        //      "auto" semantically correct ("workspace if available, else live") instead of relying
        //      on the user having clicked through the BE workspace selector at least once — which
        //      would otherwise silently downgrade to live whenever be_users.workspace_id is 0.
        //   5. otherwise → 0 (live).
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $writeMode = (string) ($extConf['mcpWriteMode'] ?? 'auto');
        $workspaceId = match (true) {
            null !== $tokenData->workspaceUid => $tokenData->workspaceUid,
            'live' === $writeMode => 0,
            'workspace' === $writeMode => $backendUser->workspace,
            'auto' === $writeMode && ExtensionManagementUtility::isLoaded('workspaces') => $this->resolveAutoWorkspaceId($backendUser),
            default => 0,
        };

        // For token-bound workspaces, validate the user still has access (handles revoked
        // permissions for old tokens). Live (0) is checked by setTemporaryWorkspace itself.
        if ($workspaceId > 0 && !$backendUser->isAdmin() && false === $backendUser->checkWorkspace($workspaceId)) {
            $message = $this->localizationService->translate('mcp:hint.token_workspace_revoked', [$workspaceId]);
            if ('' === $message) {
                $message = sprintf(
                    'Token is bound to workspace %d, but the backend user no longer has access to it. Generate a new MCP token via the AI Suite backend module.',
                    $workspaceId,
                );
            }

            throw new InsufficientPermissionException($message);
        }

        // setTemporaryWorkspace (not setWorkspace, which would persist to be_users.workspace_id).
        // Catches the writeMode=live + non-live-user edge case.
        if (false === $backendUser->setTemporaryWorkspace($workspaceId)) {
            $message = $this->localizationService->translate('mcp:hint.workspace_access_denied', [$workspaceId]);
            if ('' === $message) {
                $message = sprintf('Backend user has no access to workspace %d.', $workspaceId);
            }

            throw new InsufficientPermissionException($message);
        }

        $this->context->setAspect('workspace', new WorkspaceAspect($workspaceId));
    }

    /**
     * Auto-mode workspace resolution. Priority:
     *   1. The user's persisted default workspace (be_users.workspace_id) if non-zero.
     *   2. The first accessible non-live workspace from sys_workspace.
     *   3. Live (0) when neither produced a hit — equivalent to mcpWriteMode=live.
     *
     * Without (2) the auto-mode silently degrades to live for any user who hasn't
     * picked a workspace via the BE selector — the most common state for fresh
     * installs and post-DB-reset test environments.
     */
    private function resolveAutoWorkspaceId(BackendUserAuthentication $backendUser): int
    {
        if ($backendUser->workspace > 0) {
            return $backendUser->workspace;
        }

        try {
            $rows = $this->sysWorkspaceRepository->findAllUids();
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-workspace resolution: could not query sys_workspace, falling back to live', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        foreach ($rows as $wsUid) {
            if ($wsUid > 0 && $backendUser->checkWorkspace($wsUid)) {
                $this->logger->info('Auto-workspace resolution: user has no default workspace, picking first accessible', [
                    'beUserUid' => $backendUser->user['uid'] ?? 0,
                    'pickedWorkspace' => $wsUid,
                ]);

                return $wsUid;
            }
        }

        return 0;
    }
}
