<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

final class BatchDefaults
{
    /**
     * @param array<int|string, mixed> $entries
     *
     * @return array<int, mixed>
     */
    public static function applyTable(array $entries, string $table): array
    {
        if ('' === $table) {
            return array_values($entries);
        }

        $filled = [];
        foreach (array_values($entries) as $entry) {
            if (is_array($entry) && '' === trim((string) ($entry['table'] ?? ''))) {
                $entry['table'] = $table;
            }
            $filled[] = $entry;
        }

        return $filled;
    }
}
