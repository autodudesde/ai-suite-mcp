<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

/**
 * Format advice for fields whose value is line-based.
 *
 * The TCA of such a field says nothing about its line semantics: `tt_content.bodytext` is a plain
 * `text` column for every CType, but fluid_styled_content splits it line by line for `bullets`
 * (one <li> per line) and `table` (one row per line). Without that hint a model falls back to the
 * markup it knows, sends <ul><li> or bullet glyphs, and produces a single item.
 *
 * DataHandlerSanitizerService keeps the newlines of every TCA text field and maps block markup onto
 * them, so a wrong guess no longer breaks the record. This service exists so the model does not have
 * to guess in the first place.
 */
class FieldFormatHintService
{
    /**
     * table => record type => field => hint.
     *
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
     * The hints of a record type, keyed by field. Used where a whole type is described at once.
     *
     * @return array<string, string>
     */
    public function forType(string $table, string $typeKey): array
    {
        return self::HINTS[$table][$typeKey] ?? [];
    }
}
