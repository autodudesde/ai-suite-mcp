<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchEntryValidator;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\BatchDefaults;
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
        private readonly BatchEntryValidator $batchEntryValidator,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'deleteRecords';
    }

    public function getDescription(): string
    {
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
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'table' => ['type' => 'string'],
                            'uid' => ['type' => 'integer'],
                        ],
                        'required' => ['table', 'uid'],
                    ],
                ],
                'table' => ['type' => 'string', 'description' => 'Default TCA table for entries that do not carry their own `table`.'],
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

        $records = BatchDefaults::applyTable($records, (string) ($params['table'] ?? ''));

        $this->batchEntryValidator->assertShape($records, 'records', ['table', 'uid']);

        return $this->batchResultBuilder->run($records, 'record(s)', function (mixed $record): array {
            $table = (string) ($record['table'] ?? '');
            $uid = (int) ($record['uid'] ?? 0);

            if ('' === $table || 0 === $uid) {
                throw new InvalidParameterException('Skipped (missing table or uid).');
            }

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
