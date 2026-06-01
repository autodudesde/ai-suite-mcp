<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetPageContentTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ContentRepository $contentRepository,
        private readonly RecordRepository $recordRepository,
        private readonly BackendLayoutView $backendLayoutView,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getPageContent';
    }

    public function getDescription(): string
    {
        return 'Get all content elements of a TYPO3 page, grouped by column position. '
            .'Returns full content including text fields and textarea fields as well as image counts. '
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
                    'default' => false,
                    'description' => 'Include hidden content elements.',
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
        $includeHidden = (bool) ($params['includeHidden'] ?? false);
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
        $rows = $this->contentRepository->findByPage($pageId, $languageUid, $includeHidden, $limit, $offset);

        $grouped = [];
        foreach ($rows as $row) {
            $colPos = (int) $row['colPos'];
            if (!isset($grouped[$colPos])) {
                $grouped[$colPos] = [];
            }
            $grouped[$colPos][] = $this->formatElement($row, $languageUid);
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
                        $metaParts[] = sprintf('%d image(s)', $el['image_count']);
                    }
                    $meta = implode(' · ', $metaParts);

                    if ('' !== $preview) {
                        $line = $preview;
                    } elseif ('' !== $meta) {
                        $line = $meta;
                    } else {
                        $line = '_(empty)_';
                    }

                    $text .= sprintf(
                        "%d. **%s** (UID: %d, CType: %s)%s\n   %s\n\n",
                        $pos,
                        $el['header'] ?: '_(no header)_',
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

        return $this->textResult($text);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function formatElement(array $row, int $languageUid): array
    {
        $bodytext = strip_tags((string) ($row['bodytext'] ?? ''));
        $imageCount = $this->contentRepository->countFileReferences((int) $row['uid']);

        return [
            'uid' => (int) $row['uid'],
            'CType' => (string) $row['CType'],
            'header' => (string) $row['header'],
            'colPos' => (int) $row['colPos'],
            'sorting' => (int) ($row['sorting'] ?? 0),
            'hidden' => (bool) $row['hidden'],
            'bodytext' => $bodytext,
            'has_images' => $imageCount > 0,
            'image_count' => $imageCount,
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
            $this->logger->warning('GetPageContentTool: child-count introspection failed', [
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
                            $name = $col['name'] ?? '';
                            if (str_starts_with($name, 'LLL:')) {
                                return $this->localizationService->translate($name) ?: 'Column '.$colPos;
                            }

                            return '' !== $name ? $name : 'Column '.$colPos;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('GetPageContentTool: backend layout resolution failed, falling back to generic column label', [
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
