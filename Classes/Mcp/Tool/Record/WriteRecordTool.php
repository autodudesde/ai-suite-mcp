<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Dto\RecordWriteResult;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\McpException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchEntryValidator;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\ContainerBatchValidator;
use AutoDudes\AiSuiteMcp\Mcp\Service\NestedChildExpanderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordTypeAliasNormalizer;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordWriteService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TranslationExpanderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TranslationFieldAliasNormalizer;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\BatchDefaults;
use AutoDudes\AiSuiteMcp\Mcp\Utility\RecordsArgumentDecoder;
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
        private readonly RecordWriteService $recordWrite,
        private readonly BatchResultBuilderService $batchResultBuilder,
        private readonly NestedChildExpanderService $nestedChildExpander,
        private readonly ContainerBatchValidator $containerBatchValidator,
        private readonly BatchEntryValidator $batchEntryValidator,
        private readonly RecordTypeAliasNormalizer $typeAliasNormalizer,
        private readonly TranslationFieldAliasNormalizer $translationAliasNormalizer,
        private readonly TranslationExpanderService $translationExpander,
        private readonly RecordRepository $recordRepository,
        private readonly WorkspaceRecordService $workspaceRecords,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'writeRecords';
    }

    public function getDescription(): string
    {
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
                    'type' => 'array',
                    'description' => 'Array of records. Each: {table, fields, pid?, uid?, position?}. '
                        .'pid/uid/position are siblings of `fields`, never inside it. '
                        .'Use "$ref:N" (0-based) in a field value for the UID of a record created earlier in this batch. '
                        .'Container children: create the container first, then set `tx_container_parent: "$ref:0"` and a `colPos` from its grid (listContentTypes). '
                        .'IRRE children (accordion_item, card_group_item, …): nest them as objects in the parent\'s inline field (expanded into their own records automatically, on create only), or write them yourself with their own `pid` and a reference back to the parent. '
                        .'Never write the parent\'s inline field as a list of child UIDs — that list is read as the complete set and renumbers the children. '
                        .'Images: nest {uid_local:<sysFile UID>} objects in the image/assets field, or add explicit sys_file_reference records {uid_local, uid_foreign:"$ref:N", tablenames, fieldname, pid} — never a bare sys_file UID there. '
                        .'`sorting` is not writable; reorder with moveRecords. '
                        .'FlexForm fields (pi_flexform, …) take a nested object {"data": {"<sheet>": {"lDEF": {"<field>": {"vDEF": <value>}}}}} — '
                        .'call readFlexFormSchema first for the sheets and fields, never invent them and never pass XML. '
                        .'TCA-required fields are enforced on create (readRecordSchema lists them). '
                        .'Translations: add `translations` next to `fields`, keyed by ISO code — {"en": {"header": "…"}}; created, or updated if it exists, with the parent pointer set for you.',
                    'items' => ['type' => 'object'],
                ],
                'table' => ['type' => 'string', 'description' => 'Default TCA table for entries that do not carry their own `table`. Convenient for a batch that writes to one table.'],
                'position' => ['type' => 'string', 'default' => 'end', 'description' => 'Position of the first tt_content record: "start", "end", "after:UID", or "after:$ref:N" for a record created earlier in this batch. Records keep their batch order, so you rarely need this.'],
                'atomic' => ['type' => 'boolean', 'default' => false, 'description' => 'All-or-nothing: roll back already-applied changes if any record fails. Best-effort otherwise, partial writes kept.'],
                'allowEmptyContainer' => ['type' => 'boolean', 'default' => false, 'description' => 'Permit creating a container element that gets no children in this call. Refused by default because an empty container renders as an empty box; the error names how to wire the children up.'],
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

        $records = BatchDefaults::applyTable($records, (string) ($params['table'] ?? ''));

        // Before expansion, so the reported indices are the ones the caller sent.
        $this->batchEntryValidator->assertShape($records, 'records', ['table', 'fields'], ['uid', 'pid'], 'fields');

        $records = $this->typeAliasNormalizer->normalize($records);

        $records = $this->nestedChildExpander->expand($records);

        // After the children, so a translation is never interleaved into its parent's child block.
        $records = $this->translationExpander->expand($records);

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
     * @param array<string, mixed> $fields
     */
    private function assertNoChildCollectionWrites(string $table, array $fields, ?string $typeKey, ?int $uid): void
    {
        foreach (array_keys($fields) as $field) {
            $field = (string) $field;
            if (!$this->storesChildCount($table, $field, $typeKey)) {
                continue;
            }

            $config = $this->fieldConfig($table, $field, $typeKey);
            $childTable = (string) ($config['foreign_table'] ?? '');
            $parentField = (string) ($config['foreign_field'] ?? 'parent');

            throw (new InvalidParameterException(sprintf(
                '`%s` is a child collection and is not writable: the column holds the number of children, '
                    .'and DataHandler reads whatever you send as the complete list of child UIDs, detaching the rest and renumbering their sorting. '
                    .'%s Read the current children with readChildren.',
                $field,
                null !== $uid
                    ? sprintf('Write each child of `%s` as its own record with `%s`: %d and its own `pid`.', $childTable, $parentField, $uid)
                    : sprintf('Nest the children as objects inside `%s`; they are expanded into their own `%s` records.', $field, $childTable),
            )))->withErrorContext(['table' => $table, 'field' => $field, 'uid' => $uid]);
        }
    }

    private function storesChildCount(string $table, string $field, ?string $typeKey): bool
    {
        $config = $this->fieldConfig($table, $field, $typeKey);

        return in_array((string) ($config['type'] ?? ''), ['inline', 'file'], true)
            && '' !== (string) ($config['foreign_field'] ?? '');
    }

    /**
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

        if (is_array($record) && isset($record[TranslationExpanderService::LOCALIZE_KEY])) {
            return $this->applyTranslation($record, $zeroBased, $createdUids, $captureBefore);
        }

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
        $aliased = $this->translationAliasNormalizer->normalizeFields($table, $fields);
        $fields = $aliased['fields'];
        $fields = $this->recordAccess->filterAccessibleFields($table, $fields);
        $refFields = $this->referenceFieldNames($fields);
        $fields = $this->resolveReferences($fields, $createdUids);
        $typeKey = $this->resolveTypeKey($table, $fields, $uid);
        $this->assertNoChildCollectionWrites($table, $fields, $typeKey, $uid);
        $fields = $this->normalizeRemainingArrayValues($table, $fields, $typeKey);
        $remapped = [];
        $fields = $this->resolveRelationTargets($table, $fields, $typeKey, $refFields, $remapped);

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
                'message' => sprintf(
                    '%s created (UID: %d)%s%s%s',
                    $this->tcaLabel->getTableLabel($table),
                    $result->uid,
                    $this->strippedHint($result),
                    $this->remapHint($remapped),
                    $this->translationAliasNormalizer->describeRenames($aliased['renamed']),
                ),
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
            'message' => sprintf(
                '%s updated (UID: %d)%s%s%s',
                $this->tcaLabel->getTableLabel($table),
                $uid,
                $this->strippedHint($result),
                $this->remapHint($remapped),
                $this->translationAliasNormalizer->describeRenames($aliased['renamed']),
            ),
            'uid' => $uid,
            'rollback' => ['op' => 'update', 'table' => $table, 'uid' => $uid, 'before' => $before],
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @param array<int, int>      $createdUids
     *
     * @return array{message: string, uid: int, rollback: array{op: string, table: string, uid: int, before?: array<string, mixed>}}
     */
    private function applyTranslation(array $record, int $zeroBased, array &$createdUids, bool $captureBefore): array
    {
        $table = (string) ($record['table'] ?? '');
        $descriptor = is_array($record[TranslationExpanderService::LOCALIZE_KEY] ?? null)
            ? $record[TranslationExpanderService::LOCALIZE_KEY]
            : [];
        $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];

        $origin = $descriptor['origin'] ?? null;
        $language = (string) ($descriptor['language'] ?? '');

        $this->recordAccess->validateTableWriteAccess($table);

        $originUid = is_string($origin)
            ? (int) $this->resolveReferences(['origin' => $origin], $createdUids)['origin']
            : (int) $origin;
        if ($originUid <= 0) {
            throw new InvalidParameterException('The record to translate could not be resolved.');
        }

        $this->recordAccess->assertRecordEditAccess($table, $originUid);

        $pageId = 'pages' === $table ? $originUid : (int) (BackendUtility::getRecordWSOL($table, $originUid)['pid'] ?? 0);
        $languageUid = $this->recordAccess->resolveLanguageUid($language, $pageId);
        if (0 === $languageUid) {
            throw new InvalidParameterException(sprintf(
                'Language "%s" is not configured for this page, or it is the default language — that is the record you are already writing. readServerInfo lists the codes of every site.',
                $language,
            ));
        }
        $this->recordAccess->assertLanguageAccess($languageUid);

        $pointerField = $this->tcaCompatibilityService->getTranslationOriginPointerFieldName($table);
        $languageField = $this->tcaCompatibilityService->getLanguageFieldName($table);
        if (null === $pointerField || null === $languageField) {
            throw new InvalidParameterException(sprintf('Table "%s" does not support translations.', $table));
        }

        $existingUid = $this->recordRepository->findTranslationUid($table, $originUid, $languageUid, $pointerField, $languageField);
        $created = null === $existingUid;
        $translationUid = $existingUid ?? $this->localize($table, $originUid, $languageUid);

        $before = [];
        if ($captureBefore && !$created) {
            $existing = BackendUtility::getRecordWSOL($table, $translationUid);
            foreach (array_keys($fields) as $fieldName) {
                $before[(string) $fieldName] = is_array($existing) ? ($existing[$fieldName] ?? null) : null;
            }
        }

        $strippedHint = '';
        $aliasHint = '';
        if ([] !== $fields) {
            $aliased = $this->translationAliasNormalizer->normalizeFields($table, $fields);
            $aliasHint = $this->translationAliasNormalizer->describeRenames($aliased['renamed']);
            $writeFields = $this->recordAccess->filterAccessibleFields($table, $aliased['fields']);
            $writeFields = $this->resolveReferences($writeFields, $createdUids);
            $result = $this->recordWrite->update($table, $translationUid, $writeFields);
            $strippedHint = $this->strippedHint($result);
        }

        $createdUids[$zeroBased] = $translationUid;

        return [
            'message' => sprintf(
                '%s translated to %s (UID: %d, %s)%s%s',
                $this->tcaLabel->getTableLabel($table),
                $language,
                $translationUid,
                $created ? 'created, hidden as TYPO3 does it' : 'updated existing translation',
                $strippedHint,
                $aliasHint,
            ),
            'uid' => $translationUid,
            'rollback' => $created
                ? ['op' => 'create', 'table' => $table, 'uid' => $translationUid]
                : ['op' => 'update', 'table' => $table, 'uid' => $translationUid, 'before' => $before],
        ];
    }

    private function localize(string $table, int $originUid, int $languageUid): int
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], [$table => [$originUid => ['localize' => $languageUid]]]);
        $dataHandler->process_cmdmap();

        if ([] !== $dataHandler->errorLog) {
            throw $this->dataHandlerError->toException('localization', $table, $originUid, $dataHandler->errorLog);
        }

        $newUid = (int) ($dataHandler->copyMappingArray[$table][$originUid] ?? 0);
        if ($newUid <= 0) {
            throw new InvalidParameterException(sprintf(
                'TYPO3 created no translation of %s:%d — the record may already be a translation itself.',
                $table,
                $originUid,
            ));
        }

        return $newUid;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function resolveTypeKey(string $table, array $fields, ?int $uid): ?string
    {
        try {
            $row = $fields;
            if (null !== $uid) {
                $existing = BackendUtility::getRecordWSOL($table, $uid);
                if (is_array($existing)) {
                    $row = $fields + $existing;
                }
            }

            return $this->tcaCompatibilityService->resolveSubSchemaType($table, $row);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A type-resolved config is empty for a column outside that type's showitem, hence the fallback.
     *
     * @return array<string, mixed>
     */
    private function fieldConfig(string $table, string $field, ?string $typeKey): array
    {
        if (null !== $typeKey) {
            $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $field);
            if ([] !== $config) {
                return $config;
            }
        }

        return $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    private function referenceFieldNames(array $fields): array
    {
        $names = [];
        foreach ($fields as $field => $value) {
            if (is_string($value) && 1 === preg_match('/^\$ref:\d+$/', $value)) {
                $names[] = (string) $field;
            }
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string>         $refFields
     * @param array<string, int>   $remapped
     *
     * @return array<string, mixed>
     */
    private function resolveRelationTargets(string $table, array $fields, ?string $typeKey, array $refFields, array &$remapped): array
    {
        if (!$this->workspaceRecords->isActive()) {
            return $fields;
        }

        foreach ($fields as $field => $value) {
            $field = (string) $field;
            // $ref values already carry the uid DataHandler handed back, which is the version uid.
            if (in_array($field, $refFields, true) || !is_scalar($value)) {
                continue;
            }

            $uid = (int) $value;
            if ($uid <= 0 || (string) $uid !== trim((string) $value)) {
                continue;
            }

            $config = $this->fieldConfig($table, $field, $typeKey);
            $foreignTable = (string) ($config['foreign_table'] ?? '');
            if ('' === $foreignTable || !in_array((string) ($config['type'] ?? ''), ['select', 'group', 'inline'], true)) {
                continue;
            }

            $target = $this->workspaceRecords->resolveWriteTarget($foreignTable, $uid);
            if ($target !== $uid) {
                $fields[$field] = $target;
                $remapped[$field] = $target;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, int> $remapped
     */
    private function remapHint(array $remapped): string
    {
        if ([] === $remapped) {
            return '';
        }

        $parts = [];
        foreach ($remapped as $field => $target) {
            $parts[] = sprintf('%s → %d', $field, $target);
        }

        return sprintf(' (resolved to the workspace version: %s)', implode(', ', $parts));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function normalizeRemainingArrayValues(string $table, array $fields, ?string $typeKey): array
    {
        foreach ($fields as $field => $value) {
            if (!is_array($value)) {
                continue;
            }

            $field = (string) $field;
            $config = $this->fieldConfig($table, $field, $typeKey);
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

            // A plain list of UIDs is what select/group fields expect, just comma separated.
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
