<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

use TYPO3\CMS\Core\Utility\GeneralUtility;

final class BatchPlacement
{
    /** @var array<string, array{uid: int, fields: array<string, string>}> */
    private array $placed = [];

    /**
     * @param array<string, mixed> $record
     */
    public function anchorFor(string $table, int $pid, array $record): ?int
    {
        $anchor = $this->placed[$table.':'.$pid] ?? null;

        return null !== $anchor && $anchor['fields'] === $this->positionFields($table, $record) ? $anchor['uid'] : null;
    }

    /**
     * @param array<string, mixed> $record
     */
    public function placed(string $table, ?int $pid, int $uid, array $record): void
    {
        $this->placed = array_filter($this->placed, static fn (array $anchor): bool => $anchor['uid'] !== $uid);
        if (null !== $pid) {
            $this->placed[$table.':'.$pid] = ['uid' => $uid, 'fields' => $this->positionFields($table, $record)];
        }
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, string>
     */
    private function positionFields(string $table, array $record): array
    {
        $fields = [];
        foreach (GeneralUtility::trimExplode(',', (string) ($GLOBALS['TCA'][$table]['ctrl']['copyAfterDuplFields'] ?? ''), true) as $field) {
            $fields[$field] = (string) ($record[$field] ?? '');
        }

        return $fields;
    }
}
