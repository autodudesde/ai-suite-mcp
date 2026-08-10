<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

final class RecordTypeAliasNormalizer
{
    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * @param array<array-key, mixed> $records
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
            return $record;
        }

        $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];

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
