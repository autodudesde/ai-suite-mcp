<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class PreviewRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function getName(): string
    {
        return 'previewRecords';
    }

    public function getDescription(): string
    {
        return 'Preview one or more records before writing to the database — only needed for manually composed content (Approach B). '
            .'Not needed for external AI tools (generate*/translate*) — they return a preview directly in their response. '
            .'Always pass a records array — even for a single record, wrap it in an array. '
            .'For b13/container content, include `tx_container_parent` and `colPos` on child records (see getContentTypes / getColumnPositions). '
            .'Display the preview to the user. After explicit user approval, persist via writeRecords.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'records' => [
                    'type' => 'array',
                    'description' => 'Array of records to preview. Each: {table, fields, pid?, uid?}. '
                        .'Example: [{"table":"tt_content","pid":1,"fields":{"CType":"text","header":"Hi"}}]',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['records'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $records = $params['records'] ?? [];

        if (!is_array($records) || empty($records)) {
            return $this->textError('records must be a non-empty array.');
        }

        return $this->previewBatch($records);
    }

    /**
     * @param array<string, mixed> $records
     */
    private function previewBatch(array $records): CallToolResult
    {
        $text = sprintf("## Preview: %d record(s)\n\n", count($records));

        $i = 0;
        foreach ($records as $record) {
            ++$i;
            $table = (string) ($record['table'] ?? '');
            $uid = isset($record['uid']) ? (int) $record['uid'] : null;
            $pid = isset($record['pid']) ? (int) $record['pid'] : null;
            $fields = $record['fields'] ?? [];

            if ('' === $table || !is_array($fields) || empty($fields)) {
                $text .= sprintf("### Record %d: ❌ Invalid (missing table or fields)\n\n", $i);

                continue;
            }

            $isCreate = null === $uid;

            try {
                $this->recordAccess->validateTableReadAccess($table);
                if ($isCreate && null !== $pid) {
                    $this->recordAccess->assertRecordCreateAccess($table, $pid);
                } elseif (!$isCreate) {
                    $this->recordAccess->assertRecordEditAccess($table, $uid);
                }
                $fields = $this->recordAccess->filterAccessibleFields($table, $fields);
            } catch (InsufficientPermissionException $e) {
                $this->logger->warning('PreviewRecord: skipping record — insufficient permission', [
                    'table' => $table,
                    'uid' => $uid,
                    'pid' => $pid,
                    'isCreate' => $isCreate,
                    'reason' => $e->getMessage(),
                ]);
                $text .= sprintf("### Record %d: ⛔ Skipped — %s\n\n", $i, $e->getMessage());

                continue;
            } catch (InvalidParameterException|\RuntimeException $e) {
                $this->logger->warning('PreviewRecord: skipping record — invalid input', [
                    'table' => $table,
                    'uid' => $uid,
                    'pid' => $pid,
                    'isCreate' => $isCreate,
                    'reason' => $e->getMessage(),
                ]);
                $text .= sprintf("### Record %d: ❌ %s\n\n", $i, $e->getMessage());

                continue;
            }

            $action = $isCreate ? 'CREATE' : 'UPDATE';

            $text .= sprintf("### Record %d: %s `%s` (%s)\n", $i, $action, $table, $this->tcaLabel->getTableLabel($table));
            if ($isCreate && null !== $pid) {
                $text .= sprintf('Page: %d', $pid);
                $position = (string) ($record['position'] ?? 'end');
                $text .= sprintf(" | Position: %s\n", $position);
            } elseif (!$isCreate) {
                $text .= sprintf("UID: %d\n", $uid);
            }

            foreach ($fields as $field => $value) {
                $label = $this->tcaLabel->getFieldLabel($table, (string) $field);
                $displayValue = is_string($value) ? $value : json_encode($value);
                if (is_string($displayValue) && mb_strlen($displayValue) > 300) {
                    $displayValue = mb_substr($displayValue, 0, 300).'...';
                }
                $text .= sprintf("- **%s** (`%s`): %s\n", $label, $field, $displayValue);
            }

            $text .= "\n";
        }

        $text .= "---\n";
        $text .= 'Show this preview to the user and wait for their confirmation before saving.';

        return $this->textResult($text);
    }
}
