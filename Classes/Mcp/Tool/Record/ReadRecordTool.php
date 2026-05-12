<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadRecordTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly RecordRepository $recordRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readRecords';
    }

    public function getDescription(): string
    {
        return 'Read one or more records from any TCA table. '
            .'Provide uid for a single record, pid to list all records on a page, or filters for exact-match field queries (e.g. find records with empty fields). '
            .'Returns only records within your backend webmounts.';
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
                    'type' => 'object',
                    'description' => 'Field=value filters for exact matches. Use empty string "" to find records with empty fields. Example: {"description": ""} finds all records with no description.',
                ],
                'limit' => ['type' => 'integer', 'default' => 50, 'description' => 'Max records. Default: 50, max: 200.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'description' => 'Skip first N records for pagination. Default: 0.'],
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

        if (null === $uid && null === $pid && empty($filters)) {
            return new CallToolResult([new TextContent('Provide uid, pid, or filters.')], isError: true);
        }

        $this->validateTableReadAccess($table);

        if (null !== $uid) {
            // Mode 1: uid wins. Permission verified via assertRecordReadAccess; pid (if any) is ignored.
            $this->assertRecordReadAccess($table, $uid);

            $formatted = $this->loadAndFormatRecord($table, $uid);
            if (null === $formatted) {
                return new CallToolResult([new TextContent(sprintf('%s:%d not found.', $table, $uid))], isError: true);
            }

            return new CallToolResult([new TextContent(sprintf("Record `%s:%d`:\n\n%s", $table, $uid, $formatted))]);
        }

        if (null !== $pid) {
            // Mode 2: explicit pid → check page permission once.
            $this->assertPagePerm($pid, Permission::PAGE_SHOW);
        }

        // List records — lightweight repository query for UIDs, then FormDataCompiler per record.
        // Resolve permission filter that user filters cannot replace.
        $allowedPids = null;
        $extraWhere = null;
        if (null === $pid) {
            $beUser = $this->getBackendUser();
            if (null !== $beUser && !$beUser->isAdmin()) {
                if ('pages' === $table) {
                    $extraWhere = $beUser->getPagePermsClause(Permission::PAGE_SHOW);
                } elseif (!$this->tcaCompatibilityService->isRootLevel($table)) {
                    $allowedPids = $this->getReadablePageIds(0, 99);
                    if (empty($allowedPids)) {
                        return new CallToolResult([new TextContent(sprintf('No %s records accessible in your webmounts.', $table))]);
                    }
                }
            }
        }

        // TCA-validate field filters; the repository receives only sanitized field names.
        $sanitizedFilters = [];
        foreach ($filters as $field => $value) {
            if (is_string($field) && $this->canAccessField($table, $field)) {
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
            $limit,
            $offset,
        );

        if (empty($uids)) {
            $context = null !== $pid ? sprintf('on page %d', $pid) : 'matching filters';

            return new CallToolResult([new TextContent(sprintf('No %s records %s.', $table, $context))]);
        }

        $context = null !== $pid ? sprintf('on page %d', $pid) : 'matching filters';
        $text = sprintf("%d record(s) `%s` %s:\n\n", count($uids), $table, $context);
        foreach ($uids as $recordUid) {
            $formatted = $this->loadAndFormatRecord($table, (int) $recordUid);
            if (null !== $formatted) {
                $text .= "---\n".$formatted."\n";
            }
        }

        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * Load a record via FormDataCompiler and format only the type-relevant fields.
     */
    private function loadAndFormatRecord(string $table, int $uid): ?string
    {
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

            return $this->formatRecordFallback($table, $record);
        }

        $databaseRow = $formData['databaseRow'] ?? [];
        if (empty($databaseRow)) {
            return null;
        }

        $columnsToProcess = $formData['columnsToProcess'] ?? [];
        $labelField = $this->tcaCompatibilityService->getLabelField($table);

        $label = $databaseRow[$labelField] ?? '';
        if (\is_array($label)) {
            $label = implode(', ', array_map(
                static fn ($item) => \is_array($item) ? ($item['title'] ?? $item['label'] ?? json_encode($item)) : (string) $item,
                $label,
            ));
        }

        $text = sprintf("**UID %d** — %s\n", $uid, $label);

        foreach ($columnsToProcess as $field) {
            if (!$this->canAccessField($table, $field)) {
                continue;
            }

            $value = $databaseRow[$field] ?? null;
            if (\is_array($value)) {
                $value = implode(', ', array_map(
                    static fn ($item) => \is_array($item) ? ($item['title'] ?? $item['label'] ?? json_encode($item)) : (string) $item,
                    $value,
                ));
            }

            $display = null === $value || '' === (string) $value
                ? '_empty_'
                : mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $value))), 0, 300);

            $text .= sprintf("  `%s` (%s): %s\n", $field, $this->getFieldLabel($table, $field), $display);
        }

        $text .= $this->renderContainerContext($table, $databaseRow);

        return $text;
    }

    /**
     * Fallback formatting when FormDataCompiler is not available.
     *
     * @param array<string, mixed> $record
     */
    private function formatRecordFallback(string $table, array $record): string
    {
        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $text = sprintf("**UID %d** — %s\n", $record['uid'] ?? 0, $record[$labelField] ?? '?');
        $skip = ['uid', 'pid', 'tstamp', 'crdate', 'l10n_diffsource', 'l10n_source', 'l18n_parent', 't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 't3_origuid'];

        foreach ($record as $field => $value) {
            if (\in_array($field, $skip, true) || !$this->canAccessField($table, $field)) {
                continue;
            }
            $display = null === $value || '' === $value ? '_empty_' : mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $value))), 0, 300);
            $text .= sprintf("  `%s` (%s): %s\n", $field, $this->getFieldLabel($table, $field), $display);
        }

        $text .= $this->renderContainerContext($table, $record);

        return $text;
    }

    /**
     * Append container context for tt_content records:
     * - if the record is a registered container CType: list its inner colPos slots
     * - if the record has a non-zero tx_container_parent: indicate the parent
     *
     * @param array<string, mixed> $row
     */
    private function renderContainerContext(string $table, array $row): string
    {
        if ('tt_content' !== $table) {
            return '';
        }

        $registry = $this->getContainerRegistry();
        if (null === $registry) {
            return '';
        }

        $text = '';
        // FormDataCompiler returns select/group field values as arrays (e.g. ['text'] for CType,
        // ['12'] for tx_container_parent). The fallback path via getRecordWSOL returns scalars.
        // Normalize defensively so this helper works regardless of caller.
        $cType = self::scalarize($row['CType'] ?? '');
        $parent = (int) self::scalarize($row['tx_container_parent'] ?? 0);
        $colPos = (int) self::scalarize($row['colPos'] ?? 0);

        if ('' !== $cType && $registry->isContainerElement($cType)) {
            $text .= "  ↳ container slots:\n";
            foreach ($registry->getAvailableColumns($cType) as $col) {
                $text .= sprintf(
                    "      - %s (colPos: %d)\n",
                    $this->resolveLabel((string) ($col['name'] ?? '')),
                    (int) ($col['colPos'] ?? 0),
                );
            }
        }

        if ($parent > 0) {
            $text .= sprintf("  ↳ inside container UID %d (colPos: %d)\n", $parent, $colPos);
        }

        return $text;
    }

    /**
     * Reduce a possibly-array (from FormDataCompiler) value to a scalar string.
     */
    private static function scalarize(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        return (string) $value;
    }
}
