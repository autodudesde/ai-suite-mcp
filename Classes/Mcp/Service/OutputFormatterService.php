<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

class OutputFormatterService
{
    /**
     * @param ?int $maxLength null = no truncation
     */
    public function displayValue(mixed $value, ?int $maxLength): string
    {
        if (null === $value || '' === (string) $value) {
            return '_empty_';
        }

        $clean = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $value)));

        if (null === $maxLength || mb_strlen($clean) <= $maxLength) {
            return $clean;
        }

        return mb_substr($clean, 0, $maxLength).sprintf('… (truncated, %d chars total)', mb_strlen($clean));
    }

    public function truncate(string $value, int $maxLength): string
    {
        return mb_substr($value, 0, $maxLength);
    }

    public function scalarize(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return (string) $value;
    }
}
