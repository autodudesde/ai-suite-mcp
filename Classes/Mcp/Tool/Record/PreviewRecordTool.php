<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordPreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\RecordsArgumentDecoder;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class PreviewRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';
    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RecordPreviewService $recordPreview,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'previewRecords';
    }

    public function getDescription(): string
    {
        return 'Show what writeRecords would change, as an old/new diff per field, without touching the database. '
            .'The AI tools already return their result in the response, so they need no preview. '
            .'Always pass a records array, even for a single record; child records of a container carry `tx_container_parent` and `colPos`.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'records' => [
                    // Kept in step with writeRecords: a union type here would train the model into the
                    // string branch for the write that follows. See RecordsArgumentDecoder.
                    'type' => 'array',
                    'description' => 'The records writeRecords would receive. Each: {table, fields, pid?, uid?}. An uid diffs against that record; a pid previews a create.',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['records'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $records = RecordsArgumentDecoder::decode($params['records'] ?? []);

        if (empty($records)) {
            return $this->textError('records must be a non-empty array.');
        }

        $described = $this->recordPreview->describeWrite($records);

        $invalid = array_values(array_filter(
            $described,
            static fn (array $record): bool => 'invalid' === ($record['action'] ?? 'invalid'),
        ));
        $invalidCount = count($invalid);
        $validCount = count($described) - $invalidCount;

        $preview = [
            'preview' => [
                'kind' => 'records',
                'records' => $described,
                'validCount' => $validCount,
                'invalidCount' => $invalidCount,
            ],
        ];

        $text = $this->render($described, $invalid, $validCount);

        // Only when there is literally nothing to show. isError does not abort the turn -- the host
        // appends the result as a failed tool message and the model gets another turn to correct
        // itself -- but a mixed batch still has something worth rendering, so it stays a success.
        if (0 === $validCount) {
            return $this->errorResult($text, McpErrorType::InvalidParameter, [], $preview);
        }

        return $this->structuredResult($text, $preview);
    }

    /**
     * @param list<array<string, mixed>> $described
     * @param list<array<string, mixed>> $invalid   the subset of $described that cannot be written
     */
    private function render(array $described, array $invalid, int $validCount): string
    {
        $text = sprintf("## Preview: %d record(s)\n\n", count($described));

        $i = 0;
        foreach ($described as $record) {
            ++$i;
            $action = (string) ($record['action'] ?? 'invalid');
            $note = $record['note'] ?? null;

            if ('invalid' === $action) {
                $text .= sprintf("### Record %d: ❌ %s\n\n", $i, is_string($note) ? $note : 'Invalid');

                continue;
            }
            if ('skipped' === $action) {
                $text .= sprintf("### Record %d: ⛔ Skipped — %s\n\n", $i, is_string($note) ? $note : 'no permission');

                continue;
            }

            $text .= sprintf(
                "### Record %d: %s `%s` (%s)\n",
                $i,
                strtoupper($action),
                (string) $record['table'],
                (string) $record['tableLabel'],
            );

            if ('create' === $action) {
                $text .= sprintf(
                    "Page: %s | Position: %s\n",
                    null === $record['pid'] ? '?' : (string) $record['pid'],
                    (string) ($record['position'] ?? 'end'),
                );
            } else {
                $text .= sprintf("UID: %s\n", (string) $record['uid']);
            }

            $fields = $record['fields'] ?? [];
            if (is_array($fields)) {
                foreach ($fields as $field) {
                    $text .= $this->renderField($field);
                }
            }

            $text .= "\n";
        }

        $text .= "---\n";

        // An invalid record cannot be written, so inviting a write is worse than useless: it is what
        // sent gpt-5.4-nano into writeRecords with a hallucinated CType and a record whose table was
        // a placeholder string. The invitation is now tied to there being something writable.
        if ([] !== $invalid) {
            $notes = implode('; ', array_map(
                static fn (array $record): string => is_string($record['note'] ?? null) ? $record['note'] : 'invalid',
                $invalid,
            ));

            if (0 === $validCount) {
                return $text.sprintf(
                    '❌ Nothing can be written — every record is invalid: %s. Correct the record(s) and preview again.',
                    $notes,
                );
            }

            return $text.sprintf(
                '⚠️ %d of %d record(s) are invalid and cannot be written: %s. Only the valid record(s) can be saved with writeRecords.',
                count($invalid),
                count($described),
                $notes,
            );
        }

        // Not "wait for their confirmation": measured, gpt-5.4-nano and gpt-oss-120b took that
        // literally, ended the turn, and never called writeRecords. The host owns the approval
        // gate; a tool result that tells the model to wait simply strands the task.
        $text .= 'Show this preview to the user, then call writeRecords to save it.';

        return $text;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function renderField(array $field): string
    {
        $label = (string) ($field['label'] ?? '');
        $name = (string) ($field['name'] ?? '');
        $new = $this->withEllipsis((string) ($field['new'] ?? ''), (bool) ($field['truncated'] ?? false));
        $old = $field['old'] ?? null;

        if (null === $old) {
            return sprintf("- **%s** (`%s`): %s\n", $label, $name, $new);
        }

        $old = $this->withEllipsis((string) $old, (bool) ($field['truncated'] ?? false));
        if (false === ($field['changed'] ?? true)) {
            return sprintf("- **%s** (`%s`): %s _(unchanged)_\n", $label, $name, $new);
        }

        return sprintf("- **%s** (`%s`):\n    - old: %s\n    - new: %s\n", $label, $name, $old, $new);
    }

    private function withEllipsis(string $value, bool $truncated): string
    {
        return $truncated ? $value.'...' : $value;
    }
}
