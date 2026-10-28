<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Repository\SysWorkspaceRepository;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class McpWorkspaceOptionsService
{
    private const LABELS = 'LLL:EXT:ai_suite_mcp/Resources/Private/Language/locallang_module.xlf:aiSuite.module.mcp.workspace.';

    public function __construct(
        private readonly SysWorkspaceRepository $sysWorkspaceRepository,
    ) {}

    /**
     * @return list<array{uid: int, title: string}>
     */
    public function availableFor(?BackendUserAuthentication $backendUser): array
    {
        if (null === $backendUser || !ExtensionManagementUtility::isLoaded('workspaces')) {
            return [];
        }

        $available = [];
        foreach ($this->sysWorkspaceRepository->findAll() as $row) {
            if ($backendUser->checkWorkspace($row['uid'])) {
                $available[] = $row;
            }
        }

        return $available;
    }

    public function defaultLabel(?BackendUserAuthentication $backendUser, LanguageService $languageService): string
    {
        if (!ExtensionManagementUtility::isLoaded('workspaces')) {
            return $languageService->sL(self::LABELS.'liveWithoutWorkspaces');
        }

        $defaultWorkspace = (int) ($backendUser?->workspace ?? 0);

        return 0 === $defaultWorkspace
            ? $languageService->sL(self::LABELS.'userDefaultLive')
            : sprintf($languageService->sL(self::LABELS.'userDefaultWorkspace'), $defaultWorkspace);
    }

    /**
     * @param list<array<string, mixed>> $tokens
     *
     * @return list<array<string, mixed>>
     */
    public function withWorkspaceTitles(array $tokens, LanguageService $languageService): array
    {
        if ([] === $tokens) {
            return $tokens;
        }

        $workspaceUids = array_values(array_unique(array_filter(array_map(
            static fn (array $token): int => (int) ($token['workspace_uid'] ?? 0),
            $tokens,
        ))));

        $titles = [];
        if ([] !== $workspaceUids && ExtensionManagementUtility::isLoaded('workspaces')) {
            $titles = $this->sysWorkspaceRepository->findTitlesByUids($workspaceUids);
        }

        foreach ($tokens as &$token) {
            $workspaceUid = (int) ($token['workspace_uid'] ?? 0);
            $token['workspaceTitle'] = $titles[$workspaceUid] ?? sprintf($languageService->sL(self::LABELS.'numbered'), $workspaceUid);
        }
        unset($token);

        return $tokens;
    }
}
