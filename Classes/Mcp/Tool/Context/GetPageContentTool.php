<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuiteMcp\Mcp\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Returns content elements of a page, grouped by column position.
 * Always includes full bodytext content.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class GetPageContentTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly ContentRepository $contentRepository,
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
        $languageUid = $this->resolveLanguageUid($params['language'] ?? null, $pageId);
        $includeHidden = (bool) ($params['includeHidden'] ?? false);
        $limit = (int) ($params['limit'] ?? 50);
        $offset = (int) ($params['offset'] ?? 0);

        $this->assertPagePerm($pageId, Permission::PAGE_SHOW);

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (null === $page) {
            return new CallToolResult(
                [new TextContent(sprintf('Page %d not found.', $pageId))],
                isError: true,
            );
        }

        $total = $this->contentRepository->countByPage($pageId, $languageUid, $includeHidden);
        $rows = $this->contentRepository->findByPage($pageId, $languageUid, $includeHidden, $limit, $offset);

        // Group by colPos
        $grouped = [];
        foreach ($rows as $row) {
            $colPos = (int) $row['colPos'];
            if (!isset($grouped[$colPos])) {
                $grouped[$colPos] = [];
            }
            $grouped[$colPos][] = $this->formatElement($row);
        }

        // Build human-readable output
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
                    $preview = $el['bodytext_preview'] ?? mb_substr($el['bodytext'] ?? '', 0, 100);
                    $text .= sprintf(
                        "%d. **%s** (UID: %d, CType: %s)%s\n   %s\n\n",
                        $pos,
                        $el['header'] ?: '_(no header)_',
                        $el['uid'],
                        $el['CType'],
                        $hiddenMark,
                        '' !== $preview ? $preview : '_(empty)_',
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

        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function formatElement(array $row): array
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
        ];
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

        return 'Column '.$colPos;
    }
}
