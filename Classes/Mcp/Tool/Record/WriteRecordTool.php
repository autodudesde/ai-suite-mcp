<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Create or update TCA records via DataHandler.
 * Supports single record or batch (multiple records at once).
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class WriteRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly RecordRepository $recordRepository,
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
            .'For b13/container content, set `tx_container_parent` (parent container UID, or "$ref:N" of the container created earlier in the same batch) and `colPos` to one of the container grid slots — see getContentTypes / getColumnPositions for valid slots.';
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
            return new CallToolResult([new TextContent('records must be a non-empty array.')], isError: true);
        }

        $batchPosition = (string) ($params['position'] ?? 'end');

        $createdUids = [];
        $results = [];
        // Tracks last-created sibling per group "pid:tx_container_parent:colPos" so multiple
        // children added to the same container slot stack inside that slot, while top-level
        // content in different colPos doesn't get falsely chained.
        $lastSiblingByGroup = [];

        foreach ($records as $i => $record) {
            $table = (string) ($record['table'] ?? '');
            $uid = isset($record['uid']) ? (int) $record['uid'] : null;
            $pid = isset($record['pid']) ? (int) $record['pid'] : null;
            $fields = $record['fields'] ?? [];

            if ('' === $table || !is_array($fields) || empty($fields)) {
                $results[] = sprintf('#%d: ❌ Skipped (missing table or fields)', $i + 1);

                continue;
            }

            $this->validateTableWriteAccess($table);
            $fields = $this->filterAccessibleFields($table, $fields);

            // Resolve $ref:N references first so tx_container_parent (and other refs) are
            // concrete UIDs before we decide on position grouping.
            $fields = $this->resolveReferences($fields, $createdUids);

            // Position logic for tt_content records in batch:
            // - First sibling in a (pid, tx_container_parent, colPos) group: use batchPosition
            // - Subsequent siblings in the SAME group: insert after the previous one (keeps order
            //   within the same container slot or top-level colPos)
            // - Non-tt_content (e.g. IRRE child items): always "end"
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
                    $results[] = sprintf('#%d: ❌ Skipped (no pid for create)', $i + 1);

                    continue;
                }

                try {
                    $this->assertRecordCreateAccess($table, $pid);
                } catch (InsufficientPermissionException $e) {
                    $this->logger->warning('WriteRecord: skipping create — insufficient permission', [
                        'table' => $table,
                        'pid' => $pid,
                        'reason' => $e->getMessage(),
                    ]);
                    $results[] = sprintf('#%d: ⛔ Skipped (no create permission on %s @ pid=%d): %s', $i + 1, $table, $pid, $e->getMessage());

                    continue;
                }

                $createdUid = $this->createSingleRecord($table, $pid, $fields, $position);
                $createdUids[$i] = $createdUid;
                if ('tt_content' === $table) {
                    $lastSiblingByGroup[$groupKey] = $createdUid;
                }
                $results[] = sprintf('#%d: ✅ %s created (UID: %d)', $i + 1, $this->getTableLabel($table), $createdUid);
            } else {
                try {
                    $this->assertRecordEditAccess($table, $uid);
                } catch (InsufficientPermissionException $e) {
                    $this->logger->warning('WriteRecord: skipping update — insufficient permission', [
                        'table' => $table,
                        'uid' => $uid,
                        'reason' => $e->getMessage(),
                    ]);
                    $results[] = sprintf('#%d: ⛔ Skipped (no edit permission on %s:%d): %s', $i + 1, $table, $uid, $e->getMessage());

                    continue;
                } catch (\RuntimeException $e) {
                    $this->logger->warning('WriteRecord: record not found, cannot update', [
                        'table' => $table,
                        'uid' => $uid,
                        'reason' => $e->getMessage(),
                    ]);
                    $results[] = sprintf('#%d: ❌ %s:%d not found', $i + 1, $table, $uid);

                    continue;
                }

                $this->updateSingleRecord($table, $uid, $fields);
                $createdUids[$i] = $uid;
                $results[] = sprintf('#%d: ✅ %s updated (UID: %d)', $i + 1, $this->getTableLabel($table), $uid);
            }
        }

        $text = sprintf("## Batch result: %d record(s)\n\n", count($records));
        $text .= implode("\n", $results);

        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * Replace "$ref:N" values with the actual UID from a previously created record.
     *
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

        // Resolve position: DataHandler uses positive pid = start of page, negative = after record
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
     * Resolve position to DataHandler pid convention.
     * "start" → positive pageId (DataHandler default = top)
     * "end" → negative UID of last record in same colPos/page
     * "after:UID" → negative of that UID.
     *
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

        // "end" → find last record on this page (same colPos if tt_content)
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
        // Existence + permission already verified in doExecute(); WSOL keeps the
        // workspace draft visible if applicable.
        $record = BackendUtility::getRecordWSOL($table, $uid);
        if (null === $record) {
            throw new \RuntimeException(sprintf('%s:%d not found.', $table, $uid));
        }

        $fields = $this->dataHandlerSanitizer->sanitizeFields($table, $fields);

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([$table => [$uid => $fields]], []);
        $dh->process_datamap();

        if ([] !== $dh->errorLog) {
            throw new \RuntimeException('Update failed: '.implode(', ', $dh->errorLog));
        }

        // Clean up finished background tasks for the written fields
        $this->cleanupBackgroundTasks($table, $uid, $fields);
    }

    /**
     * Remove finished background tasks whose results have just been written.
     *
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
