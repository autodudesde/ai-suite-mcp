<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Brings a client-supplied FlexForm value into the canonical shape DataHandler expects
 * (data → sheet → lDEF → field → vDEF) and validates it against the real data structure.
 *
 * Without this, DataHandler::checkValueForFlex() only looks at $value['data']: foreign top-level
 * keys survive, get serialised by flexArray2Xml() and are merged into the stored value. The
 * result is XML with an empty <data> node, which xml2array() reads back as a string — every
 * later read of the record then dies with "Cannot access offset of type string on string".
 */
class FlexFormValueNormalizer
{
    private const LANGUAGE_KEY = 'lDEF';
    private const VALUE_KEY = 'vDEF';

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * @param array<string, mixed> $row   record row used to resolve the data structure (needs CType / list_type)
     * @param mixed                $value the raw value as sent by the client
     *
     * @return array<string, mixed> canonical structure: ['data' => [sheet => ['lDEF' => [field => ['vDEF' => …]]]]]
     *
     * @throws InvalidParameterException when the value cannot be mapped onto the data structure
     */
    public function normalize(string $table, string $field, array $row, mixed $value): array
    {
        $sheets = $this->resolveSheets($table, $field, $row);
        $input = $this->decode($table, $field, $value);
        $data = $this->extractDataPart($table, $field, $input, $sheets);

        $canonical = [];
        foreach ($data as $sheetName => $sheetValue) {
            $sheetName = (string) $sheetName;
            if (!\is_array($sheetValue)) {
                throw $this->invalid($table, $field, sprintf('Sheet "%s" must be an object of fields, got %s.', $sheetName, get_debug_type($sheetValue)));
            }

            $fields = \is_array($sheetValue[self::LANGUAGE_KEY] ?? null) ? $sheetValue[self::LANGUAGE_KEY] : $sheetValue;
            foreach ($fields as $fieldName => $fieldValue) {
                $fieldName = (string) $fieldName;
                $definition = $sheets[$sheetName][$fieldName] ?? null;
                if (null === $definition) {
                    throw $this->invalid($table, $field, sprintf(
                        'Unknown FlexForm field "%s" in sheet "%s". Valid fields: %s.',
                        $fieldName,
                        $sheetName,
                        implode(', ', array_keys($sheets[$sheetName])) ?: '(none)',
                    ));
                }

                $canonical[$sheetName][self::LANGUAGE_KEY][$fieldName] = $this->isSection($definition)
                    ? $this->normalizeSection($table, $field, $sheetName, $fieldName, $fieldValue)
                    : [self::VALUE_KEY => $this->scalarize($table, $field, $sheetName, $fieldName, $fieldValue)];
            }
        }

        return ['data' => $canonical];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, array<string, mixed>> sheet name => field name => field definition
     */
    private function resolveSheets(string $table, string $field, array $row): array
    {
        $fieldTca = $this->tcaCompatibilityService->getFieldTca($table, $field);

        try {
            $structure = $this->tcaCompatibilityService->resolveFlexFormDataStructure($fieldTca, $table, $field, $row);
        } catch (\Throwable $e) {
            throw $this->invalid($table, $field, sprintf(
                'Could not resolve the FlexForm data structure (%s). Make sure the record type (CType / list_type) is set.',
                $e->getMessage(),
            ));
        }

        $sheets = [];
        foreach (($structure['sheets'] ?? []) as $sheetName => $sheetDefinition) {
            if (!\is_array($sheetDefinition)) {
                continue;
            }
            $elements = $sheetDefinition['ROOT']['el'] ?? [];
            $sheets[(string) $sheetName] = \is_array($elements) ? $elements : [];
        }

        if ([] === $sheets) {
            throw $this->invalid($table, $field, 'The FlexForm data structure has no sheets, so no value can be written.');
        }

        return $sheets;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $table, string $field, mixed $value): array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (\is_string($value)) {
            $trimmed = trim($value);
            if ('' === $trimmed) {
                return [];
            }

            // Valid FlexForm XML: the atomic rollback and the safe-edit tools feed the stored
            // XML string straight back through the write path, so it must stay acceptable.
            if (str_starts_with($trimmed, '<')) {
                // xml2arrayProcess() over xml2array(): same parser, but without the runtime cache.
                $parsed = GeneralUtility::xml2arrayProcess($trimmed);
                if (!\is_array($parsed) || (isset($parsed['data']) && !\is_array($parsed['data']))) {
                    throw $this->invalid($table, $field, 'The value is not valid FlexForm XML.');
                }

                return $parsed;
            }

            $decoded = json_decode($trimmed, true);
            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        throw $this->invalid($table, $field, sprintf('Expected a nested object, got %s.', get_debug_type($value)));
    }

    /**
     * Accepts the canonical form, sheets on top level, and a flat field map.
     *
     * @param array<string, mixed>                $input
     * @param array<string, array<string, mixed>> $sheets
     *
     * @return array<string, mixed> sheet name => sheet value
     */
    private function extractDataPart(string $table, string $field, array $input, array $sheets): array
    {
        if (\array_key_exists('data', $input)) {
            if (!\is_array($input['data'])) {
                throw $this->invalid($table, $field, '"data" must be an object of sheets.');
            }
            $input = $input['data'];
        }

        if ([] === $input) {
            return [];
        }

        $keys = array_map(strval(...), array_keys($input));
        $unknownSheets = array_diff($keys, array_keys($sheets));
        if ([] === $unknownSheets) {
            return $input;
        }

        // Flat field map: {field: value} — resolve the sheet from the data structure.
        $flat = [];
        foreach ($input as $fieldName => $fieldValue) {
            $fieldName = (string) $fieldName;
            $owningSheets = array_keys(array_filter($sheets, static fn (array $elements): bool => \array_key_exists($fieldName, $elements)));
            if (1 !== \count($owningSheets)) {
                throw $this->invalid($table, $field, sprintf(
                    'Unknown sheet or field "%s". Valid sheets: %s.',
                    $fieldName,
                    implode(', ', array_keys($sheets)),
                ));
            }
            $flat[(string) $owningSheets[0]][$fieldName] = $fieldValue;
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function isSection(array $definition): bool
    {
        return 'array' === (string) ($definition['type'] ?? '') && !empty($definition['section']);
    }

    /**
     * Section containers carry their own nested structure — only the canonical form is accepted,
     * guessing a shape here would corrupt the containers.
     *
     * @return array<string, mixed>
     */
    private function normalizeSection(string $table, string $field, string $sheetName, string $fieldName, mixed $value): array
    {
        if (\is_array($value) && \is_array($value['el'] ?? null)) {
            return ['el' => $value['el']];
        }

        throw $this->invalid($table, $field, sprintf(
            'Field "%s" in sheet "%s" is a repeatable section and must be passed as {"el": {…}}.',
            $fieldName,
            $sheetName,
        ));
    }

    private function scalarize(string $table, string $field, string $sheetName, string $fieldName, mixed $value): string
    {
        if (\is_array($value) && \array_key_exists(self::VALUE_KEY, $value)) {
            $value = $value[self::VALUE_KEY];
        }

        // {"vDEF": ["3"]} — single-element lists are what a flattened numIndex node looks like.
        if (\is_array($value) && 1 === \count($value)) {
            $value = reset($value);
        }

        if (null === $value) {
            return '';
        }
        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        throw $this->invalid($table, $field, sprintf(
            'Field "%s" in sheet "%s" expects a scalar value, got %s.',
            $fieldName,
            $sheetName,
            get_debug_type($value),
        ));
    }

    private function invalid(string $table, string $field, string $reason): InvalidParameterException
    {
        $example = ['data' => ['sDEF' => [self::LANGUAGE_KEY => ['<field>' => [self::VALUE_KEY => '<value>']]]]];

        return (new InvalidParameterException(sprintf(
            '%s.%s (FlexForm): %s Pass it as a nested object %s — the shorthand {"<sheet>": {"<field>": "<value>"}} works too. '
            .'Call getFlexFormSchema for the sheets and fields of this record.',
            $table,
            $field,
            $reason,
            (string) json_encode($example, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        )))
            ->withErrorType(McpErrorType::InvalidParameter)
            ->withErrorContext(['table' => $table, 'field' => $field])
        ;
    }
}
