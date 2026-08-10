<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Utility\BatchIndexRemapper;

final class TranslationExpanderService
{
    public const LOCALIZE_KEY = '$localize';

    public const TRANSLATIONS_KEY = 'translations';

    private const MAX_TRANSLATIONS_PER_RECORD = 20;

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * @param array<int, mixed> $records
     *
     * @return array<int, mixed>
     */
    public function expand(array $records): array
    {
        $plans = [];
        foreach (array_values($records) as $record) {
            $plans[] = $this->planRecord($record);
        }

        $indexMap = BatchIndexRemapper::buildIndexMap(
            array_map(static fn (array $plan): int => count($plan['translations']), $plans),
        );

        $expanded = [];
        foreach ($plans as $plan) {
            $record = $plan['record'];
            if (is_array($record) && isset($record['fields']) && is_array($record['fields'])) {
                $record['fields'] = BatchIndexRemapper::remap($record['fields'], $indexMap);
            }

            $originIndex = count($expanded);
            $expanded[] = $record;

            $table = is_array($record) ? (string) ($record['table'] ?? '') : '';
            $originUid = is_array($record) && isset($record['uid']) ? (int) $record['uid'] : null;

            foreach ($plan['translations'] as $language => $fields) {
                $expanded[] = [
                    'table' => $table,
                    self::LOCALIZE_KEY => [
                        'origin' => null !== $originUid ? $originUid : '$ref:'.$originIndex,
                        'language' => $language,
                    ],
                    'fields' => BatchIndexRemapper::remap($fields, $indexMap),
                ];
            }
        }

        return $expanded;
    }

    /**
     * @return array{record: mixed, translations: array<string, array<string, mixed>>}
     */
    private function planRecord(mixed $record): array
    {
        if (!is_array($record)) {
            return ['record' => $record, 'translations' => []];
        }

        if (array_key_exists(self::LOCALIZE_KEY, $record)) {
            throw new InvalidParameterException(sprintf(
                '`%s` is reserved for internal use. Use `%s` to write a translation.',
                self::LOCALIZE_KEY,
                self::TRANSLATIONS_KEY,
            ));
        }

        $recordFields = $record['fields'] ?? null;
        if (is_array($recordFields) && array_key_exists(self::TRANSLATIONS_KEY, $recordFields)) {
            throw new InvalidParameterException(sprintf(
                '`%s` belongs next to `table` and `fields`, not inside `fields`. Nested inline children cannot be translated in the same call — write them first, then translate them by uid.',
                self::TRANSLATIONS_KEY,
            ));
        }

        if (!array_key_exists(self::TRANSLATIONS_KEY, $record)) {
            return ['record' => $record, 'translations' => []];
        }

        $translations = $record[self::TRANSLATIONS_KEY];
        unset($record[self::TRANSLATIONS_KEY]);

        $table = (string) ($record['table'] ?? '');
        if (!is_array($translations) || [] === $translations) {
            throw new InvalidParameterException(sprintf(
                '`%s` must be an object keyed by ISO language code, e.g. {"en": {"header": "…"}}.',
                self::TRANSLATIONS_KEY,
            ));
        }

        if (!$this->isLanguageAware($table)) {
            throw new InvalidParameterException(sprintf(
                'Table "%s" does not support translations, so `%s` cannot be applied.',
                $table,
                self::TRANSLATIONS_KEY,
            ));
        }

        if (count($translations) > self::MAX_TRANSLATIONS_PER_RECORD) {
            throw new InvalidParameterException(sprintf(
                'Too many translations for one record (%d, max %d).',
                count($translations),
                self::MAX_TRANSLATIONS_PER_RECORD,
            ));
        }

        $planned = [];
        foreach ($translations as $language => $fields) {
            $planned[$this->assertLanguageKey($language)] = $this->assertTranslationFields($language, $fields);
        }

        return ['record' => $record, 'translations' => $planned];
    }

    private function assertLanguageKey(mixed $language): string
    {
        $code = is_string($language) ? trim($language) : '';

        if ('' === $code) {
            throw new InvalidParameterException(sprintf(
                'Each key of `%s` must be an ISO language code — readServerInfo lists the codes of every site.',
                self::TRANSLATIONS_KEY,
            ));
        }

        if (1 === preg_match('/^\d+$/', $code)) {
            throw new InvalidParameterException(sprintf(
                '`%s` is keyed by ISO language code, not by sys_language_uid — "%s" is not a code. readServerInfo lists the codes of every site.',
                self::TRANSLATIONS_KEY,
                $code,
            ));
        }

        // de-DE and de_DE resolve like de: RecordAccessService compares the locale's language code.
        $parts = explode('-', str_replace('_', '-', $code));

        return strtolower($parts[0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertTranslationFields(mixed $language, mixed $fields): array
    {
        if (!is_array($fields)) {
            throw new InvalidParameterException(sprintf(
                'The value of `%s.%s` must be an object of field values.',
                self::TRANSLATIONS_KEY,
                is_string($language) ? $language : '?',
            ));
        }

        foreach (['table', 'pid', 'uid', 'position'] as $reserved) {
            if (array_key_exists($reserved, $fields)) {
                throw new InvalidParameterException(sprintf(
                    '`%s` must not appear inside a translation — it is taken from the record being translated.',
                    $reserved,
                ));
            }
        }

        // @var array<string, mixed> $fields
        return $fields;
    }

    private function isLanguageAware(string $table): bool
    {
        try {
            return '' !== $table && $this->tcaCompatibilityService->isLanguageAware($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
