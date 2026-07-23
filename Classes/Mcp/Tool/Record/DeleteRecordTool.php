<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class DeleteRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';
    protected bool $destructiveHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BatchResultBuilderService $batchResultBuilder,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'deleteRecords';
    }

    public function getDescription(): string
    {
        // No "ask the user first" here: confirmation is a host concern (the chat drawer gates
        // destructive calls, MCP clients raise an approval dialog). Telling the model to ask made
        // it answer in prose instead of calling the tool at all.
        return 'Delete one or more records from any TCA table (deletes). Soft-delete only — refused if the table does not support it. '
            .'Always pass a records array, even for a single record.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'records' => [
                    'type' => 'array',
                    'description' => 'The records to soft-delete. Each: {table, uid}.',
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

        return $this->batchResultBuilder->run($records, 'record(s)', function (mixed $record): array {
            $table = (string) ($record['table'] ?? '');
            $uid = (int) ($record['uid'] ?? 0);

            if ('' === $table || 0 === $uid) {
                throw new InvalidParameterException('Skipped (missing table or uid).');
            }

            // Validation is now inside the per-item handler so an excluded/missing table marks only
            // this record as failed instead of aborting the whole batch.
            $this->recordAccess->validateTableWriteAccess($table);

            if (!$this->tcaCompatibilityService->hasSoftDelete($table)) {
                throw new InvalidParameterException(sprintf('%s does not support soft-delete — deletion refused.', $table));
            }

            $existing = $this->recordAccess->assertRecordEditAccess($table, $uid);

            $labelField = $this->tcaCompatibilityService->getLabelField($table);
            $recordLabel = $existing[$labelField] ?? $uid;
            $tableLabel = $this->tcaLabel->getTableLabel($table);

            $dh = GeneralUtility::makeInstance(DataHandler::class);
            $dh->start([], [$table => [$uid => ['delete' => 1]]]);
            $dh->process_cmdmap();

            if ([] !== $dh->errorLog) {
                throw $this->dataHandlerError->toException('delete', $table, $uid, $dh->errorLog);
            }

            return [
                'message' => sprintf('%s "%s" (UID: %d) deleted', $tableLabel, $recordLabel, $uid),
                'uid' => $uid,
                'table' => $table,
                'action' => 'delete',
            ];
        });
    }
}
