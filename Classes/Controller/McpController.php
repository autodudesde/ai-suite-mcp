<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Controller;

use AutoDudes\AiSuite\Controller\AbstractBackendController;
use AutoDudes\AiSuite\Controller\Trait\AjaxResponseTrait;
use AutoDudes\AiSuite\Service\AiSuiteContext;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Service\TranslationService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Domain\Repository\SysWorkspaceRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use AutoDudes\AiSuiteMcp\Mcp\McpPermissionService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
class McpController extends AbstractBackendController
{
    use AjaxResponseTrait;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        PageRenderer $pageRenderer,
        FlashMessageService $flashMessageService,
        SendRequestService $requestService,
        TranslationService $translationService,
        EventDispatcher $eventDispatcher,
        AiSuiteContext $aiSuiteContext,
        protected readonly McpPermissionService $permissionService,
        protected readonly ExtensionConfiguration $extensionConfiguration,
        protected readonly OAuthService $oauthService,
        protected readonly TokenRepository $tokenRepository,
        protected readonly SysWorkspaceRepository $sysWorkspaceRepository,
        protected readonly UuidService $uuidService,
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
        return $this->indexAction($request);
    }

    public function createTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $clientName = (string) ($body['clientName'] ?? 'Claude Desktop');
        $workspaceUid = (int) ($body['workspaceUid'] ?? 0);
        $backendUser = $this->aiSuiteContext->backendUserService->getBackendUser();
        $beUserUid = (int) ($backendUser?->user['uid'] ?? 0);

        if ($workspaceUid > 0 && (null === $backendUser || false === $backendUser->checkWorkspace($workspaceUid))) {
            $response = new Response();
            $this->logError('No access to workspace '.$workspaceUid, $response, 403);

            return $response;
        }

        $availableScopes = $this->permissionService->getAvailableScopes();

        try {
            $tokenResult = $this->oauthService->createAccessToken(
                $beUserUid,
                $clientName,
                $availableScopes,
                $workspaceUid > 0 ? $workspaceUid : null,
            );
        } catch (\Exception $e) {
            $response = new Response();
            $this->logError($e->getMessage(), $response, 500);

            return $response;
        }

        /** @var string $requestHost */
        $requestHost = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        $baseUrl = rtrim($requestHost, '/');

        $claudeConfig = json_encode([
            'mcpServers' => [
                'typo3-ai-suite' => [
                    'url' => $baseUrl.'/aisuite-mcp',
                    'transport' => 'http',
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
            'claudeDesktopConfig' => $claudeConfig,
            'configPaths' => [
                'macOS' => '~/Library/Application Support/Claude/claude_desktop_config.json',
                'windows' => '%APPDATA%\Claude\claude_desktop_config.json',
                'linux' => '~/.config/claude/claude_desktop_config.json',
            ],
        ]);
    }

    public function revokeTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = (array) ($request->getParsedBody() ?? []);
        $tokenUid = (int) ($body['tokenUid'] ?? 0);

        if ($tokenUid > 0) {
            $this->tokenRepository->markDeleted($tokenUid);
        }

        return new JsonResponse(['success' => true]);
    }

    private function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->initialize($request);
        $this->pageRenderer->loadJavaScriptModule('@autodudes/ai-suite-mcp/mcp/dashboard.js');
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $mcpEnabled = (bool) ($extConf['enableMcp'] ?? false);
        $beUserUid = (int) ($this->aiSuiteContext->backendUserService->getBackendUser()?->user['uid'] ?? 0);

        // Get active tokens for current user
        $tokens = [];
        if ($mcpEnabled) {
            $tokens = $this->tokenRepository->findActiveTokensForUser($beUserUid);
        }

        /** @var string $requestHost */
        $requestHost = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        $baseUrl = rtrim($requestHost, '/');
        $availableScopes = $this->permissionService->getAvailableScopes();

        $allowedOrigins = trim((string) ($extConf['mcpAllowedOrigins'] ?? ''));
        $allowedRedirectUris = trim((string) ($extConf['mcpAllowedRedirectUris'] ?? ''));

        // Workspace selection options for the create-token form
        $availableWorkspaces = $this->resolveAvailableWorkspaces();
        $defaultWorkspaceLabel = $this->resolveDefaultWorkspaceLabel();
        $tokens = $this->decorateTokensWithWorkspaceTitle($tokens);

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

    /**
     * @return list<array{uid: int, title: string}>
     */
    private function resolveAvailableWorkspaces(): array
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return [];
        }

        $beUser = $this->aiSuiteContext->backendUserService->getBackendUser();
        if (null === $beUser) {
            return [];
        }

        $available = [];
        foreach ($this->sysWorkspaceRepository->findAll() as $row) {
            if ($beUser->checkWorkspace($row['uid'])) {
                $available[] = $row;
            }
        }

        return $available;
    }

    private function resolveDefaultWorkspaceLabel(): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return 'Live (workspaces not installed)';
        }

        $beUser = $this->aiSuiteContext->backendUserService->getBackendUser();
        $defaultWs = (int) ($beUser?->workspace ?? 0);

        return 0 === $defaultWs
            ? 'User default (Live)'
            : sprintf('User default (Workspace %d)', $defaultWs);
    }

    /**
     * @param list<array<string, mixed>> $tokens
     *
     * @return list<array<string, mixed>>
     */
    private function decorateTokensWithWorkspaceTitle(array $tokens): array
    {
        if ([] === $tokens) {
            return $tokens;
        }

        $workspaceUids = array_values(array_unique(array_filter(array_map(
            static fn (array $t): int => (int) ($t['workspace_uid'] ?? 0),
            $tokens,
        ))));

        $titles = [];
        if ([] !== $workspaceUids && ExtensionManagementUtility::isLoaded('workspaces')) {
            $titles = $this->sysWorkspaceRepository->findTitlesByUids($workspaceUids);
        }

        foreach ($tokens as &$token) {
            $wsUid = (int) ($token['workspace_uid'] ?? 0);
            $token['workspaceTitle'] = $titles[$wsUid] ?? sprintf('Workspace %d', $wsUid);
        }
        unset($token);

        return $tokens;
    }
}
