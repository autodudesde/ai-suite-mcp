<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RefusedRequestException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchEntryValidator;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\BatchPlacement;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class CopyRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BatchResultBuilderService $batchResultBuilder,
        private readonly BatchEntryValidator $batchEntryValidator,
        private readonly WorkspaceRecordService $workspaceRecords,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'copyRecords';
    }

    public function getDescription(): string
    {
        return 'Duplicate one or more records onto a target page, including their relations and child records (writes). '
            .'The original stays where it is; moveRecords relocates instead of duplicating.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'copies' => [
                    'type' => 'array',
                    'description' => 'The records to copy. Each: {table, uid, targetPid}, all required. Pass one entry even for a single copy.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'table' => ['type' => 'string'],
                            'uid' => ['type' => 'integer'],
                            'targetPid' => ['type' => 'integer'],
                        ],
                        'required' => ['table', 'uid', 'targetPid'],
                    ],
                ],
            ],
            'required' => ['copies'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $copies = $params['copies'] ?? null;

        if (!is_array($copies) || empty($copies)) {
            return $this->textError('copies must be a non-empty array of {table, uid, targetPid}.');
        }

        $this->batchEntryValidator->assertShape($copies, 'copies', ['table', 'uid', 'targetPid']);

        $placement = new BatchPlacement();

        return $this->batchResultBuilder->run($copies, 'copy/copies', function (mixed $copy) use ($placement): array {
            if (!is_array($copy)) {
                throw new InvalidParameterException('Skipped (not an object).');
            }

            return $this->performCopy($copy, $placement);
        });
    }

    /**
     * @param array<string, mixed> $copy
     *
     * @return array{message: string, uid: null|int, table: string, action: string}
     */
    private function performCopy(array $copy, BatchPlacement $placement): array
    {
        $table = (string) ($copy['table'] ?? '');
        $uid = (int) ($copy['uid'] ?? 0);
        $targetPid = isset($copy['targetPid']) ? (int) $copy['targetPid'] : null;

        if ('' === $table || $uid <= 0 || null === $targetPid) {
            throw RefusedRequestException::invalidParameter('Provide table, uid and targetPid.');
        }

        $this->recordAccess->validateTableWriteAccess($table);

        $uid = $this->workspaceRecords->resolveLiveUid($table, $uid);
        $record = $this->recordAccess->assertRecordReadAccess($table, $uid);
        $this->recordAccess->assertRecordCreateAccess($table, $targetPid);

        $targetPage = BackendUtility::getRecordWSOL('pages', $targetPid);
        if (null === $targetPage) {
            throw RefusedRequestException::notFound(sprintf('Target page %d not found.', $targetPid));
        }

        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $recordLabel = $record[$labelField] ?? $uid;

        $anchorUid = $placement->anchorFor($table, $targetPid, $record);
        $destination = null !== $anchorUid
            ? -1 * abs($this->workspaceRecords->resolveWriteTarget($table, $anchorUid))
            : $targetPid;

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], [$table => [$uid => ['copy' => $destination]]]);
        $dh->process_cmdmap();

        if ([] !== $dh->errorLog) {
            throw $this->dataHandlerError->toException('copy', $table, $uid, $dh->errorLog);
        }

        $newUid = $dh->copyMappingArray[$table][$uid] ?? null;
        if (null !== $newUid) {
            $placement->placed($table, $targetPid, (int) $newUid, $record);
        }

        $text = sprintf(
            '%s "%s" (UID: %d) copied to page %d.',
            $this->tcaLabel->getTableLabel($table),
            $recordLabel,
            $uid,
            $targetPid,
        );

        if (null !== $newUid) {
            $text .= sprintf(' New record UID: %d.', $newUid);
        }
        if (null !== $anchorUid) {
            $text .= sprintf(' Placed after record %d to keep the order of the batch.', $anchorUid);
        }

        return [
            'message' => $text,
            'uid' => null !== $newUid ? (int) $newUid : null,
            'table' => $table,
            'action' => 'create',
        ];
    }
}
