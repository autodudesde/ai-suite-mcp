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
    protected function applyReplacement(string $subject, string $search, string $replace, bool $all): array
    {
        if ('' === $search) {
            throw new InvalidParameterException('search must not be empty.');
        }

        $count = substr_count($subject, $search);

        if (0 === $count) {
            throw (new InvalidParameterException(sprintf(
                'Search text not found: "%s". The value is stored raw — for RTE fields a phrase may span HTML tags.',
                $this->truncate($search),
            )))->withErrorType(McpErrorType::NotFound);
        }

        if ($count > 1 && !$all) {
            throw new InvalidParameterException(sprintf(
                'Search text occurs %d times — pass all:true to replace every occurrence, or add surrounding context for a unique match.',
                $count,
            ));
        }

        $result = $all
            ? str_replace($search, $replace, $subject)
            : $this->replaceFirst($subject, $search, $replace);

        return ['result' => $result, 'count' => $all ? $count : 1];
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
}
