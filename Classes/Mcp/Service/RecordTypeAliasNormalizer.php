<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

/**
 * Accepts `type` as an alias for a table's sub-schema divisor (CType for tt_content, doktype for
 * pages) and moves it into `fields` under the real column name.
 *
 * This closes an inconsistency in our own API: readRecordSchema and getRecordSchema take the element
 * kind as a parameter named `type`, but writeRecords wants it as `CType` inside `fields`. Measured
 * with Qwen3.5 — a strong model that read the FlexForm schema and set the columns correctly — every
 * record carried `type` (sometimes on the record level, sometimes inside `fields`) instead of `CType`,
 * so the whole batch was rejected and nothing was written. Normalising the alias is verbatim: `type`
 * only ever means the divisor here, no table has a real field called `type`.
 */
final class RecordTypeAliasNormalizer
{
    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * @param array<int, mixed> $records
     *
     * @return array<int, mixed>
     */
    public function normalize(array $records): array
    {
        $normalized = [];
        foreach ($records as $record) {
            $normalized[] = is_array($record) ? $this->normalizeRecord($record) : $record;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function normalizeRecord(array $record): array
    {
        $table = (string) ($record['table'] ?? '');
        if ('' === $table) {
            return $record;
        }

        $divisor = $this->divisor($table);
        if (null === $divisor) {
            // No sub-schema divisor: `type` is not implicitly the element kind here, so leave the
            // record untouched and let the normal field validation speak.
            return $record;
        }

        $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];

        // A value in `fields` wins over a record-level one, but either way both keys are removed:
        // `type` is never a real column.
        $typeValue = $fields['type'] ?? $record['type'] ?? null;
        unset($fields['type'], $record['type']);

        if (is_scalar($typeValue) && '' !== (string) $typeValue && !array_key_exists($divisor, $fields)) {
            $fields[$divisor] = $typeValue;
        }

        $record['fields'] = $fields;

        return $record;
    }

    private function divisor(string $table): ?string
    {
        try {
            return $this->tcaCompatibilityService->getSubSchemaDivisorFieldName($table);
        } catch (\Throwable) {
            return null;
        }
    }
}
