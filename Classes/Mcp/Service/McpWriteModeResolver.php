<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Repository\SysWorkspaceRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

class McpWriteModeResolver implements SingletonInterface
{
    public function __construct(
        private readonly SysWorkspaceRepository $sysWorkspaceRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly LoggerInterface $logger,
    ) {}

    public function resolveWorkspaceId(BackendUserAuthentication $backendUser, ?int $boundWorkspaceUid = null, ?string $writeModeOverride = null): int
    {
        if (null !== $boundWorkspaceUid) {
            return $boundWorkspaceUid;
        }

        $writeMode = ('' !== (string) $writeModeOverride) ? (string) $writeModeOverride : $this->getWriteMode();

        return match (true) {
            'live' === $writeMode => 0,
            'workspace' === $writeMode => $this->resolveWorkspaceModeId($backendUser),
            'auto' === $writeMode => $this->resolveAutoWorkspaceId($backendUser),
            default => 0,
        };
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

        // Reuse a previously auto-created per-user workspace if present (idempotent).
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

        // Create one on demand so approved writes never silently hit live.
        try {
            $username = (string) ($backendUser->user['username'] ?? ('uid '.$beUserUid));
            $newUid = $this->sysWorkspaceRepository->createForUser($beUserUid, $username);
            $this->logger->info('Workspace mode: auto-created per-user draft workspace', [
                'beUserUid' => $beUserUid,
                'workspace' => $newUid,
            ]);

            return $newUid;
        } catch (\Throwable $e) {
            $this->logger->error('Workspace mode: failed to auto-create user workspace, falling back to live', [
                'beUserUid' => $beUserUid,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

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
                // Logged per request in a chat session, so it only produces noise.
                // $this->logger->info('Auto-workspace resolution: user has no default workspace, picking first accessible', [
                //     'beUserUid' => $backendUser->user['uid'] ?? 0,
                //     'pickedWorkspace' => $wsUid,
                // ]);

                return $wsUid;
            }
        }

        return 0;
    }
}
