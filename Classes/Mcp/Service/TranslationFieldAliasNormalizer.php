<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

final class TranslationFieldAliasNormalizer
{
    private const ORIGIN = 'origin';

    private const SOURCE = 'source';

    private const ALIASES = [
        'l10n_parent' => self::ORIGIN,
        'l18n_parent' => self::ORIGIN,
        'l10n_source' => self::SOURCE,
        'l18n_source' => self::SOURCE,
    ];

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{fields: array<string, mixed>, renamed: array<string, string>}
     */
    public function normalizeFields(string $table, array $fields): array
    {
        $normalized = [];
        $renamed = [];

        foreach ($fields as $field => $value) {
            $target = $this->resolve($table, (string) $field);
            if (null === $target) {
                $normalized[(string) $field] = $value;

                continue;
            }

            $renamed[(string) $field] = $target;

            if (!array_key_exists($target, $fields)) {
                $normalized[$target] = $value;
            }
        }

        return ['fields' => $normalized, 'renamed' => $renamed];
    }

    /**
     * @param list<string> $names
     *
     * @return array{names: list<string>, renamed: array<string, string>}
     */
    public function normalizeNames(string $table, array $names): array
    {
        $normalized = [];
        $renamed = [];

        foreach ($names as $name) {
            $target = $this->resolve($table, $name);
            if (null === $target) {
                $normalized[] = $name;

                continue;
            }

            $renamed[$name] = $target;
            $normalized[] = $target;
        }

        return ['names' => array_values(array_unique($normalized)), 'renamed' => $renamed];
    }

    /**
     * @param array<string, string> $renamed
     */
    public function describeRenames(array $renamed): string
    {
        if ([] === $renamed) {
            return '';
        }

        $parts = [];
        foreach ($renamed as $alias => $target) {
            $parts[] = sprintf('%s → %s', $alias, $target);
        }

        return sprintf(
            ' — note: this table names its translation fields differently (%s)',
            implode(', ', $parts),
        );
    }

    private function resolve(string $table, string $field): ?string
    {
        $kind = self::ALIASES[$field] ?? null;
        if (null === $kind) {
            return null;
        }

        if ($this->hasField($table, $field)) {
            return null;
        }

        $target = self::ORIGIN === $kind
            ? $this->originPointerField($table)
            : $this->sourceField($table);

        if (null === $target || $target === $field || !$this->hasField($table, $target)) {
            return null;
        }

        return $target;
    }

    private function originPointerField(string $table): ?string
    {
        try {
            return $this->tcaCompatibilityService->getTranslationOriginPointerFieldName($table);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sourceField(string $table): ?string
    {
        try {
            return $this->tcaCompatibilityService->getTranslationSourceFieldName($table);
        } catch (\Throwable) {
            return null;
        }
    }

    private function hasField(string $table, string $field): bool
    {
        try {
            return $this->tcaCompatibilityService->hasField($table, $field);
        } catch (\Throwable) {
            return false;
        }
    }
}
