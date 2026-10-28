<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

class RecordLanguageLabelService implements SingletonInterface
{
    /** @var array<string, string> */
    private array $titles = [];

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly SiteFinder $siteFinder,
    ) {}

    public function titleOf(string $table, int $uid): string
    {
        if ('' === $table || $uid <= 0) {
            return '';
        }

        return $this->titles[$table.':'.$uid] ??= $this->resolve($table, $uid);
    }

    private function resolve(string $table, int $uid): string
    {
        try {
            $languageField = $this->tcaCompatibilityService->getLanguageFieldName($table);
            if (null === $languageField || '' === $languageField) {
                return '';
            }

            $record = BackendUtility::getRecord($table, $uid, '*');
            if (!\is_array($record)) {
                return '';
            }

            $languageId = (int) ($record[$languageField] ?? -1);
            if ($languageId < 0) {
                return '';
            }

            $site = $this->siteFinder->getSiteByPageId($this->sitePageId($table, $uid, $record));
            if (\count($site->getLanguages()) < 2) {
                return '';
            }

            return $site->getLanguageById($languageId)->getTitle();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function sitePageId(string $table, int $uid, array $record): int
    {
        if ('pages' !== $table) {
            return (int) ($record['pid'] ?? 0);
        }

        $parent = (int) ($record['l10n_parent'] ?? 0);

        return $parent > 0 ? $parent : $uid;
    }
}
