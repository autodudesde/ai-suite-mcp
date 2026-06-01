<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

class DataHandlerSanitizerService
{
    public function __construct(
        private TcaCompatibilityService $tcaCompatibilityService,
    ) {}

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function sanitizeFields(string $table, array $data, ?string $typeKey = null): array
    {
        if (null === $typeKey) {
            try {
                $typeKey = $this->tcaCompatibilityService->resolveSubSchemaType($table, $data);
            } catch (\Throwable $e) {
                $typeKey = null;
            }
        }

        foreach ($data as $field => $value) {
            if (!\is_string($value)) {
                continue;
            }

            if ($this->tcaCompatibilityService->isRichTextField($table, $field, $typeKey)) {
                continue;
            }

            // Replace tags with a space first so block-level boundaries
            $stripped = preg_replace('/<[^>]+>/', ' ', $value) ?? $value;
            $data[$field] = trim((string) preg_replace('/\s+/', ' ', $stripped));
        }

        return $data;
    }
}
