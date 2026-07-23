<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\FieldCurationService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RelationResolutionService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadRecordTool extends AbstractDataTool
{
    private const HTML_STRIPPED_NOTE = "  ↳ ℹ️ HTML was stripped for this preview — re-read with `raw: true` to get the source markup before editing.\n";

    protected ?string $requiredScope = null;
    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly RecordRepository $recordRepository,
        private readonly FieldCurationService $fieldCuration,
        private readonly RelationResolutionService $relationResolver,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readRecords';
    }

    public function getDescription(): string
    {
        return 'Read records of any TCA table — one by uid, a whole page by pid, or an exact-match query by filters. '
            .'Reads the stored rows; readRenderedPage shows the page as a visitor sees it. '
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
                'filters' => [
                    // Stays a free-form object: the keys are TCA field names of the requested table.
                    'type' => 'object',
                    'description' => 'Field name => value, exact match. An empty string "" finds records whose field is empty, e.g. {"description": ""}. '
                        .'To list the children of a container/IRRE parent, filter on the relation field, e.g. table "tx_bootstrappackage_card_group_item" with {"tt_content": PARENT_UID}.',
                ],
                'limit' => ['type' => 'integer', 'default' => 50, 'description' => 'Max records. Default: 50, max: 200.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'description' => 'Skip first N records for pagination. Default: 0.'],
                'fullText' => ['type' => 'boolean', 'default' => false, 'description' => 'Return long text fields untruncated. Ignored (always full) for a single-uid read.'],
                'maxLength' => ['type' => 'integer', 'description' => 'Truncate text fields to this many characters in list mode (default 300). Use 0 or fullText=true for no truncation.'],
                'raw' => ['type' => 'boolean', 'default' => false, 'description' => 'Return verbatim stored values (markup intact, untruncated, no tag stripping). Required before editing bodytext/RTE fields to round-trip the HTML.'],
                'includeEmpty' => ['type' => 'boolean', 'default' => true, 'description' => 'Include empty-valued fields. Default true (enables finding records with empty fields); set false to show only populated fields.'],
                'includeSystem' => ['type' => 'boolean', 'default' => false, 'description' => 'Include housekeeping/system fields (timestamps, versioning, sorting). Default false (hidden as noise).'],
            ],
            'required' => ['table'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $table = (string) $params['table'];
        $uid = isset($params['uid']) ? (int) $params['uid'] : null;
        $pid = isset($params['pid']) ? (int) $params['pid'] : null;
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

        if (null === $uid && null === $pid && empty($filters)) {
            return $this->textError('Provide uid, pid, or filters.');
        }

        $this->recordAccess->validateTableReadAccess($table);

        if (null !== $uid) {
            $this->recordAccess->assertRecordReadAccess($table, $uid);

            $formatted = $this->loadAndFormatRecord($table, $uid, null, $raw, $includeEmpty, $includeSystem, false);
            if (null === $formatted) {
                return $this->textError(sprintf('%s:%d not found.', $table, $uid));
            }

            return $this->textResult(sprintf("Record `%s:%d`:\n\n%s", $table, $uid, $formatted));
        }

        if (null !== $pid) {
            $this->recordAccess->assertPagePerm($pid, Permission::PAGE_SHOW);
        }

        $allowedPids = null;
        $extraWhere = null;
        if (null === $pid) {
            $beUser = $this->getBackendUser();
            if (null !== $beUser && !$beUser->isAdmin()) {
                if ('pages' === $table) {
                    $extraWhere = $beUser->getPagePermsClause(Permission::PAGE_SHOW);
                } elseif (!$this->tcaCompatibilityService->isRootLevel($table)) {
                    $allowedPids = $this->recordAccess->getReadablePageIds(0, 99);
                    if (empty($allowedPids)) {
                        return $this->textResult(sprintf('No %s records accessible in your webmounts.', $table));
                    }
                }
            }
        }

        $sanitizedFilters = [];
        foreach ($filters as $field => $value) {
            if (is_string($field) && $this->recordAccess->canAccessField($table, $field)) {
                $sanitizedFilters[$field] = $value;
            }
        }

        $uids = $this->recordRepository->findUidsByCriteria(
            $table,
            $pid,
            $sanitizedFilters,
            $allowedPids,
            $extraWhere,
            $this->tcaCompatibilityService->getSortField($table),
            $limit + 1,
            $offset,
        );

        if (empty($uids)) {
            $context = null !== $pid ? sprintf('on page %d', $pid) : 'matching filters';

            return $this->textResult(sprintf('No %s records %s.', $table, $context));
        }

        $truncated = count($uids) > $limit;
        if ($truncated) {
            $uids = array_slice($uids, 0, $limit);
        }

        $context = null !== $pid ? sprintf('on page %d', $pid) : 'matching filters';
        $text = sprintf("%d record(s) `%s` %s:\n\n", count($uids), $table, $context);
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

        return $this->textResult($text);
    }

    /**
     * @param ?int $maxLength     truncate long text values to this many chars; null = no truncation
     * @param bool $raw           return raw stored DB values (markup intact, untruncated) instead of
     *                            FormDataCompiler-processed, tag-stripped plain text
     * @param bool $includeEmpty  keep fields whose value is empty
     * @param bool $includeSystem keep housekeeping/system fields
     * @param bool $listMode      multi-record list read (bounds relation-title lookups)
     */
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
                    'request' => $this->userContext->getServerRequest(),
                    'tableName' => $table,
                    'vanillaUid' => $uid,
                    'command' => 'edit',
                    'returnUrl' => '',
                    'defaultValues' => [],
                ],
                GeneralUtility::makeInstance(TcaDatabaseRecord::class),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ReadRecordTool: FormDataCompiler failed, falling back to BackendUtility::getRecordWSOL', [
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

        $text = sprintf("**UID %d** — %s\n", $uid, $label);

        $htmlStripped = false;
        foreach ($columnsToProcess as $field) {
            if (!$this->recordAccess->canAccessField($table, $field)) {
                continue;
            }
            if (!$this->fieldCuration->shouldInclude($field, $databaseRow[$field] ?? null, $includeEmpty, $includeSystem)) {
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
                    static fn ($item) => \is_array($item) ? ($item['title'] ?? $item['label'] ?? json_encode($item)) : (string) $item,
                    $value,
                ));
            }

            $display = $this->outputFormatter->displayValue($value, $maxLength);

            $text .= sprintf("  `%s` (%s): %s\n", $field, $this->tcaLabel->getFieldLabel($table, $field), $display);
        }

        $text .= $this->renderContainerContext($table, $databaseRow);
        if ($htmlStripped) {
            $text .= self::HTML_STRIPPED_NOTE;
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function formatRecordFallback(string $table, array $record, ?int $maxLength, bool $includeEmpty, bool $includeSystem, bool $listMode): string
    {
        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $text = sprintf("**UID %d** — %s\n", $record['uid'] ?? 0, $record[$labelField] ?? '?');

        $typeKey = $this->richtextTypeKey($table, $record);

        $htmlStripped = false;
        foreach ($record as $field => $value) {
            if (!$this->recordAccess->canAccessField($table, (string) $field)
                || !$this->fieldCuration->shouldInclude((string) $field, $value, $includeEmpty, $includeSystem)
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
            return $this->tcaCompatibilityService->isRichTextField($table, $field, $typeKey);
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
        $text = sprintf("**UID %d** — %s\n", (int) ($record['uid'] ?? 0), $this->outputFormatter->scalarize($record[$labelField] ?? ''));

        foreach ($record as $field => $value) {
            if (!$this->recordAccess->canAccessField($table, (string) $field)) {
                continue;
            }
            if (!$includeSystem && $this->fieldCuration->isHousekeeping((string) $field)) {
                continue;
            }
            // Raw mode is for round-tripping markup: empty fields carry nothing to edit.
            $rawValue = is_array($value) ? (string) json_encode($value) : (string) $value;
            if ('' === $rawValue) {
                continue;
            }

            // Keep the verbatim value (round-trippable for a later write) and annotate
            // relations with their resolved titles instead of replacing the UIDs.
            $line = $rawValue;
            $resolved = $this->relationResolver->resolveFieldValue($table, (string) $field, $value, $record, $listMode);
            if (null !== $resolved && $resolved !== $rawValue) {
                $line .= '  ↳ '.$resolved;
            }

            $text .= sprintf("  `%s` (%s): %s\n", $field, $this->tcaLabel->getFieldLabel($table, (string) $field), $line);
        }

        $text .= $this->renderContainerContext($table, $record);

        return $text;
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
