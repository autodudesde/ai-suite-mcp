<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;

final class RecordsArgumentDecoder
{
    private const DIAGNOSTIC_TAIL_LENGTH = 120;

    private const EXCERPT_LENGTH = 40;

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
            : self::mismatchHint($key, $trimmed);

        return (new InvalidParameterException($message))->withErrorContext([
            'records_length' => $length,
            'records_tail' => $tail,
            'looks_truncated' => $looksTruncated,
        ]);
    }

    private static function mismatchHint(string $key, string $json): string
    {
        $mismatch = self::firstMismatch($json);
        if (null === $mismatch) {
            return sprintf('Pass `%s` as an array, or as a well-formed JSON array.', $key);
        }

        $position = mb_strlen(substr($json, 0, $mismatch['position']));
        $openedAt = mb_strlen(substr($json, 0, $mismatch['openedAt']));
        $hint = null === $mismatch['expected']
            ? sprintf('At character %d a `%s` closes nothing that was opened.', $position, $mismatch['found'])
            : sprintf(
                'At character %d a `%s` closes the `%s` opened at character %d (`%s…`): a `%s` is missing before it, after `…%s`.',
                $position,
                $mismatch['found'],
                '}' === $mismatch['expected'] ? '{' : '[',
                $openedAt,
                mb_substr($json, $openedAt, self::EXCERPT_LENGTH),
                $mismatch['expected'],
                mb_substr($json, max(0, $position - self::EXCERPT_LENGTH), min(self::EXCERPT_LENGTH, $position)),
            );

        return $hint.sprintf(' Send `%s` as a real array instead of a JSON string.', $key);
    }

    /**
     * @return null|array{position: int, found: string, expected: null|string, openedAt: int}
     */
    private static function firstMismatch(string $json): ?array
    {
        $stack = [];
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
                $stack[] = [$char, $i];
            } elseif (']' === $char || '}' === $char) {
                $open = array_pop($stack);
                $expected = null === $open ? null : ('[' === $open[0] ? ']' : '}');
                if ($expected !== $char) {
                    return ['position' => $i, 'found' => $char, 'expected' => $expected, 'openedAt' => null === $open ? 0 : $open[1]];
                }
            }
        }

        return null;
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
