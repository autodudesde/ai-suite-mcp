<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;

final class RecordsArgumentDecoder
{
    private const DIAGNOSTIC_TAIL_LENGTH = 120;

    /**
     * @return array<int|string, mixed>
     *
     * @throws InvalidParameterException
     */
    public static function decode(mixed $records, string $key = 'records'): array
    {
        if (is_array($records)) {
            return $records;
        }

        if (!is_string($records)) {
            return [];
        }

        $trimmed = self::stripCodeFence(trim($records));
        if ('' === $trimmed) {
            return [];
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw self::malformedJson($key, $trimmed, $e);
        }

        if (is_string($decoded)) {
            $inner = json_decode(trim($decoded), true);

            return is_array($inner) ? $inner : [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function stripCodeFence(string $value): string
    {
        if (!str_starts_with($value, '```')) {
            return $value;
        }

        $value = (string) preg_replace('/^```(?:json)?\s*/i', '', $value);

        return trim((string) preg_replace('/\s*```$/', '', $value));
    }

    private static function malformedJson(string $key, string $trimmed, \JsonException $e): InvalidParameterException
    {
        $length = strlen($trimmed);
        $tail = substr($trimmed, -self::DIAGNOSTIC_TAIL_LENGTH);
        $looksTruncated = self::looksTruncated($trimmed);

        $message = sprintf(
            '`%s` was sent as a string but is not valid JSON (%s). ',
            $key,
            $e->getMessage(),
        );
        $message .= $looksTruncated
            ? sprintf(
                'It ends mid-value after %d characters, so it arrived cut off rather than malformed. '
                .'Send `%s` as a real array instead of a JSON string, and split the batch into '
                .'several smaller calls if it stays this long.',
                $length,
                $key,
            )
            : sprintf('Pass `%s` as an array, or as a well-formed JSON array.', $key);

        return (new InvalidParameterException($message))->withErrorContext([
            'records_length' => $length,
            'records_tail' => $tail,
            'looks_truncated' => $looksTruncated,
        ]);
    }

    private static function looksTruncated(string $json): bool
    {
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0, $len = strlen($json); $i < $len; ++$i) {
            $char = $json[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ('\\' === $char) {
                    $escaped = true;
                } elseif ('"' === $char) {
                    $inString = false;
                }

                continue;
            }

            if ('"' === $char) {
                $inString = true;
            } elseif ('[' === $char || '{' === $char) {
                ++$depth;
            } elseif (']' === $char || '}' === $char) {
                --$depth;
            }
        }

        return $inString || $depth > 0;
    }
}
