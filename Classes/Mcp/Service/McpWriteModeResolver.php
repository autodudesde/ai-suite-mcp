<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Domain\Repository\SysWorkspaceRepository;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

class McpWriteModeResolver implements SingletonInterface
{
    public function __construct(
        private readonly SysWorkspaceRepository $sysWorkspaceRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LocalizationService $localizationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws InsufficientPermissionException
     */
    public function resolveWorkspaceId(BackendUserAuthentication $backendUser, ?int $boundWorkspaceUid = null, ?string $writeModeOverride = null): int
    {
        if (null !== $boundWorkspaceUid) {
            return $boundWorkspaceUid;
        }

        $writeMode = ('' !== (string) $writeModeOverride) ? (string) $writeModeOverride : $this->getWriteMode();

        return 'live' === $writeMode ? 0 : $this->resolveWorkspaceModeId($backendUser);
    }

    public function getWriteMode(): string
    {
        try {
            return (string) ($this->extensionConfiguration->get('ai_suite_mcp')['mcpWriteMode'] ?? 'workspace');
        } catch (\Throwable) {
            return 'workspace';
        }
    }

    private function resolveWorkspaceModeId(BackendUserAuthentication $backendUser): int
    {
        if ($backendUser->workspace > 0) {
            return $backendUser->workspace;
        }

        $beUserUid = (int) ($backendUser->user['uid'] ?? 0);

        try {
            $existing = $this->sysWorkspaceRepository->findUserWorkspaceUid($beUserUid);
        } catch (\Throwable $e) {
            $this->logger->warning('Workspace mode: lookup of existing user workspace failed, will attempt creation', [
                'beUserUid' => $beUserUid,
                'error' => $e->getMessage(),
            ]);
            $existing = null;
        }

        if (null !== $existing && $existing > 0) {
            return $existing;
        }

        try {
            $username = (string) ($backendUser->user['username'] ?? ('uid '.$beUserUid));
            $newUid = $this->sysWorkspaceRepository->createForUser($beUserUid, $username);
            $this->logger->info('Workspace mode: auto-created per-user draft workspace', [
                'beUserUid' => $beUserUid,
                'workspace' => $newUid,
            ]);

            return $newUid;
        } catch (\Throwable $e) {
            $this->logger->error('Workspace mode: failed to auto-create user workspace, aborting instead of writing to live', [
                'beUserUid' => $beUserUid,
                'error' => $e->getMessage(),
            ]);

            throw new InsufficientPermissionException($this->workspaceUnavailableMessage(), 1753900001, $e);
        }
    }

    private function workspaceUnavailableMessage(): string
    {
        $message = $this->localizationService->translate('mcp:hint.workspace_unavailable');

        if ('' === $message) {
            $message = 'Write mode "workspace" requires a draft workspace, but none could be resolved or created for this backend user. Nothing was written to the live site. Contact a TYPO3 administrator or adjust the MCP write mode.';
        }

        return $message;
    }
}
