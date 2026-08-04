<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

class DataHandlerSanitizerService
{
    public function __construct(
        private TcaCompatibilityService $tcaCompatibilityService,
        private FlexFormValueNormalizer $flexFormValueNormalizer,
        private RawMarkupPolicyService $rawMarkupPolicy,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function sanitizeFields(string $table, array $data, ?string $typeKey = null, array $row = []): array
    {
        return $this->sanitizeFieldsWithReport($table, $data, $typeKey, $row)['data'];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $row
     *
     * @return array{data: array<string, mixed>, stripped: list<string>, blocked: list<string>}
     */
    public function sanitizeFieldsWithReport(string $table, array $data, ?string $typeKey = null, array $row = []): array
    {
        if (null === $typeKey) {
            try {
                $typeKey = $this->tcaCompatibilityService->resolveSubSchemaType($table, $data + $row);
            } catch (\Throwable $e) {
                $typeKey = null;
            }
        }

        $stripped = [];
        $blocked = [];
        foreach ($data as $field => $value) {
            if ($this->isFlexField($table, (string) $field, $typeKey)) {
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

            if ($this->isRawMarkupField($table, (string) $field, $typeKey)) {
                if ($hadMarkup && !$this->rawMarkupPolicy->isRawMarkupWriteAllowed()) {
                    unset($data[$field]);
                    $blocked[] = (string) $field;
                }

                continue;
            }

            $cleaned = $this->isMultilineField($table, (string) $field, $typeKey)
                ? $this->sanitizeMultiline($value)
                : $this->sanitizeSingleLine($value);

            if ($hadMarkup) {
                $stripped[] = (string) $field;
            }
            $data[$field] = $cleaned;
        }

        return ['data' => $data, 'stripped' => $stripped, 'blocked' => $blocked];
    }

    public function toPlainText(string $value): string
    {
        return $this->sanitizeMultiline($value);
    }

    private function sanitizeSingleLine(string $value): string
    {
        $value = $this->decodeEntities($value);
        $value = preg_replace('/<[^>]+>/', ' ', $value) ?? $value;

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function sanitizeMultiline(string $value): string
    {
        $value = $this->decodeEntities($value);

        $value = preg_replace('#<br\s*/?>#i', "\n", $value) ?? $value;
        $value = preg_replace('#</(?:li|p|div|tr|blockquote|h[1-6])\s*>#i', "\n", $value) ?? $value;
        $value = preg_replace('/<[^>]+>/', ' ', $value) ?? $value;

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? $value;

        $lines = array_map(static fn (string $line): string => trim($line), explode("\n", $value));
        $value = preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines)) ?? $value;

        return trim($value);
    }

    private function decodeEntities(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_replace("\u{00A0}", ' ', $value);
    }

    private function isMultilineField(string $table, string $field, ?string $typeKey): bool
    {
        return 'text' === $this->getFieldType($table, $field, $typeKey);
    }

    private function isFlexField(string $table, string $field, ?string $typeKey): bool
    {
        return 'flex' === $this->getFieldType($table, $field, $typeKey);
    }

    private function isRawMarkupField(string $table, string $field, ?string $typeKey): bool
    {
        try {
            return $this->tcaCompatibilityService->isRawMarkupField($table, $field, $typeKey);
        } catch (\Throwable) {
            return false;
        }
    }

    private function getFieldType(string $table, string $field, ?string $typeKey): string
    {
        try {
            $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $field);
            if ([] === $config) {
                $config = $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
            }
            $type = $config['type'] ?? '';

            return is_string($type) ? $type : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
