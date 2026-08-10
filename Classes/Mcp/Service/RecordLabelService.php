<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\SingletonInterface;

class RecordLabelService implements SingletonInterface
{
    private const MAX_LENGTH = 40;

    public function __construct(
        private readonly TcaLabelService $tcaLabelService,
    ) {}

    public function describe(string $table, int $uid): string
    {
        return $this->getRecordTitle($table, $uid)
            ?? sprintf('%s %d', $this->tcaLabelService->getTableLabel($table), $uid);
    }

    public function getRecordTitle(string $table, int $uid): ?string
    {
        if ('' === $table || $uid <= 0) {
            return null;
        }

        try {
            $row = BackendUtility::getRecordWSOL($table, $uid);
            if (!\is_array($row)) {
                return null;
            }
            $title = (string) BackendUtility::getRecordTitle($table, $row);
        } catch (\Throwable) {
            return null;
        }

        $title = trim((string) preg_replace('/\s+/u', ' ', strip_tags($title)));
        if ('' === $title) {
            return null;
        }

        return mb_strlen($title) > self::MAX_LENGTH
            ? mb_substr($title, 0, self::MAX_LENGTH - 1).'…'
            : $title;
    }
}
