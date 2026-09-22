<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\ElementLabelService;
use AutoDudes\AiSuiteMcp\Mcp\Service\PageRecordsService;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadPageContentTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ContentRepository $contentRepository,
        private readonly RecordRepository $recordRepository,
        private readonly BackendLayoutView $backendLayoutView,
        private readonly WorkspaceRecordService $workspaceRecords,
        private readonly ElementLabelService $elementLabels,
        private readonly PageRecordsService $pageRecords,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readPageContent';
    }

    public function getDescription(): string
    {
        return 'Get all content elements of a TYPO3 page, grouped by column position. '
            .'Returns full content including text fields and textarea fields as well as image counts '
            .'and the referenced file UIDs with the field they hang on, which the media tools take. '
            .'Requires read permission on the page.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => [
                    'type' => 'integer',
                    'description' => 'TYPO3 page UID',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'ISO language code. Default: default language.',
                ],
                'includeHidden' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include hidden content elements, marked [HIDDEN] in the output. Default: true, as in the backend. Set false for the visitor-facing set.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 50,
                    'minimum' => 1,
                    'maximum' => 200,
                    'description' => 'Maximum number of content elements.',
                ],
                'offset' => [
                    'type' => 'integer',
                    'default' => 0,
                    'minimum' => 0,
                    'description' => 'Skip this many elements (pagination).',
                ],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $languageUid = $this->recordAccess->resolveLanguageUid($params['language'] ?? null, $pageId);
        $includeHidden = (bool) ($params['includeHidden'] ?? true);
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);

        $this->recordAccess->assertPagePerm($pageId, Permission::PAGE_SHOW);

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (null === $page) {
            return new CallToolResult(
                [new TextContent(sprintf('Page %d not found.', $pageId))],
                isError: true,
            );
        }

        $total = $this->contentRepository->countByPage($pageId, $languageUid, $includeHidden);
        $rows = $this->workspaceRecords->overlayRows(
            'tt_content',
            $this->contentRepository->findByPage($pageId, $languageUid, $includeHidden, $limit, $offset),
        );

        $labels = $this->elementLabels->resolveForRows('tt_content', $rows);
        $grouped = [];
        foreach ($rows as $row) {
            $colPos = (int) $row['colPos'];
            if (!isset($grouped[$colPos])) {
                $grouped[$colPos] = [];
            }
            $element = $this->formatElement($row, $languageUid);
            $element['label'] = $labels[(int) $row['uid']] ?? '';
            $grouped[$colPos][] = $element;
        }

        $text = sprintf("## Page %d: %s\n\n", $pageId, $page['title']);

        if (empty($grouped)) {
            $text .= '_No content elements found._';
        } else {
            foreach ($grouped as $colPos => $elements) {
                $colLabel = $this->resolveColPosLabel($pageId, $colPos);
                $text .= sprintf("### %s (colPos: %d)\n\n", $colLabel, $colPos);
                foreach ($elements as $idx => $el) {
                    $pos = $idx + 1;
                    $hiddenMark = $el['hidden'] ? ' [HIDDEN]' : '';
                    $preview = $el['bodytext_preview'] ?? $this->outputFormatter->truncate($el['bodytext'] ?? '', 100);

                    $metaParts = [];
                    if ('' !== ($el['child_summary'] ?? '')) {
                        $metaParts[] = $el['child_summary'];
                    }
                    if (($el['image_count'] ?? 0) > 0) {
                        $byField = $el['files_by_field'] ?? [];
                        $perField = [];
                        foreach ($byField as $field => $uids) {
                            $perField[] = sprintf('%s: fileUid %s', (string) $field, implode(', ', $uids));
                        }
                        $metaParts[] = [] !== $perField
                            ? implode(' · ', $perField)
                            : sprintf('%d image(s)', $el['image_count']);
                    }
                    $meta = implode(' · ', $metaParts);

                    if ('' === $preview && '' === $meta) {
                        $line = '_(empty)_';
                    } else {
                        $line = implode("\n   ", array_filter([$preview, $meta], static fn (string $part): bool => '' !== $part));
                    }

                    $text .= sprintf(
                        "%d. **%s** (UID: %d, CType: %s)%s\n   %s\n\n",
                        $pos,
                        '' !== $el['label'] ? $el['label'] : '_(unnamed)_',
                        $el['uid'],
                        $this->tcaLabel->resolveCTypeLabel($el['CType']),
                        $hiddenMark,
                        $line,
                    );
                }
                $text .= sprintf(
                    "_To insert at end of this column: use position \"end\" with colPos %d._\n"
                    ."_To insert after a specific element: use position \"after:%d\" (UID of last element)._\n\n",
                    $colPos,
                    $elements[array_key_last($elements)]['uid'],
                );
            }
        }

        $text .= sprintf("\n_Showing %d of %d elements (offset: %d)._", count($rows), $total, $offset);
        $text .= $this->describePageRecords($pageId);

        return $this->withFoundRecords($this->textResult($text), [['table' => 'pages', 'uid' => (int) $pageId]]);
    }

    private function describePageRecords(int $pageId): string
    {
        $found = $this->pageRecords->findOnPage($pageId);
        if ([] === $found) {
            return '';
        }

        $text = sprintf(
            "\n\n### Other records stored on this page\n\n"
            .'This page also holds domain records that are NOT content elements. To add one (e.g. a news '
            .'article), writeRecords into its table with pid %d — do not create a tt_content element or a '
            ."subpage for it.\n\n",
            $pageId,
        );

        foreach ($found as $table => $entry) {
            $text .= sprintf(
                "- `%s` — %s — %d record(s)\n",
                $table,
                $this->tcaLabel->getTableLabel($table),
                $entry['total'],
            );
            foreach ($entry['records'] as $record) {
                $text .= sprintf(
                    "  - UID %d: %s\n",
                    $record['uid'],
                    '' !== $record['label'] ? $record['label'] : '_(unnamed)_',
                );
            }
            if ($entry['total'] > count($entry['records'])) {
                $text .= sprintf("  - _… %d more, read them with readRecords._\n", $entry['total'] - count($entry['records']));
            }
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function formatElement(array $row, int $languageUid): array
    {
        $bodytext = strip_tags((string) ($row['bodytext'] ?? ''));
        $filesByField = $this->contentRepository->getReferencedFilesByField((int) $row['uid']);
        $fileUids = array_values(array_unique(array_merge(...array_values($filesByField) ?: [[]])));

        return [
            'uid' => (int) $row['uid'],
            'CType' => (string) $row['CType'],
            'header' => (string) $row['header'],
            'colPos' => (int) $row['colPos'],
            'sorting' => (int) ($row['sorting'] ?? 0),
            'hidden' => (bool) $row['hidden'],
            'bodytext' => $bodytext,
            'has_images' => [] !== $fileUids,
            'image_count' => count($fileUids),
            'file_uids' => $fileUids,
            'files_by_field' => $filesByField,
            'child_summary' => $this->describeChildren($row, $languageUid),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function describeChildren(array $row, int $languageUid): string
    {
        $uid = (int) $row['uid'];
        $cType = (string) $row['CType'];

        $registry = $this->tcaLabel->getContainerRegistry();
        if (null !== $registry && $registry->isContainerElement($cType)) {
            $childCount = count($this->contentRepository->findContainerChildren($uid, $languageUid));

            return $childCount > 0 ? sprintf('%d child element(s) in container slots', $childCount) : '';
        }

        $parts = [];

        try {
            foreach ($this->tcaCompatibilityService->getFieldNamesForType('tt_content', $cType) as $fieldName) {
                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration('tt_content', $cType, $fieldName);
                if ('inline' !== ($config['type'] ?? '')
                    || empty($config['foreign_table'])
                    || empty($config['foreign_field'])
                    || 'sys_file_reference' === $config['foreign_table'] // assets are reported as image count
                ) {
                    continue;
                }

                $count = $this->recordRepository->countByCriteria(
                    (string) $config['foreign_table'],
                    [(string) $config['foreign_field'] => $uid],
                );
                if ($count > 0) {
                    $parts[] = sprintf('%d %s', $count, $this->tcaLabel->getFieldLabel('tt_content', $fieldName));
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ReadPageContentTool: child-count introspection failed', [
                'uid' => $uid,
                'cType' => $cType,
                'error' => $e->getMessage(),
            ]);
        }

        return implode(', ', $parts);
    }

    private function resolveColPosLabel(int $pageId, int $colPos): string
    {
        try {
            $backendLayout = $this->backendLayoutView->getBackendLayoutForPage($pageId);
            if (null !== $backendLayout) {
                $config = $backendLayout->getStructure();
                $rows = $config['__config']['backend_layout.']['rows.'] ?? [];
                foreach ($rows as $row) {
                    foreach ($row['columns.'] ?? [] as $col) {
                        if ((int) ($col['colPos'] ?? -1) === $colPos) {
                            $name = $this->tcaLabel->resolveLabel((string) ($col['name'] ?? ''));

                            return '' !== $name ? $name : 'Column '.$colPos;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('ReadPageContentTool: backend layout resolution failed, falling back to generic column label', [
                'pageId' => $pageId,
                'colPos' => $colPos,
                'error' => $e->getMessage(),
            ]);
        }

        $containerLabel = $this->tcaLabel->resolveContainerColumnLabel($colPos);
        if (null !== $containerLabel) {
            return $containerLabel;
        }

        return 'Column '.$colPos;
    }
}
