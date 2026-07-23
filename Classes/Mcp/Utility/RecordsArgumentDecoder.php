<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Utility;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;

/**
 * Accepts the `records` argument of writeRecords/previewRecords as a JSON string as well as an array.
 *
 * The schema asks for an array, but a large nested payload is exactly what a tool-calling layer tends
 * to hand back JSON-encoded. Measured with Qwen3.5 — otherwise the strongest GDPR model here — the
 * whole 13-element records list arrived as a string across six attempts, so it never parsed and
 * nothing was written. This is the same leniency we already apply to FlexForm values one level down.
 *
 * The string form stays accepted as a safety net, but the schema no longer advertises it: encoding a
 * batch as a JSON string costs a lot of extra tokens for the escaping, and a run on 16.07.2026 lost
 * every write of a PDF import (Qwen3.5 and Haiku 4.5 alike) to payloads that were cut off mid-string.
 * Truncation cannot be repaired here — a half-written record is gone — so the decoder's job is to
 * recognise it and say so, both to the model and in the log.
 */
final class RecordsArgumentDecoder
{
    /**
     * Bytes of the payload tail kept for diagnostics. The head is invariably well-formed; the end is
     * what tells a truncated payload apart from a malformed one.
     */
    private const DIAGNOSTIC_TAIL_LENGTH = 120;

    /**
     * @param string $key name of the argument, used in the message the model gets back
     *
     * @return array<int|string, mixed>
     *
     * @throws InvalidParameterException a string that is not valid JSON, so the model gets a clear message
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

        // A tool-calling layer that encodes its arguments twice hands back the JSON array as a string
        // again. Re-decoding is safe: it only happens when the outer decode yielded a string.
        if (is_string($decoded)) {
            $inner = json_decode(trim($decoded), true);

            return is_array($inner) ? $inner : [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Some models wrap the payload in a Markdown fence despite the schema. Cheap to undo, and it
     * cannot corrupt an otherwise valid payload: JSON never legally starts with a backtick.
     */
    private static function stripCodeFence(string $value): string
    {
        if (!str_starts_with($value, '```')) {
            return $value;
        }

        $value = (string) preg_replace('/^```(?:json)?\s*/i', '', $value);

        return trim((string) preg_replace('/\s*```$/', '', $value));
    }

    /**
     * Deliberately no trailing-comma or quote "repair": those rewrites are only reachable once strict
     * parsing already failed, and on this payload — records carrying HTML bodytext — a regex that
     * edits punctuation outside string literals cannot tell content from syntax. Silently writing
     * mangled content is worse than a rejected batch the model can retry.
     */
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

    /**
     * A truncated payload is an unclosed one: the brackets never balance. Counting them outside string
     * literals is enough to separate "cut off" from "wrong syntax" without parsing.
     */
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
