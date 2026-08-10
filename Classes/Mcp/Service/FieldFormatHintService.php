<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

class FieldFormatHintService
{
    /**
     * @var array<string, array<string, array<string, string>>>
     */
    private const HINTS = [
        'tt_content' => [
            'bullets' => [
                'bodytext' => 'one list item per line. Plain text, no <ul>/<li> and no bullet glyphs; the list markup is rendered for you.',
            ],
            'table' => [
                'bodytext' => 'one table row per line, cells separated by the delimiter configured in `table_delimiter` (default `|`).',
            ],
        ],
    ];

    public function forField(string $table, ?string $typeKey, string $field): ?string
    {
        if (null === $typeKey) {
            return null;
        }

        return self::HINTS[$table][$typeKey][$field] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function forType(string $table, string $typeKey): array
    {
        return self::HINTS[$table][$typeKey] ?? [];
    }
}
