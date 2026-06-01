<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class WriteRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly RecordRepository $recordRepository,
        private readonly BatchResultBuilderService $batchResultBuilder,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'writeRecords';
    }

    public function getDescription(): string
    {
        return 'Write one or more records to the database. Only call after the user has seen a preview (via previewRecords or a generate*/translate* tool) and explicitly approved. '
            .'Always pass a records array — even for a single record, wrap it in an array. '
            .'For b13/container content, set `tx_container_parent` (parent container UID, or "$ref:N" of the container created earlier in the same batch) and `colPos` to one of the container grid slots — see getContentTypes / getColumnPositions for valid slots. '
            .'Notes: pid/uid/position are sibling properties of each record, never inside `fields`. '
            .'IRRE child records (e.g. bootstrap_package card_group_item/accordion_item/timeline_item) require their own `pid`. '
            .'Most reliable order is two-pass: create the tt_content parent first, then create children with the literal parent UID. '
            .'Attach an image with an explicit sys_file_reference record {uid_local:<sysFile>, uid_foreign:<elementUID|$ref:N>, tablenames, fieldname, pid} — do not put the sys_file UID directly into the image/assets field. '
            .'`sorting` is not writable here; reorder with moveRecord. '
            .'TCA-required fields are enforced on create — a record missing one is rejected (use getRecordSchema to see the required fields of a type). '
            .'On a partial failure the batch reports each record individually and lists the succeeded (persisted) UIDs — only re-send the failed records.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'records' => [
                    'type' => 'array',
                    'description' => 'Array of records. Each: {table, fields, pid?, uid?, position?}. '
                        .'Use "$ref:N" (0-based index) in a field value to reference the UID of a previously created record '
                        .'in the same batch (e.g. "$ref:0" for the first record). Useful for parent-child linking — '
                        .'including b13/container: create the container first, then set `tx_container_parent: "$ref:0"` and a valid `colPos` on each child.',
                    'items' => ['type' => 'object'],
                ],
                'position' => ['type' => 'string', 'default' => 'end', 'description' => 'Default position for the first tt_content record: "start", "end" (default), "after:UID".'],
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

        $batchPosition = (string) ($params['position'] ?? 'end');

        $createdUids = [];
        $lastSiblingByGroup = [];

        return $this->batchResultBuilder->run(
            $records,
            'record(s)',
            function (mixed $record, int $index) use (&$createdUids, &$lastSiblingByGroup, $batchPosition): array {
                $zeroBased = $index - 1;

                $table = (string) ($record['table'] ?? '');
                $uid = isset($record['uid']) ? (int) $record['uid'] : null;
                $pid = isset($record['pid']) ? (int) $record['pid'] : null;
                $fields = $record['fields'] ?? [];

                if ('' === $table || !is_array($fields) || empty($fields)) {
                    throw new InvalidParameterException('Skipped (missing table or fields).');
                }

                foreach (['pid', 'uid', 'position'] as $reserved) {
                    if (array_key_exists($reserved, $fields)) {
                        throw new InvalidParameterException(sprintf(
                            '`%s` must be a sibling property of the record (next to `table` and `fields`), not inside `fields`.',
                            $reserved,
                        ));
                    }
                }

                $this->recordAccess->validateTableWriteAccess($table);
                $fields = $this->recordAccess->filterAccessibleFields($table, $fields);
                $fields = $this->resolveReferences($fields, $createdUids);

                $position = (string) ($record['position'] ?? '');
                $groupKey = sprintf(
                    '%d:%d:%d',
                    $pid ?? 0,
                    (int) ($fields['tx_container_parent'] ?? 0),
                    (int) ($fields['colPos'] ?? 0),
                );
                if ('' === $position) {
                    if ('tt_content' === $table && isset($lastSiblingByGroup[$groupKey])) {
                        $position = 'after:'.$lastSiblingByGroup[$groupKey];
                    } elseif ('tt_content' === $table) {
                        $position = $batchPosition;
                    } else {
                        $position = 'end';
                    }
                }

                if (null === $uid) {
                    if (null === $pid) {
                        throw new InvalidParameterException('Missing `pid` (required to create a record).');
                    }

                    $this->recordAccess->assertRecordCreateAccess($table, $pid);

                    $typeField = $this->tcaCompatibilityService->getSubSchemaDivisorFieldName($table);
                    $typeValue = null !== $typeField ? (string) ($fields[$typeField] ?? '') : null;
                    $missingRequired = $this->recordAccess->findMissingRequiredFields($table, $typeValue, $fields);
                    if ([] !== $missingRequired) {
                        throw new InvalidParameterException(sprintf(
                            'Missing required field(s) for %s: %s. Provide them — see getRecordSchema for the required fields of this type.',
                            $this->tcaLabel->getTableLabel($table),
                            implode(', ', $missingRequired),
                        ));
                    }

                    $createdUid = $this->createSingleRecord($table, $pid, $fields, $position);
                    $createdUids[$zeroBased] = $createdUid;
                    if ('tt_content' === $table) {
                        $lastSiblingByGroup[$groupKey] = $createdUid;
                    }

                    return ['message' => sprintf('%s created (UID: %d)', $this->tcaLabel->getTableLabel($table), $createdUid), 'uid' => $createdUid];
                }

                $this->recordAccess->assertRecordEditAccess($table, $uid);
                $this->updateSingleRecord($table, $uid, $fields);
                $createdUids[$zeroBased] = $uid;

                return ['message' => sprintf('%s updated (UID: %d)', $this->tcaLabel->getTableLabel($table), $uid), 'uid' => $uid];
            },
        );
    }

    /**
     * @param array<int, int>      $createdUids
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function resolveReferences(array $fields, array $createdUids): array
    {
        foreach ($fields as $field => $value) {
            if (is_string($value) && preg_match('/^\$ref:(\d+)$/', $value, $matches)) {
                $refIndex = (int) $matches[1];
                if (isset($createdUids[$refIndex])) {
                    $fields[$field] = $createdUids[$refIndex];
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function createSingleRecord(string $table, int $pid, array $fields, string $position = 'end'): int
    {
        $fields = $this->dataHandlerSanitizer->sanitizeFields($table, $fields);
        $newId = 'NEW'.substr(md5((string) time().random_int(0, 100000)), 0, 22);

        $resolvedPid = $this->resolvePosition($table, $pid, $fields, $position);
        $fields['pid'] = $resolvedPid;

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([$table => [$newId => $fields]], []);
        $dh->process_datamap();

        if ([] !== $dh->errorLog) {
            throw new \RuntimeException('Create failed: '.implode(', ', $dh->errorLog));
        }

        return (int) ($dh->substNEWwithIDs[$newId] ?? 0);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function resolvePosition(string $table, int $pageId, array $fields, string $position): int
    {
        if ('start' === $position) {
            return $pageId;
        }

        if (str_starts_with($position, 'after:')) {
            $afterUid = (int) substr($position, 6);
            if ($afterUid > 0) {
                return -$afterUid;
            }
        }

        $sortByField = $this->tcaCompatibilityService->getRawConfiguration($table)['sortby'] ?? '';
        if ('end' === $position && '' !== $sortByField) {
            $colPos = ('tt_content' === $table && isset($fields['colPos'])) ? (int) $fields['colPos'] : null;
            $lastUid = $this->recordRepository->findLastUidOnPage($table, $pageId, $sortByField, $colPos);
            if (null !== $lastUid) {
                return -$lastUid;
            }
        }

        return $pageId;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function updateSingleRecord(string $table, int $uid, array $fields): void
    {
        $record = BackendUtility::getRecordWSOL($table, $uid);
        if (null === $record) {
            throw new \RuntimeException(sprintf('%s:%d not found.', $table, $uid));
        }

        $typeKey = null;

        try {
            $typeKey = $this->tcaCompatibilityService->resolveSubSchemaType($table, $fields + $record);
        } catch (\Throwable $e) {
            $this->logger->warning('WriteRecord: could not resolve record type for sanitizing, using base config', [
                'table' => $table,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
        }
        $fields = $this->dataHandlerSanitizer->sanitizeFields($table, $fields, $typeKey);

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([$table => [$uid => $fields]], []);
        $dh->process_datamap();

        if ([] !== $dh->errorLog) {
            throw new \RuntimeException('Update failed: '.implode(', ', $dh->errorLog));
        }

        $this->cleanupBackgroundTasks($table, $uid, $fields);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function cleanupBackgroundTasks(string $table, int $uid, array $fields): void
    {
        $uuidsToDelete = [];
        foreach (array_keys($fields) as $column) {
            $tasks = $this->backgroundTaskRepository->findFinishedTasksByRecord($table, $uid, (string) $column);
            foreach ($tasks as $task) {
                $uuidsToDelete[] = (string) $task['uuid'];
            }
        }

        if (!empty($uuidsToDelete)) {
            $this->backgroundTaskRepository->deleteByUuids($uuidsToDelete);
        }
    }
}
