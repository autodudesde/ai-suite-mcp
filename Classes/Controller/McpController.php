<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Controller;

use AutoDudes\AiSuite\Controller\AbstractBackendController;
use AutoDudes\AiSuite\Controller\Trait\AjaxResponseTrait;
use AutoDudes\AiSuite\Service\AiSuiteContext;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\Service\BackendCredentialService;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpWorkspaceOptionsService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\EventDispatcher\EventDispatcher;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsController]
class McpController extends AbstractBackendController
{
    use AjaxResponseTrait;

    private const CLIENT_NAME_MAX_LENGTH = 100;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        PageRenderer $pageRenderer,
        FlashMessageService $flashMessageService,
        SendRequestService $requestService,
        TranslationService $translationService,
        EventDispatcher $eventDispatcher,
        AiSuiteContext $aiSuiteContext,
        protected readonly PermissionService $permissionService,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly OAuthService $oauthService,
        protected readonly TokenRepository $tokenRepository,
        protected readonly McpWorkspaceOptionsService $workspaceOptions,
        protected readonly BackendCredentialService $credentialService,
        protected readonly LoggerInterface $logger,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $uriBuilder,
            $pageRenderer,
            $flashMessageService,
            $requestService,
            $translationService,
            $eventDispatcher,
            $aiSuiteContext,
        );
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== ($denied = $this->denyWithoutMcpAccess())) {
            return $denied;
        }

        return $this->indexAction($request);
    }

    public function createTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== ($denied = $this->denyWithoutMcpAccess())) {
            return $denied;
        }
        if (!(bool) ($this->extensionConfiguration->get('ai_suite_mcp')['enableMcp'] ?? false)) {
            return $this->tokenError('MCP token requested while enableMcp is off', 'mcp_disabled', 403);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $clientName = mb_substr(trim(strip_tags((string) ($body['clientName'] ?? ''))), 0, self::CLIENT_NAME_MAX_LENGTH) ?: 'Claude Desktop';
        $workspaceUid = (int) ($body['workspaceUid'] ?? 0);
        $backendUser = $this->aiSuiteContext->backendUserService->getBackendUser();
        $beUserUid = (int) ($backendUser?->user['uid'] ?? 0);

        $confirmedUid = $this->credentialService->authenticate((string) ($backendUser?->user['username'] ?? ''), (string) ($body['password'] ?? ''), $request);
        if ($beUserUid <= 0 || $confirmedUid !== $beUserUid) {
            return $this->tokenError('MCP token requested without a valid password confirmation', 'password_invalid', 403);
        }

        if ($workspaceUid > 0 && (null === $backendUser || false === $backendUser->checkWorkspace($workspaceUid))) {
            return $this->tokenError('No access to workspace '.$workspaceUid, 'workspace_denied', 403);
        }

        $availableScopes = $this->permissionService->getAvailableScopes();

        try {
            $tokenResult = $this->oauthService->createAccessToken(
                $beUserUid,
                $clientName,
                $availableScopes,
                CanonicalResource::get($request),
                $workspaceUid > 0 ? $workspaceUid : null,
            );
        } catch (\Exception $e) {
            return $this->tokenError('MCP token creation failed: '.$e->getMessage(), 'token_creation_failed', 500);
        }

        $baseUrl = CanonicalResource::host($request);

        $mcpUrl = $baseUrl.'/aisuite-mcp';

        $claudeConfig = json_encode([
            'mcpServers' => [
                'typo3-ai-suite' => [
                    'command' => 'npx',
                    'args' => [
                        '-y',
                        'mcp-remote',
                        $mcpUrl,
                        '--header',
                        'Authorization:${AUTH_HEADER}',
                    ],
                    'env' => [
                        'AUTH_HEADER' => 'Bearer '.$tokenResult['access_token'],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $claudeCodeConfig = json_encode([
            'mcpServers' => [
                'typo3-ai-suite' => [
                    'type' => 'http',
                    'url' => $mcpUrl,
                    'headers' => [
                        'Authorization' => 'Bearer '.$tokenResult['access_token'],
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return new JsonResponse([
            'success' => true,
            'token' => $tokenResult['access_token'],
            'scope' => $tokenResult['scope'],
            'expiresIn' => $tokenResult['expires_in'],
            'revokedClientId' => $tokenResult['revoked_client_id'],
            'claudeDesktopConfig' => $claudeConfig,
            'claudeCodeConfig' => $claudeCodeConfig,
            'configPaths' => [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'windows' => '%APPDATA%\Claude\claude_desktop_config.json',
                'linux' => '~/.config/Claude/claude_desktop_config.json',
            ],
        ]);
    }

    public function revokeTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        if (null !== ($denied = $this->denyWithoutMcpAccess())) {
            return $denied;
        }
        $body = (array) ($request->getParsedBody() ?? []);
        $tokenUid = (int) ($body['tokenUid'] ?? 0);
        $beUserUid = (int) ($this->aiSuiteContext->backendUserService->getBackendUser()?->user['uid'] ?? 0);

        if ($tokenUid <= 0 || $beUserUid <= 0 || !$this->tokenRepository->markDeletedForUser($tokenUid, $beUserUid)) {
            $response = new Response();
            $this->logError('Token '.$tokenUid.' not found for the current user', $response, 404);

            return $response;
        }

        return new JsonResponse(['success' => true]);
    }

    private function tokenError(string $logMessage, string $error, int $status): ResponseInterface
    {
        $this->logger->error($logMessage);

        return new JsonResponse(['success' => false, 'status' => $status, 'error' => $error], $status);
    }

    private function denyWithoutMcpAccess(): ?ResponseInterface
    {
        if ($this->aiSuiteContext->backendUserService->checkPermissions('tx_aisuite_features:enable_mcp_access')) {
            return null;
        }
        $response = new Response();
        $this->logError('MCP access is not enabled for this backend user (tx_aisuite_features:enable_mcp_access)', $response, 403);

        return $response;
    }

    private function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->initialize($request);
        $this->pageRenderer->addInlineLanguageLabelFile('EXT:ai_suite_mcp/Resources/Private/Language/locallang_module.xlf');
        $this->pageRenderer->loadJavaScriptModule('@autodudes/ai-suite-mcp/mcp/dashboard.js');
        $this->pageRenderer->addCssFile('EXT:ai_suite_mcp/Resources/Public/Css/mcp-module.css');
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $mcpEnabled = (bool) ($extConf['enableMcp'] ?? false);
        $beUserUid = (int) ($this->aiSuiteContext->backendUserService->getBackendUser()?->user['uid'] ?? 0);

        $tokens = [];
        if ($mcpEnabled) {
            $tokens = $this->tokenRepository->findActiveTokensForUser($beUserUid);
        }

        $baseUrl = CanonicalResource::host($request);
        $availableScopes = $this->permissionService->getAvailableScopes();

        $allowedOrigins = trim((string) ($extConf['mcpAllowedOrigins'] ?? ''));
        $allowedRedirectUris = trim((string) ($extConf['mcpAllowedRedirectUris'] ?? ''));

        $backendUser = $this->aiSuiteContext->backendUserService->getBackendUser();
        $languageService = $this->aiSuiteContext->localizationService->getLanguageService();
        $availableWorkspaces = $this->workspaceOptions->availableFor($backendUser);
        $defaultWorkspaceLabel = $this->workspaceOptions->defaultLabel($backendUser, $languageService);
        $tokens = $this->workspaceOptions->withWorkspaceTitles($tokens, $languageService);

        $this->view->assignMultiple([
            'mcpEnabled' => $mcpEnabled,
            'tokens' => $tokens,
            'baseUrl' => $baseUrl,
            'mcpEndpointUrl' => $baseUrl.'/aisuite-mcp',
            'availableScopes' => $availableScopes,
            'currentAction' => 'mcp',
            'allowedOrigins' => $allowedOrigins,
            'allowedRedirectUris' => $allowedRedirectUris,
            'availableWorkspaces' => $availableWorkspaces,
            'defaultWorkspaceLabel' => $defaultWorkspaceLabel,
        ]);

        return $this->view->renderResponse('Mcp/Index');
    }
}
