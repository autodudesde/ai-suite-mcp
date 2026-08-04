<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordWriteService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;

abstract class AbstractSafeEditTool extends AbstractDataTool
{
    private const SNIPPET_CONTEXT = 40;
    protected ?string $requiredScope = 'mcp:write';
    protected bool $dryRun = false;
    protected bool $normalizeWhitespace = true;

    public function __construct(
        ToolContext $mcpToolContext,
        protected readonly RecordWriteService $recordWrite,
    ) {
        parent::__construct($mcpToolContext);
    }

    /**
     * @return array{record: array<string, mixed>, value: string}
     */
    protected function loadEditableField(string $table, int $uid, string $field): array
    {
        $this->recordAccess->validateTableWriteAccess($table);
        $this->recordAccess->filterAccessibleFields($table, [$field => '']);
        $this->assertFieldWritable($table, $field);

        $record = $this->recordAccess->assertRecordEditAccess($table, $uid);
        $value = array_key_exists($field, $record) ? (string) $record[$field] : '';

        return ['record' => $record, 'value' => $value];
    }

    /**
     * @param array<string, mixed> $fields
     */
    protected function persist(string $table, int $uid, array $fields): void
    {
        if ($this->dryRun) {
            return;
        }

        $this->recordWrite->update($table, $uid, $fields);
    }

    protected function assertFieldWritable(string $table, string $field): void
    {
        $config = $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
        if (!empty($config['readOnly'])) {
            throw (new InvalidParameterException(sprintf('Field "%s" on %s is read-only.', $field, $table)))
                ->withErrorType(McpErrorType::ReadOnlyField)
                ->withErrorContext(['table' => $table, 'field' => $field])
            ;
        }
    }

    /**
     * @return array{result: string, count: int}
     */
    protected function applyReplacement(string $subject, string $search, string $replace, bool $all, bool $normalizeWhitespace = true): array
    {
        if ('' === $search) {
            throw new InvalidParameterException('search must not be empty.');
        }

        $matches = $normalizeWhitespace
            ? $this->findNormalized($subject, $search)
            : $this->findLiteral($subject, $search);
        $count = count($matches);

        if (0 === $count) {
            throw (new InvalidParameterException($this->notFoundMessage($subject, $search, $normalizeWhitespace)))
                ->withErrorType(McpErrorType::NotFound)
            ;
        }

        if ($count > 1 && !$all) {
            throw new InvalidParameterException(sprintf(
                'Search text occurs %d times — pass all:true to replace every occurrence, or add surrounding context for a unique match.',
                $count,
            ));
        }

        if (!$all) {
            $matches = [$matches[0]];
        }

        // Splice from the back so earlier offsets stay valid.
        $result = $subject;
        foreach (array_reverse($matches) as $match) {
            $result = substr_replace($result, $replace, $match['offset'], $match['length']);
        }

        return ['result' => $result, 'count' => count($matches)];
    }

    protected function replaceFirst(string $subject, string $search, string $replace): string
    {
        $pos = strpos($subject, $search);
        if (false === $pos) {
            return $subject;
        }

        return substr_replace($subject, $replace, $pos, strlen($search));
    }

    protected function snippet(string $value, string $needle): string
    {
        $pos = strpos($value, $needle);
        if (false === $pos) {
            return $this->truncate($value);
        }

        $start = max(0, $pos - self::SNIPPET_CONTEXT);
        $length = strlen($needle) + 2 * self::SNIPPET_CONTEXT;
        $window = substr($value, $start, $length);

        return ($start > 0 ? '…' : '').$window.($start + $length < strlen($value) ? '…' : '');
    }

    protected function truncate(string $value, int $max = 120): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max).'…' : $value;
    }

    /**
     * @return list<array{offset: int, length: int}>
     */
    private function findLiteral(string $subject, string $search): array
    {
        $matches = [];
        $offset = 0;
        while (false !== ($pos = strpos($subject, $search, $offset))) {
            $matches[] = ['offset' => $pos, 'length' => strlen($search)];
            $offset = $pos + strlen($search);
        }

        return $matches;
    }

    /**
     * Folds whitespace to locate the match, then maps it back onto the original byte offsets.
     *
     * @return list<array{offset: int, length: int}>
     */
    private function findNormalized(string $subject, string $search): array
    {
        ['text' => $haystack, 'map' => $map] = $this->foldWhitespace($subject);
        $needle = $this->foldWhitespace($search)['text'];
        if ('' === $needle) {
            return [];
        }

        $matches = [];
        $offset = 0;
        while (false !== ($pos = strpos($haystack, $needle, $offset))) {
            $start = $map[$pos];
            $end = $map[$pos + strlen($needle)];
            $matches[] = ['offset' => $start, 'length' => $end - $start];
            $offset = $pos + strlen($needle);
        }

        return $matches;
    }

    /**
     * @return array{text: string, map: list<int>}
     */
    private function foldWhitespace(string $value): array
    {
        $text = '';
        $map = [];
        $length = strlen($value);

        for ($i = 0; $i < $length;) {
            if (1 === preg_match('/\s/', $value[$i])) {
                $runStart = $i;
                while ($i < $length && 1 === preg_match('/\s/', $value[$i])) {
                    ++$i;
                }
                $text .= ' ';
                $map[] = $runStart;

                continue;
            }

            $text .= $value[$i];
            $map[] = $i;
            ++$i;
        }
        $map[] = $length;

        return ['text' => $text, 'map' => $map];
    }

    private function notFoundMessage(string $subject, string $search, bool $normalizeWhitespace): string
    {
        $message = sprintf('Search text not found: "%s".', $this->truncate($search));

        $closest = $this->longestFoundPrefix($subject, $search);
        if (null !== $closest) {
            $message .= sprintf(
                ' Its first %d character(s) do match, at offset %d: "%s" — the stored text diverges from there.',
                $closest['length'],
                $closest['offset'],
                $this->truncate(substr($subject, $closest['offset'], $closest['length'] + 40), 160),
            );
        }

        if (str_contains(html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $search)) {
            return $message
                .' The stored value writes those characters as HTML entities — your search uses the decoded spelling.'
                .' Search for the exact text a `raw: true` read returns; it is never entity-decoded.';
        }

        if (!$normalizeWhitespace) {
            return $message
                .' The value is stored raw: entities (&amp; vs &) and line endings (\r\n vs \n) can differ from what a read showed you.'
                .' Retry with normalizeWhitespace:true to ignore line-ending and spacing differences.';
        }

        return $message
            .' Line endings and spacing were already ignored, so the stored text genuinely differs.'
            .' Read the field with `raw: true` and copy the exact spelling — entities such as &amp; are stored literally.';
    }

    /**
     * @return null|array{offset: int, length: int}
     */
    private function longestFoundPrefix(string $subject, string $search): ?array
    {
        $low = 1;
        $high = strlen($search);
        $best = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            $position = strpos($subject, substr($search, 0, $middle));
            if (false !== $position) {
                $best = ['offset' => $position, 'length' => $middle];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $best;
    }
}
