<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

/**
 * Sanitize plain-text fields before writing via TYPO3 DataHandler.
 *
 * DataHandler does NOT sanitize plain input/text fields — only RTE fields
 * are processed by RteHtmlParser + html-sanitizer natively.
 * This service fills that gap for AI-generated content.
 */
class DataHandlerSanitizer
{
    public function __construct(
        private TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * Sanitize string values in data array.
     * RTE fields are skipped — DataHandler handles those via RteHtmlParser.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function sanitizeFields(string $table, array $data): array
    {
        foreach ($data as $field => $value) {
            if (!\is_string($value)) {
                continue;
            }

            if ($this->tcaCompatibilityService->isRichTextField($table, $field)) {
                continue;
            }

            // Replace tags with a space first so block-level boundaries
            // (</p><p>, <br>, </li>) don't collapse adjacent words.
            $stripped = preg_replace('/<[^>]+>/', ' ', $value) ?? $value;
            $data[$field] = trim((string) preg_replace('/\s+/', ' ', $stripped));
        }

        return $data;
    }
}
