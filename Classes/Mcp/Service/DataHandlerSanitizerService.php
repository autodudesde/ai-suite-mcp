<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

class DataHandlerSanitizerService
{
    public function __construct(
        private TcaCompatibilityService $tcaCompatibilityService,
        private FlexFormValueNormalizer $flexFormValueNormalizer,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row  existing record row, used to resolve FlexForm data structures
     *
     * @return array<string, mixed>
     */
    public function sanitizeFields(string $table, array $data, ?string $typeKey = null, array $row = []): array
    {
        return $this->sanitizeFieldsWithReport($table, $data, $typeKey, $row)['data'];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row  existing record row, used to resolve FlexForm data structures
     *
     * @return array{data: array<string, mixed>, stripped: list<string>}
     */
    public function sanitizeFieldsWithReport(string $table, array $data, ?string $typeKey = null, array $row = []): array
    {
        if (null === $typeKey) {
            try {
                $typeKey = $this->tcaCompatibilityService->resolveSubSchemaType($table, $data);
            } catch (\Throwable $e) {
                $typeKey = null;
            }
        }

        $stripped = [];
        foreach ($data as $field => $value) {
            if ($this->isFlexField($table, (string) $field)) {
                $data[$field] = $this->flexFormValueNormalizer->normalize($table, (string) $field, $data + $row, $value);

                continue;
            }

            if (!\is_string($value)) {
                continue;
            }

            if ($this->tcaCompatibilityService->isRichTextField($table, $field, $typeKey)) {
                continue;
            }

            $hadMarkup = 1 === preg_match('/<[^>]+>/', $value);

            $cleaned = $this->isMultilineField($table, (string) $field)
                ? $this->sanitizeMultiline($value)
                : $this->sanitizeSingleLine($value);

            if ($hadMarkup) {
                $stripped[] = (string) $field;
            }
            $data[$field] = $cleaned;
        }

        return ['data' => $data, 'stripped' => $stripped];
    }

    /**
     * A readable, line-preserving rendition of a value that may carry markup.
     *
     * Used to show an RTE field to a human: the stored value keeps its HTML (RTE fields are never
     * sanitized), but a confirmation card that prints raw <ul><li> asks the editor to read markup.
     */
    public function toPlainText(string $value): string
    {
        return $this->sanitizeMultiline($value);
    }

    /**
     * Collapses every whitespace run, including newlines, into a single space.
     * Correct for TCA `input` fields, which cannot hold more than one line anyway.
     */
    private function sanitizeSingleLine(string $value): string
    {
        $value = $this->decodeEntities($value);
        $value = preg_replace('/<[^>]+>/', ' ', $value) ?? $value;

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Keeps newlines intact and turns block-level markup into them.
     *
     * Line-based CTypes read the field line by line (`bullets` renders one <li> per line,
     * `table` one row per line), so collapsing newlines the way sanitizeSingleLine() does
     * silently merges every item into one. Block markup is mapped onto newlines as well,
     * so a model that sends <ul><li>…</li></ul> instead of plain lines still lands correctly.
     */
    private function sanitizeMultiline(string $value): string
    {
        $value = $this->decodeEntities($value);

        $value = preg_replace('#<br\s*/?>#i', "\n", $value) ?? $value;
        $value = preg_replace('#</(?:li|p|div|tr|blockquote|h[1-6])\s*>#i', "\n", $value) ?? $value;
        $value = preg_replace('/<[^>]+>/', ' ', $value) ?? $value;

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        // Horizontal whitespace only. \s would swallow the newlines we just protected.
        $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? $value;

        $lines = array_map(static fn (string $line): string => trim($line), explode("\n", $value));
        $value = preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? $value;

        return trim($value);
    }

    private function decodeEntities(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // A decoded &nbsp; is not matched by \s, so it would survive every whitespace collapse.
        return str_replace("\u{00A0}", ' ', $value);
    }

    private function isMultilineField(string $table, string $field): bool
    {
        return 'text' === $this->getFieldType($table, $field);
    }

    private function isFlexField(string $table, string $field): bool
    {
        return 'flex' === $this->getFieldType($table, $field);
    }

    private function getFieldType(string $table, string $field): string
    {
        try {
            $type = $this->tcaCompatibilityService->getFieldConfiguration($table, $field)['type'] ?? '';

            return is_string($type) ? $type : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
