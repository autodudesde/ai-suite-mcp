<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class MoveRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BatchResultBuilderService $batchResultBuilder,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'moveRecords';
    }

    public function getDescription(): string
    {
        return 'Relocate one or more records to another page or another position (writes). '
            .'The record itself moves; copyRecords duplicates instead. Also the way to reorder — `sorting` is not writable.';
    }

    public function getSchema(): array
    {
        // Array-only, see CopyRecordTool::getSchema().
        return [
            'type' => 'object',
            'properties' => [
                'moves' => [
                    'type' => 'array',
                    'description' => 'The records to move. Each: {table, uid, targetPid?, afterUid?}. Give each entry targetPid or afterUid. Pass one entry even for a single move.',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['moves'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $moves = $params['moves'] ?? null;

        if (!is_array($moves) || empty($moves)) {
            return $this->textError('moves must be a non-empty array of {table, uid, targetPid|afterUid}.');
        }

        return $this->batchResultBuilder->run($moves, 'move(s)', function (mixed $move): array {
            if (!is_array($move)) {
                throw new InvalidParameterException('Skipped (not an object).');
            }

            return $this->performMove($move);
        });
    }

    /**
     * @param array<string, mixed> $move
     *
     * @return array{message: string, uid: int, table: string, action: string}
     */
    private function performMove(array $move): array
    {
        $table = (string) ($move['table'] ?? '');
        $uid = (int) ($move['uid'] ?? 0);
        $targetPid = isset($move['targetPid']) ? (int) $move['targetPid'] : null;
        $afterUid = isset($move['afterUid']) ? (int) $move['afterUid'] : null;

        if ('' === $table || $uid <= 0) {
            throw new \RuntimeException('Provide table and uid.');
        }

        if (null === $targetPid && null === $afterUid) {
            throw new \RuntimeException('Provide targetPid or afterUid.');
        }

        $this->recordAccess->validateTableWriteAccess($table);

        $record = $this->recordAccess->assertRecordEditAccess($table, $uid);

        if (null !== $afterUid) {
            $afterRecord = BackendUtility::getRecordWSOL($table, $afterUid);
            if (null === $afterRecord) {
                throw new \RuntimeException(sprintf('Reference record %s:%d not found.', $table, $afterUid));
            }
            $resolvedTargetPid = (int) ($afterRecord['pid'] ?? 0);
        } else {
            $resolvedTargetPid = $targetPid;
        }
        $this->recordAccess->assertRecordCreateAccess($table, $resolvedTargetPid);

        $destination = null !== $afterUid ? -1 * abs($afterUid) : $targetPid;

        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $recordLabel = $record[$labelField] ?? $uid;

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], [$table => [$uid => ['move' => $destination]]]);
        $dh->process_cmdmap();

        if ([] !== $dh->errorLog) {
            throw $this->dataHandlerError->toException('move', $table, $uid, $dh->errorLog);
        }

        $text = sprintf(
            '%s "%s" (UID: %d) moved',
            $this->tcaLabel->getTableLabel($table),
            $recordLabel,
            $uid,
        );

        if (null !== $afterUid) {
            $text .= sprintf(' after record %d.', $afterUid);
        } else {
            $text .= sprintf(' to page %d.', $targetPid);
        }

        return ['message' => $text, 'uid' => $uid, 'table' => $table, 'action' => 'update'];
    }
}
