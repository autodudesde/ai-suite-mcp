<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Dto\RecordWriteResult;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\McpException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\ContainerBatchValidator;
use AutoDudes\AiSuiteMcp\Mcp\Service\NestedChildExpanderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordTypeAliasNormalizer;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordWriteService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\RecordsArgumentDecoder;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class WriteRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RecordWriteService $recordWrite,
        private readonly BatchResultBuilderService $batchResultBuilder,
        private readonly NestedChildExpanderService $nestedChildExpander,
        private readonly ContainerBatchValidator $containerBatchValidator,
        private readonly RecordTypeAliasNormalizer $typeAliasNormalizer,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'writeRecords';
    }

    public function getDescription(): string
    {
        // No "only call after the user approved" here: the host gates the call (the chat drawer
        // asks, MCP clients raise an approval dialog). Told to ask, small models answer in prose
        // instead of calling the tool at all — measured on deleteRecords.
        // The payload mechanics live in the `records` schema description, which is what the model
        // reads while it builds that argument.
        return 'Create or update one or more records (writes). '
            .'Pass a records array, one entry per record, even for a single record. '
            .'For a small correction inside an existing field prefer replaceText or patchText.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'records' => [
                    // Not a ['array','string'] union: the union let tool-calling layers pick the string
                    // branch, and a batch escaped into a JSON string burns enough extra tokens that it
                    // gets cut off mid-record. RecordsArgumentDecoder still accepts a string, but the
                    // schema no longer invites one.
                    'type' => 'array',
                    'description' => 'Array of records. Each: {table, fields, pid?, uid?, position?}. '
                        .'pid/uid/position are siblings of `fields`, never inside it. '
                        .'Use "$ref:N" (0-based index) in a field value to reference the UID of a record created earlier in the same batch. '
                        .'Container children: create the container first, then set `tx_container_parent: "$ref:0"` and a `colPos` from its grid (listContentTypes). '
                        .'IRRE children (accordion_item, card_group_item, …): either nest them as objects in the parent\'s inline field (they are expanded into their own records automatically, only when creating the parent) or write them yourself with their own `pid` and a reference back to the parent. '
                        .'Images: nest {uid_local:<sysFile UID>} objects in the image/assets field, or add explicit sys_file_reference records {uid_local, uid_foreign:"$ref:N", tablenames, fieldname, pid} — never put a bare sys_file UID into the image/assets field. '
                        .'`sorting` is not writable; reorder with moveRecords. '
                        .'FlexForm fields (pi_flexform, …) take a nested object {"data": {"<sheet>": {"lDEF": {"<field>": {"vDEF": <value>}}}}} — '
                        .'call readFlexFormSchema first for the sheets and fields, never invent them and never pass XML. '
                        .'TCA-required fields are enforced on create (readRecordSchema lists them).',
                    'items' => ['type' => 'object'],
                ],
                'position' => ['type' => 'string', 'default' => 'end', 'description' => 'Default position for the first tt_content record: "start", "end" (default), "after:UID", or "after:$ref:N" to place it after a record created earlier in this same batch. Records already keep their batch order, so you rarely need this.'],
                'atomic' => ['type' => 'boolean', 'default' => false, 'description' => 'All-or-nothing: roll back already-applied changes if any record fails. Default false (best-effort, partial writes kept).'],
                'allowEmptyContainer' => ['type' => 'boolean', 'default' => false, 'description' => 'Permit creating a container element that gets no children in this call. Off by default: an empty container renders as an empty box, so the batch is refused and tells you how to wire the children up.'],
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

        // Accept `type` as an alias for CType/doktype before anything reads the element kind.
        $records = $this->typeAliasNormalizer->normalize($records);

        // Rewrite nested inline children into sibling records before anything else runs, so the
        // batch, `$ref:N` resolution and the atomic rollback all see one flat list.
        $records = $this->nestedChildExpander->expand($records);

        // The only point that sees the whole batch before a single row is written.
        $this->containerBatchValidator->assertContainersHaveChildren(
            $records,
            (bool) ($params['allowEmptyContainer'] ?? false),
        );

        $batchPosition = (string) ($params['position'] ?? 'end');
        $atomic = (bool) ($params['atomic'] ?? false);

        $createdUids = [];
        $lastSiblingByGroup = [];

        if ($atomic) {
            return $this->writeAtomic($records, $batchPosition, $createdUids, $lastSiblingByGroup);
        }

        return $this->batchResultBuilder->run(
            $records,
            'record(s)',
            function (mixed $record, int $index) use (&$createdUids, &$lastSiblingByGroup, $batchPosition): array {
                $applied = $this->applyRecord($record, $index, $createdUids, $lastSiblingByGroup, $batchPosition, false);

                return [
                    'message' => $applied['message'],
                    'uid' => $applied['uid'],
                    'table' => $applied['rollback']['table'],
                    'action' => $applied['rollback']['op'],
                ];
            },
        );
    }

    /**
     * @param array<int, mixed>  $records
     * @param array<int, int>    $createdUids
     * @param array<string, int> $lastSiblingByGroup
     */
    private function writeAtomic(array $records, string $batchPosition, array &$createdUids, array &$lastSiblingByGroup): CallToolResult
    {
        /** @var list<array{op: string, table: string, uid: int, before?: array<string, mixed>}> $applied */
        $applied = [];
        $lines = [];
        $index = 0;

        foreach ($records as $record) {
            ++$index;

            try {
                $result = $this->applyRecord($record, $index, $createdUids, $lastSiblingByGroup, $batchPosition, true);
            } catch (\Throwable $e) {
                $rolledBackCleanly = $this->rollback($applied);
                $type = $e instanceof McpException ? $e->getErrorType() : McpErrorType::DataHandlerError;

                return $this->errorResult(
                    sprintf(
                        "Atomic batch aborted at record #%d: %s\n%d already-applied change(s) were rolled back — nothing was persisted.%s",
                        $index,
                        $e->getMessage(),
                        count($applied),
                        $rolledBackCleanly ? '' : ' WARNING: rollback itself reported errors, check the MCP log.',
                    ),
                    $type,
                );
            }

            $applied[] = $result['rollback'];
            $lines[] = sprintf('#%d: ✅ %s', $index, $result['message']);
        }

        // Same `batch` envelope the non-atomic path emits via BatchResultBuilderService — a client
        // must not have to parse UIDs out of the text just because it asked for atomicity.
        // failedCount is always 0 here: any failure aborts above with errorResult().
        $records = array_map(
            static fn (array $op): array => ['table' => $op['table'], 'uid' => $op['uid'], 'action' => $op['op']],
            $applied,
        );

        return $this->structuredResult(
            sprintf("## Atomic batch: %d record(s) written\n\n%s", $index, implode("\n", $lines)),
            ['batch' => [
                'total' => $index,
                'succeededUids' => array_column($applied, 'uid'),
                'failedCount' => 0,
                'records' => $records,
            ]],
        );
    }

    /**
     * Undo applied operations in reverse order. Returns false if any single undo failed.
     *
     * @param list<array{op: string, table: string, uid: int, before?: array<string, mixed>}> $applied
     */
    private function rollback(array $applied): bool
    {
        $clean = true;

        foreach (array_reverse($applied) as $op) {
            try {
                if ('create' === $op['op']) {
                    $this->recordWrite->delete($op['table'], $op['uid']);
                } else {
                    $this->recordWrite->update($op['table'], $op['uid'], $op['before'] ?? []);
                }
            } catch (\Throwable $e) {
                $clean = false;
                $this->logger->error('Atomic rollback failed for an operation', [
                    'op' => $op['op'],
                    'table' => $op['table'],
                    'uid' => $op['uid'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $clean;
    }

    /**
     * Validate and write a single record. Returns the human message, the resulting
     * UID and a rollback descriptor for atomic mode.
     *
     * @param array<int, int>    $createdUids
     * @param array<string, int> $lastSiblingByGroup
     *
     * @return array{message: string, uid: int, rollback: array{op: string, table: string, uid: int, before?: array<string, mixed>}}
     */
    private function applyRecord(mixed $record, int $index, array &$createdUids, array &$lastSiblingByGroup, string $batchPosition, bool $captureBefore): array
    {
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
        $fields = $this->normalizeRemainingArrayValues($table, $fields);

        $position = $this->resolvePositionReference((string) ($record['position'] ?? ''), $createdUids);
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
                    'Missing required field(s) for %s: %s. Provide them — see readRecordSchema for the required fields of this type.',
                    $this->tcaLabel->getTableLabel($table),
                    implode(', ', $missingRequired),
                ));
            }

            $result = $this->recordWrite->create($table, $pid, $fields, $position);
            $createdUids[$zeroBased] = $result->uid;
            if ('tt_content' === $table) {
                $lastSiblingByGroup[$groupKey] = $result->uid;
            }

            return [
                'message' => sprintf('%s created (UID: %d)%s', $this->tcaLabel->getTableLabel($table), $result->uid, $this->strippedHint($result)),
                'uid' => $result->uid,
                'rollback' => ['op' => 'create', 'table' => $table, 'uid' => $result->uid],
            ];
        }

        $this->recordAccess->assertRecordEditAccess($table, $uid);

        $before = [];
        if ($captureBefore) {
            $existing = BackendUtility::getRecordWSOL($table, $uid);
            foreach (array_keys($fields) as $fieldName) {
                $before[(string) $fieldName] = is_array($existing) ? ($existing[$fieldName] ?? null) : null;
            }
        }

        $result = $this->recordWrite->update($table, $uid, $fields);
        $createdUids[$zeroBased] = $uid;

        return [
            'message' => sprintf('%s updated (UID: %d)%s', $this->tcaLabel->getTableLabel($table), $uid, $this->strippedHint($result)),
            'uid' => $uid,
            'rollback' => ['op' => 'update', 'table' => $table, 'uid' => $uid, 'before' => $before],
        ];
    }

    /**
     * Last stop before DataHandler. Anything still holding an array here would die inside
     * checkValue_SW() with a bare "Array to string conversion", which tells the model nothing
     * and invites an identical retry. FlexForm values are exempt: DataHandlerSanitizerService
     * normalises those and they are legitimately nested.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function normalizeRemainingArrayValues(string $table, array $fields): array
    {
        foreach ($fields as $field => $value) {
            if (!is_array($value)) {
                continue;
            }

            $field = (string) $field;
            $config = $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
            $type = (string) ($config['type'] ?? '');

            if ('flex' === $type) {
                continue;
            }

            if ('sys_file_reference' === ($config['foreign_table'] ?? '')) {
                throw (new InvalidParameterException(sprintf(
                    '`%s` is a file field and cannot take a list of UIDs. Nest the references as objects ({uid_local: <sysFile UID>, …}) or add explicit sys_file_reference records.',
                    $field,
                )))->withErrorContext(['table' => $table, 'field' => $field]);
            }

            // A plain list of UIDs is what select/group/inline fields expect, just comma separated.
            if (array_is_list($value) && $this->isScalarList($value)) {
                $fields[$field] = implode(',', array_map(strval(...), $value));

                continue;
            }

            throw (new InvalidParameterException(sprintf(
                '`%s` (TCA type "%s") cannot take a nested value. Only inline fields expand nested child objects; every other field needs a scalar.',
                $field,
                '' !== $type ? $type : 'unknown',
            )))->withErrorContext(['table' => $table, 'field' => $field]);
        }

        return $fields;
    }

    /**
     * @param array<int, mixed> $value
     */
    private function isScalarList(array $value): bool
    {
        foreach ($value as $entry) {
            if (!is_scalar($entry)) {
                return false;
            }
        }

        return true;
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
                if (!isset($createdUids[$refIndex])) {
                    // Used to fall through and hand the literal "$ref:5" to the DataHandler, which
                    // wrote it into the column. A reference that resolves to nothing is a broken
                    // record, not a value.
                    throw new InvalidParameterException(sprintf(
                        'Field `%s` references record %d ("$ref:%d"), which was not created in this batch. '
                            .'A $ref may only point at a record created EARLIER in the same call, by its 0-based index.',
                        (string) $field,
                        $refIndex,
                        $refIndex,
                    ));
                }
                $fields[$field] = $createdUids[$refIndex];
            }
        }

        return $fields;
    }

    /**
     * `after:$ref:0` places a record after another one created earlier in the same batch. The $ref
     * only resolved inside `fields` before, so a model that ordered records this way (a natural
     * thing to do) got the literal string as a position, the batch fell back to default ordering,
     * and a follow-up moveRecords was needed to fix it.
     *
     * @param array<int, int> $createdUids
     */
    private function resolvePositionReference(string $position, array $createdUids): string
    {
        if (1 !== preg_match('/^after:\$ref:(\d+)$/', $position, $matches)) {
            return $position;
        }

        $refIndex = (int) $matches[1];
        if (!isset($createdUids[$refIndex])) {
            throw new InvalidParameterException(sprintf(
                'position "after:$ref:%d" references record %d, which was not created earlier in this batch. '
                    .'A $ref may only point at a record created EARLIER in the same call, by its 0-based index.',
                $refIndex,
                $refIndex,
            ));
        }

        return 'after:'.$createdUids[$refIndex];
    }

    private function strippedHint(RecordWriteResult $result): string
    {
        if ([] === $result->strippedFields) {
            return '';
        }

        return sprintf(
            ' — note: HTML removed from non-RTE field(s): %s (use readRecordSchema to check which fields allow HTML/RTE)',
            implode(', ', $result->strippedFields),
        );
    }
}
