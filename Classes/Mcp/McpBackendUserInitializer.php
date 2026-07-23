<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpWriteModeResolver;
use AutoDudes\AiSuiteMcp\Mcp\Service\TokenAuthenticatedBackendUserService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\SingletonInterface;

class McpBackendUserInitializer implements SingletonInterface
{
    private bool $extTablesLoaded = false;

    public function __construct(
        private readonly TokenAuthenticatedBackendUserService $tokenAuthenticatedBackendUser,
        private readonly Context $context,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly LocalizationService $localizationService,
        private readonly McpWriteModeResolver $writeModeResolver,
    ) {}

    /**
     * @param null|int $workspaceUid Workspace the token is bound to (null = fall back to mcpWriteMode)
     *
     * @throws InsufficientPermissionException when the resolved workspace is not accessible
     */
    public function initialize(int $beUserUid, ?int $workspaceUid): BackendUserAuthentication
    {
        $backendUser = $this->tokenAuthenticatedBackendUser->createForUid($beUserUid);

        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUser);
        $this->context->setAspect('backend.user', new UserAspect($backendUser));

        $this->loadExtTablesOnce();

        $workspaceId = $this->writeModeResolver->resolveWorkspaceId($backendUser, $workspaceUid);

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

        if (false === $backendUser->setTemporaryWorkspace($workspaceId)) {
            $message = $this->localizationService->translate('mcp:hint.workspace_access_denied', [$workspaceId]);
            if ('' === $message) {
                $message = sprintf('Backend user has no access to workspace %d.', $workspaceId);
            }

            throw new InsufficientPermissionException($message);
        }

        $this->context->setAspect('workspace', new WorkspaceAspect($workspaceId));

        return $backendUser;
    }

    private function loadExtTablesOnce(): void
    {
        if ($this->extTablesLoaded) {
            return;
        }

        Bootstrap::loadExtTables();
        $this->extTablesLoaded = true;
    }
}
