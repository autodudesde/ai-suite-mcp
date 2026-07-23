<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Builds the compact "current environment" block appended to the MCP server's
 * initialize instructions. Front-loading the user's accessible sites (root page
 * UID + languages) and the active write mode means the model is oriented for
 * site-wide tasks from turn one, without spending a readServerInfo round-trip.
 *
 * Only the token's webmount sites are listed, so the block stays small on large
 * multi-site installs.
 */
class SessionOrientationService
{
    /**
     * Cap on how many writable tables the index lists, so it stays small even for
     * admins (who can write every table). The rest is reachable via listTables.
     */
    private const TABLE_INDEX_CAP = 50;

    /**
     * Infrastructure tables to keep out of the content-oriented index even though
     * they are technically writable (admins write everything). Content-relevant
     * sys_* tables (FAL, categories) are kept — see {@see isContentRelevantTable()}.
     *
     * @var list<string>
     */
    private const KEPT_SYS_TABLES = ['sys_category', 'sys_file', 'sys_file_metadata', 'sys_file_reference', 'sys_file_collection'];

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly BackendUserService $backendUserService,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly RecordAccessService $recordAccess,
        private readonly TcaLabelService $tcaLabel,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LoggerInterface $logger,
    ) {}

    public function buildInstructionBlock(): string
    {
        $beUser = $this->backendUserService->getBackendUser();
        if (null === $beUser) {
            return '';
        }

        $lines = ['## Current environment'];

        $isAdmin = $beUser->isAdmin();
        $siteLines = [];
        foreach ($this->siteFinder->getAllSites() as $identifier => $site) {
            try {
                if (!$isAdmin && !$beUser->isInWebMount($site->getRootPageId())) {
                    continue;
                }
                $languages = [];
                foreach ($site->getAllLanguages() as $language) {
                    $languages[] = $language->getLocale()->getLanguageCode();
                }
                $siteLines[] = sprintf(
                    '- %s (root page %d) — languages: %s',
                    $site->getConfiguration()['websiteTitle'] ?? $identifier,
                    $site->getRootPageId(),
                    [] !== $languages ? implode(', ', $languages) : 'default only',
                );
            } catch (\Throwable $e) {
                $this->logger->warning('SessionOrientationService: site skipped while building orientation block', [
                    'siteIdentifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ([] !== $siteLines) {
            $lines[] = 'Sites you can operate on (use the root page UID as rootPageId for tree/audit/bulk tools):';
            $lines = array_merge($lines, $siteLines);
        }

        $writeMode = (string) ($this->extensionConfiguration->get('ai_suite_mcp')['mcpWriteMode'] ?? 'workspace');
        $workspaceId = (int) ($beUser->workspace ?? 0);
        $target = $workspaceId > 0 ? sprintf('draft workspace #%d (not live)', $workspaceId) : 'the live site';
        $lines[] = sprintf('Write mode: %s — approved writes go to %s.', $writeMode, $target);

        $tableIndex = $this->buildTableIndex();
        if ('' !== $tableIndex) {
            $lines[] = $tableIndex;
        }

        return implode("\n", $lines);
    }

    private function buildTableIndex(): string
    {
        $entries = [];
        $writable = 0;
        foreach ($this->tcaCompatibilityService->getAllTableNames() as $table) {
            if (!$this->isContentRelevantTable($table)) {
                continue;
            }

            try {
                if (!$this->recordAccess->hasTableWriteAccess($table)) {
                    continue;
                }
                ++$writable;
                if (count($entries) >= self::TABLE_INDEX_CAP) {
                    continue;
                }
                $lang = $this->tcaCompatibilityService->isLanguageAware($table) ? ' [translatable]' : '';
                $entries[] = sprintf('%s (%s)%s', $table, $this->tcaLabel->getTableLabel($table), $lang);
            } catch (\Throwable $e) {
                $this->logger->warning('SessionOrientationService: table skipped while building index', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ([] === $entries) {
            return '';
        }

        $overflow = $writable > count($entries)
            ? sprintf(' (+%d more — call listTables for the full list incl. read-only tables)', $writable - count($entries))
            : '';

        return 'Writable content tables (field details on demand via readRecordSchema): '
            .implode(', ', $entries).$overflow;
    }

    private function isContentRelevantTable(string $table): bool
    {
        if (str_starts_with($table, 'be_') || str_starts_with($table, 'fe_')) {
            return false;
        }
        if (str_starts_with($table, 'sys_') && !in_array($table, self::KEPT_SYS_TABLES, true)) {
            return false;
        }

        return true;
    }
}
