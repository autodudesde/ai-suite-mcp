<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\FormEngine\TcaDatabaseRecordForReading;
use AutoDudes\AiSuiteMcp\Mcp\Service\ChildRecordTemplate;
use AutoDudes\AiSuiteMcp\Mcp\Service\FieldCurationService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordSelectionService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RelationResolutionService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TranslationFieldAliasNormalizer;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;
use TYPO3\CMS\Backend\Form\Exception\AccessDeniedException;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ReadRecordTool extends AbstractDataTool
{
    private const HTML_STRIPPED_NOTE = "  ↳ ℹ️ HTML was stripped for this preview — re-read with `raw: true` to get the source markup before editing.\n";

    /**
     * @var list<string>
     */
    private const IDENTITY_FIELDS = ['uid', 'pid'];

    private const CTRL_SYSTEM_FIELDS = ['sortby', 'tstamp', 'crdate'];

    private const CTRL_TIMESTAMP_FIELDS = ['tstamp', 'crdate'];

    protected ?string $requiredScope = null;
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

    /** @var null|list<string> */
    private ?array $allowedFields = null;

    /** @var array<string, string> */
    private array $fieldAliasRenames = [];

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly FieldCurationService $fieldCuration,
        private readonly RelationResolutionService $relationResolver,
        private readonly TranslationFieldAliasNormalizer $translationAliasNormalizer,
        private readonly WorkspaceRecordService $workspaceRecords,
        private readonly ChildRecordTemplate $childRecordTemplate,
        private readonly RecordSelectionService $recordSelection,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readRecords';
    }

    public function getDescription(): string
    {
        return 'Read records of any TCA table — one by uid, a whole page by pid, a whole subtree by rootPageId, '
            .'or an exact-match query by filters. Reads the stored rows; readRenderedPage shows the page as a visitor sees it. '
            .'Only records inside your backend webmounts are returned.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'TCA table name'],
                'uid' => ['type' => 'integer', 'description' => 'Single record UID'],
                'pid' => ['type' => 'integer', 'description' => 'Page UID — list all records on this page'],
                'rootPageId' => [
                    'type' => 'integer',
                    'description' => 'Subtree root page UID: this page and everything below it. '
                        .'Combine with filters to sweep a branch in one call, e.g. table "pages" with {"seo_title": ""}. '
                        .'Alternative to pid; give exactly one of the two.',
                ],
                'filters' => [
                    'type' => 'object',
                    'description' => 'Field name => value, exact match. An empty string "" finds records whose field is empty, e.g. {"description": ""}. '
                        .'For the children of a container/IRRE parent, filter on the relation field, e.g. table "tx_bootstrappackage_card_group_item" with {"tt_content": PARENT_UID}.',
                ],
                'limit' => ['type' => 'integer', 'default' => 50, 'description' => 'Max records, up to 200.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'description' => 'Skip first N records for pagination.'],
                'fullText' => ['type' => 'boolean', 'default' => false, 'description' => 'Return long text fields untruncated. Ignored (always full) for a single-uid read. '
                    .'On a list read this pulls every record\'s full text into the conversation, where it stays. '
                    .'Use it when you are about to edit the text; readRecordSchema shows which fields exist, `fields` shows one value.'],
                'maxLength' => ['type' => 'integer', 'description' => 'Truncate text fields to this many characters in list mode (default 300). Use 0 or fullText=true for no truncation.'],
                'raw' => ['type' => 'boolean', 'default' => false, 'description' => 'Return verbatim stored values (markup intact, untruncated, no tag stripping). Required before editing bodytext/RTE fields to round-trip the HTML.'],
                'includeEmpty' => ['type' => 'boolean', 'default' => true, 'description' => 'Include empty-valued fields; set false to show only populated ones. On by default so that empty-field searches work.'],
                'includeSystem' => ['type' => 'boolean', 'default' => false, 'description' => 'Include housekeeping/system fields (timestamps, versioning, sorting). Hidden by default as noise.'],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Return only these fields. uid and the label field are always included. Cuts a tt_content read from 60+ lines to a handful; use it whenever you know the field you are after.',
                ],
            ],
            'required' => ['table'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = isset($params['uid']) ? (int) $params['uid'] : null;
        $pid = isset($params['pid']) ? (int) $params['pid'] : null;
        $rootPageId = isset($params['rootPageId']) ? (int) $params['rootPageId'] : null;
        $filters = is_array($params['filters'] ?? null) ? $params['filters'] : [];
        $limit = min((int) ($params['limit'] ?? 50), 200);
        $offset = (int) ($params['offset'] ?? 0);
        $raw = (bool) ($params['raw'] ?? false);
        $includeEmpty = (bool) ($params['includeEmpty'] ?? true);
        $includeSystem = (bool) ($params['includeSystem'] ?? false);

        $listMaxLength = 300;
        if ((bool) ($params['fullText'] ?? false)) {
            $listMaxLength = null;
        } elseif (isset($params['maxLength'])) {
            $listMaxLength = (int) $params['maxLength'] > 0 ? (int) $params['maxLength'] : null;
        }

        if (null === $uid && null === $pid && null === $rootPageId && empty($filters)) {
            return $this->textError(RecordSelectionService::MISSING_SCOPE);
        }

        $this->recordAccess->validateTableReadAccess($table);
        $this->allowedFields = $this->resolveAllowedFields($table, $params);

        if (null !== $uid) {
            $record = $this->recordAccess->assertRecordReadAccess($table, $uid);

            if ($this->workspaceRecords->isDeletePlaceholder($record)) {
                return $this->textResult(sprintf(
                    '%s:%d is deleted in workspace %d. The live record still exists and reappears if the deletion is discarded.',
                    $table,
                    $uid,
                    $this->workspaceRecords->getWorkspaceId(),
                ));
            }

            $formatted = $this->loadAndFormatRecord($table, $uid, null, $raw, $includeEmpty, $includeSystem, false);
            if (null === $formatted) {
                return $this->textError(sprintf('%s:%d not found.', $table, $uid));
            }

            return $this->textResult(sprintf(
                "Record `%s:%d`:%s\n\n%s",
                $table,
                $uid,
                $this->translationAliasNormalizer->describeRenames($this->fieldAliasRenames),
                $formatted,
            ));
        }

        $selection = $this->recordSelection->select($table, $pid, $rootPageId, $filters, $limit + 1, $offset);
        if (is_string($selection)) {
            return $this->textError($selection);
        }
        if (null !== $selection['note']) {
            return $this->textResult($selection['note']);
        }

        $uids = $selection['uids'];
        $sanitizedFilters = $selection['filters'];
        $context = $selection['context'];

        if (empty($uids)) {
            return $this->textResult(sprintf('No %s records %s.', $table, $context));
        }

        $truncated = count($uids) > $limit;
        if ($truncated) {
            $uids = array_slice($uids, 0, $limit);
        }

        $text = sprintf(
            "%d record(s) `%s` %s:%s\n\n",
            count($uids),
            $table,
            $context,
            $this->translationAliasNormalizer->describeRenames($this->fieldAliasRenames),
        );
        foreach ($uids as $recordUid) {
            $formatted = $this->loadAndFormatRecord($table, (int) $recordUid, $listMaxLength, $raw, $includeEmpty, $includeSystem, true);
            if (null !== $formatted) {
                $text .= "---\n".$formatted."\n";
            }
        }

        if ($truncated) {
            $text .= sprintf(
                "\n⚠️ More records match than shown — this is NOT the complete set. Showing %d (limit) from offset %d. "
                ."Raise `limit` (max 200), page with `offset` (next: %d), or narrow with `filters`.\n",
                $limit,
                $offset,
                $offset + $limit,
            );
        }

        if ([] === $sanitizedFilters) {
            return $this->textResult($text);
        }

        return $this->withFoundRecords(
            $this->textResult($text),
            array_values(array_map(static fn (int|string $uid): array => ['table' => $table, 'uid' => (int) $uid], $uids)),
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return null|list<string>
     */
    private function resolveAllowedFields(string $table, array $params): ?array
    {
        $requested = $params['fields'] ?? null;
        if (!is_array($requested) || [] === $requested) {
            return null;
        }

        $names = array_values(array_unique(array_map(static fn ($v): string => (string) $v, $requested)));

        $aliased = $this->translationAliasNormalizer->normalizeNames($table, $names);
        $names = $aliased['names'];
        $this->fieldAliasRenames = $aliased['renamed'];

        $this->recordAccess->filterAccessibleFields(
            $table,
            array_fill_keys(array_diff($names, self::IDENTITY_FIELDS, array_keys($this->ctrlSystemFields($table))), ''),
        );

        $names[] = 'uid';
        $names[] = $this->tcaCompatibilityService->getLabelField($table);

        return array_values(array_unique(array_filter($names, static fn (string $n): bool => '' !== $n)));
    }

    private function loadAndFormatRecord(string $table, int $uid, ?int $maxLength, bool $raw, bool $includeEmpty, bool $includeSystem, bool $listMode): ?string
    {
        if ($raw) {
            $record = BackendUtility::getRecordWSOL($table, $uid);
            if (null === $record) {
                return null;
            }

            return $this->formatRecordRaw($table, $record, $includeSystem, $listMode);
        }

        try {
            $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class);
            $formData = $formDataCompiler->compile(
                [
                    'request' => $this->formDataRequest(),
                    'tableName' => $table,
                    'vanillaUid' => $this->workspaceRecords->resolveWriteTarget($table, $uid),
                    'command' => 'edit',
                    'returnUrl' => '',
                    'defaultValues' => [],
                ],
                GeneralUtility::makeInstance(TcaDatabaseRecordForReading::class),
            );
        } catch (\Throwable $e) {
            $this->logger->log($e instanceof AccessDeniedException ? LogLevel::INFO : LogLevel::WARNING, 'ReadRecordTool: FormDataCompiler failed, falling back to BackendUtility::getRecordWSOL', [
                'table' => $table,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
            $record = BackendUtility::getRecordWSOL($table, $uid);
            if (null === $record) {
                return null;
            }

            return $this->formatRecordFallback($table, $record, $maxLength, $includeEmpty, $includeSystem, $listMode);
        }

        $databaseRow = $formData['databaseRow'] ?? [];
        if (empty($databaseRow)) {
            return null;
        }

        $columnsToProcess = array_values(array_unique($formData['columnsToProcess'] ?? []));
        if (null !== $this->allowedFields) {
            $columnsToProcess = array_values(array_intersect($columnsToProcess, $this->allowedFields));
        }
        $labelField = $this->tcaCompatibilityService->getLabelField($table);

        $label = $databaseRow[$labelField] ?? '';
        if ($label instanceof \DateTimeInterface) {
            $label = $label->format('Y-m-d H:i:s');
        }
        if (\is_array($label)) {
            $label = implode(', ', array_map(
                static fn ($item) => \is_array($item) ? ($item['title'] ?? $item['label'] ?? json_encode($item)) : (string) $item,
                $label,
            ));
        }

        $rawRecord = BackendUtility::getRecordWSOL($table, $uid) ?? [];
        $typeKey = $this->richtextTypeKey($table, $rawRecord);

        $text = $this->headerLine($table, $uid, (string) $label, $rawRecord);

        $htmlStripped = false;
        $fileFields = [];
        foreach ($columnsToProcess as $field) {
            if (!$this->recordAccess->canAccessField($table, $field)) {
                continue;
            }
            if (!$this->fieldCuration->shouldInclude($field, $databaseRow[$field] ?? null, $includeEmpty, $includeSystem, $this->allowedFields)) {
                continue;
            }

            if (!$htmlStripped
                && $this->previewWouldDropTags($rawRecord[$field] ?? null)
                && $this->isEditorialRichtextField($table, $typeKey, $field)
            ) {
                $htmlStripped = true;
            }

            $value = $databaseRow[$field] ?? null;
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            if (\is_array($value)) {
                $value = implode(', ', array_map(
                    static fn ($item) => \is_array($item) ? self::relationItemLabel($item) : (string) $item,
                    $value,
                ));
            }

            if (!$listMode) {
                $value = $this->relationResolver->resolveFileField($table, (string) $field, $rawRecord) ?? $value;
                $config = $this->tcaCompatibilityService->getFieldConfiguration($table, (string) $field);
                if ('file' === ($config['type'] ?? '') || ('inline' === ($config['type'] ?? '') && '' !== (string) ($config['foreign_selector'] ?? ''))) {
                    $fileFields[(string) $field] = $config;
                }
            }

            $display = $this->outputFormatter->displayValue($value, $maxLength);

            $text .= sprintf("  `%s` (%s): %s\n", $field, $this->tcaLabel->getFieldLabel($table, $field), $display);
        }

        $text .= $this->renderCtrlSystemFields($table, $rawRecord, $includeSystem);
        if ($includeSystem && null === $this->allowedFields) {
            $text .= $this->renderWorkspaceState($table, $rawRecord);
        }
        $text .= $this->renderContainerContext($table, $databaseRow);
        $text .= $this->renderFileFieldHint($table, $uid, (int) ($rawRecord['pid'] ?? 0), $fileFields);
        if ($htmlStripped) {
            $text .= self::HTML_STRIPPED_NOTE;
        }

        return $text;
    }

    /**
     * @param array<string, array<string, mixed>> $fileFields
     */
    private function renderFileFieldHint(string $table, int $uid, int $pid, array $fileFields): string
    {
        if ([] === $fileFields) {
            return '';
        }

        $names = array_keys($fileFields);
        $fieldname = 1 === count($names) ? $names[0] : '<one of: '.implode(', ', $names).'>';

        return sprintf(
            "  To add a file to %s, write a record of its own (nesting it into the field works only when this record is created): %s\n",
            implode(', ', array_map(static fn (string $name): string => '`'.$name.'`', $names)),
            $this->childRecordTemplate->render($table, $uid, $pid, reset($fileFields), $fieldname),
        );
    }

    private function formDataRequest(): ServerRequestInterface
    {
        $request = $this->userContext->getServerRequest() ?? ($GLOBALS['TYPO3_REQUEST'] ?? null);

        return $request instanceof ServerRequestInterface
            ? $request
            // An empty URI, never none: core accepts null in the constructor and then fails in getUri()
            : (new ServerRequest(''))->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    /**
     * @param array<array-key, mixed> $item
     */
    private static function relationItemLabel(array $item): string
    {
        $title = $item['title'] ?? $item['label'] ?? null;
        if (null === $title) {
            return (string) json_encode($item);
        }

        return is_numeric($item['uid'] ?? null)
            ? sprintf('%s (%d)', (string) $title, (int) $item['uid'])
            : (string) $title;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function formatRecordFallback(string $table, array $record, ?int $maxLength, bool $includeEmpty, bool $includeSystem, bool $listMode): string
    {
        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $text = $this->headerLine($table, (int) ($record['uid'] ?? 0), (string) ($record[$labelField] ?? '?'), $record);

        $typeKey = $this->richtextTypeKey($table, $record);

        $htmlStripped = false;
        foreach ($record as $field => $value) {
            if ($this->relationResolver->isEmptyRelation($table, (string) $field, $value)) {
                $value = '';
            }
            if (!$this->recordAccess->canAccessField($table, (string) $field)
                || !$this->fieldCuration->shouldInclude((string) $field, $value, $includeEmpty, $includeSystem, $this->allowedFields)
            ) {
                continue;
            }

            $resolved = $this->relationResolver->resolveFieldValue($table, (string) $field, $value, $record, $listMode);
            if (null !== $resolved) {
                $text .= sprintf("  `%s` (%s): %s\n", $field, $this->tcaLabel->getFieldLabel($table, (string) $field), $resolved);

                continue;
            }

            if (!$htmlStripped
                && $this->previewWouldDropTags($value)
                && $this->isEditorialRichtextField($table, $typeKey, (string) $field)
            ) {
                $htmlStripped = true;
            }
            $display = $this->outputFormatter->displayValue($value, $maxLength);
            $text .= sprintf("  `%s` (%s): %s\n", $field, $this->tcaLabel->getFieldLabel($table, (string) $field), $display);
        }

        $text .= $this->renderCtrlSystemFields($table, $record, $includeSystem);
        $text .= $this->renderContainerContext($table, $record);
        if ($htmlStripped) {
            $text .= self::HTML_STRIPPED_NOTE;
        }

        return $text;
    }

    private function previewWouldDropTags(mixed $rawValue): bool
    {
        if (!is_scalar($rawValue)) {
            return false;
        }
        $str = (string) $rawValue;

        return '' !== $str && $str !== strip_tags($str);
    }

    /**
     * @param array<string, mixed> $rawRecord
     */
    private function richtextTypeKey(string $table, array $rawRecord): ?string
    {
        try {
            return $this->tcaCompatibilityService->resolveSubSchemaType($table, $rawRecord);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isEditorialRichtextField(string $table, ?string $typeKey, string $field): bool
    {
        try {
            return $this->tcaCompatibilityService->isRichTextField($table, $field, $typeKey)
                || $this->tcaCompatibilityService->isRawMarkupField($table, $field, $typeKey);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function formatRecordRaw(string $table, array $record, bool $includeSystem, bool $listMode): string
    {
        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $text = $this->headerLine(
            $table,
            (int) ($record['uid'] ?? 0),
            (string) $this->outputFormatter->scalarize($record[$labelField] ?? ''),
            $record,
        );

        foreach ($record as $field => $value) {
            if (!$this->recordAccess->canAccessField($table, (string) $field)) {
                continue;
            }
            if (null !== $this->allowedFields) {
                if (!in_array((string) $field, $this->allowedFields, true)) {
                    continue;
                }
            } elseif (!$includeSystem && $this->fieldCuration->isHousekeeping((string) $field)) {
                continue;
            }

            $rawValue = is_array($value) ? (string) json_encode($value) : (string) $value;
            if ('' === $rawValue) {
                continue;
            }

            $line = $rawValue;
            $resolved = $this->relationResolver->resolveFieldValue($table, (string) $field, $value, $record, $listMode);
            if (null !== $resolved && $resolved !== $rawValue) {
                $line .= '  ↳ '.$resolved;
            }

            $text .= sprintf("  `%s` (%s): %s\n", $field, $this->tcaLabel->getFieldLabel($table, (string) $field), $line);
        }

        $text .= $this->renderCtrlSystemFields($table, $record, $includeSystem);
        if ($includeSystem) {
            $text .= $this->renderWorkspaceState($table, $record);
        }

        $text .= $this->renderContainerContext($table, $record);

        return $text;
    }

    /**
     * @return array<string, string>
     */
    private function ctrlSystemFields(string $table): array
    {
        try {
            $ctrl = $this->tcaCompatibilityService->getRawConfiguration($table);
        } catch (\Throwable) {
            return [];
        }

        $fields = [];
        foreach (self::CTRL_SYSTEM_FIELDS as $key) {
            $field = (string) ($ctrl[$key] ?? '');
            if ('' !== $field && !$this->recordAccess->fieldExistsInSchema($table, $field)) {
                $fields[$field] = $key;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function renderCtrlSystemFields(string $table, array $record, bool $includeSystem): string
    {
        $text = '';
        foreach ($this->ctrlSystemFields($table) as $field => $key) {
            $requested = null !== $this->allowedFields ? in_array($field, $this->allowedFields, true) : $includeSystem;
            if (!$requested || !array_key_exists($field, $record)) {
                continue;
            }

            $value = (int) $this->outputFormatter->scalarize($record[$field]);
            $display = in_array($key, self::CTRL_TIMESTAMP_FIELDS, true) && $value > 0
                ? sprintf('%s (%d)', date('Y-m-d H:i:s', $value), $value)
                : (string) $value;

            $text .= sprintf("  `%s` (system, read-only): %s\n", $field, $display);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function renderWorkspaceState(string $table, array $record): string
    {
        if (!array_key_exists('t3ver_wsid', $record)) {
            return '';
        }

        $state = $this->workspaceRecords->stateLabel($record);
        $uid = (int) ($record['uid'] ?? 0);
        $liveUid = $this->workspaceRecords->resolveLiveUid($table, $uid);

        return sprintf(
            "  `workspace state`: %s (t3ver_wsid: %d, t3ver_oid: %d, t3ver_state: %d, deleted: %d, live uid: %d)\n",
            '' !== $state ? $state : 'live',
            (int) ($record['t3ver_wsid'] ?? 0),
            (int) ($record['t3ver_oid'] ?? 0),
            (int) ($record['t3ver_state'] ?? 0),
            (int) ($record['deleted'] ?? 0),
            $liveUid,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function headerLine(string $table, int $uid, string $label, array $row): string
    {
        $markers = [];

        $state = $this->workspaceRecords->stateLabel($row);
        if ('' !== $state) {
            $markers[] = $state;
        }

        $disabledField = $this->tcaCompatibilityService->getDisabledFieldName($table);
        if (null !== $disabledField && !empty($row[$disabledField])) {
            $markers[] = 'hidden';
        }

        return [] === $markers
            ? sprintf("**UID %d** — %s\n", $uid, $label)
            : sprintf("**UID %d** — %s [%s]\n", $uid, $label, implode(', ', $markers));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderContainerContext(string $table, array $row): string
    {
        if ('tt_content' !== $table) {
            return '';
        }

        $registry = $this->tcaLabel->getContainerRegistry();
        if (null === $registry) {
            return '';
        }

        $text = '';
        $cType = $this->outputFormatter->scalarize($row['CType'] ?? '');
        $parent = (int) $this->outputFormatter->scalarize($row['tx_container_parent'] ?? 0);
        $colPos = (int) $this->outputFormatter->scalarize($row['colPos'] ?? 0);

        if ('' !== $cType && $registry->isContainerElement($cType)) {
            $text .= "  ↳ container slots:\n";
            foreach ($registry->getAvailableColumns($cType) as $col) {
                $text .= sprintf(
                    "      - %s (colPos: %d)\n",
                    $this->tcaLabel->resolveLabel((string) ($col['name'] ?? '')),
                    (int) ($col['colPos'] ?? 0),
                );
            }
        }

        if ($parent > 0) {
            $text .= sprintf("  ↳ inside container UID %d (colPos: %d)\n", $parent, $colPos);
        }

        return $text;
    }
}
