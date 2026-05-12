<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class DeleteRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function getName(): string
    {
        return 'deleteRecords';
    }

    public function getDescription(): string
    {
        return 'Delete one or more records from any TCA table. Only soft-deletes — if the table does not support soft-delete, the operation is refused. '
            .'Ask the user for confirmation before calling. '
            .'Always pass a records array — even for a single record, wrap it in an array.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'records' => [
                    'type' => 'array',
                    'description' => 'Array of records to delete. Each: {table, uid}. Example: [{"table":"tt_content","uid":42}]',
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
            return new CallToolResult([new TextContent('records must be a non-empty array.')], isError: true);
        }

        $results = [];

        foreach ($records as $i => $record) {
            $table = (string) ($record['table'] ?? '');
            $uid = (int) ($record['uid'] ?? 0);

            if ('' === $table || 0 === $uid) {
                $results[] = sprintf('#%d: ❌ Skipped (missing table or uid)', $i + 1);

                continue;
            }

            $this->validateTableWriteAccess($table);

            if (!$this->tcaCompatibilityService->hasSoftDelete($table)) {
                $results[] = sprintf('#%d: ❌ %s does not support soft-delete — deletion refused', $i + 1, $table);

                continue;
            }

            try {
                $existing = $this->assertRecordEditAccess($table, $uid);
            } catch (InsufficientPermissionException $e) {
                $this->logger->warning('DeleteRecord: skipping — insufficient permission', [
                    'table' => $table,
                    'uid' => $uid,
                    'reason' => $e->getMessage(),
                ]);
                $results[] = sprintf('#%d: ⛔ %s:%d skipped — %s', $i + 1, $table, $uid, $e->getMessage());

                continue;
            } catch (\RuntimeException $e) {
                $this->logger->warning('DeleteRecord: record not found', [
                    'table' => $table,
                    'uid' => $uid,
                    'reason' => $e->getMessage(),
                ]);
                $results[] = sprintf('#%d: ❌ %s:%d not found', $i + 1, $table, $uid);

                continue;
            }

            $labelField = $this->tcaCompatibilityService->getLabelField($table);
            $recordLabel = $existing[$labelField] ?? $uid;

            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $dh->start([], [$table => [$uid => ['delete' => 1]]]);
            $dh->process_cmdmap();

            if ([] !== $dh->errorLog) {
                $results[] = sprintf('#%d: ❌ %s "%s" (UID: %d) failed: %s', $i + 1, $this->getTableLabel($table), $recordLabel, $uid, implode(', ', $dh->errorLog));

                continue;
            }

            $results[] = sprintf('#%d: ✅ %s "%s" (UID: %d) deleted', $i + 1, $this->getTableLabel($table), $recordLabel, $uid);
        }

        $text = sprintf("## Delete result: %d record(s)\n\n", count($records));
        $text .= implode("\n", $results);

        return new CallToolResult([new TextContent($text)]);
    }
}
