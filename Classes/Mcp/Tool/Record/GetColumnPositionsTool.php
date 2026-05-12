<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetColumnPositionsTool extends AbstractDataTool
{
    protected ?string $requiredScope = null;

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly BackendLayoutView $backendLayoutView,
        private readonly ContentRepository $contentRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getColumnPositions';
    }

    public function getDescription(): string
    {
        return 'List available column positions (colPos) for a page based on its backend layout. '
            .'Also lists existing container instances on the page (b13/container) with their inner slots — '
            .'use those to drop a child element into an existing container by setting `tx_container_parent` and `colPos`. '
            .'Use before placing content to know valid colPos values.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'Page UID'],
                'languageUid' => ['type' => 'integer', 'default' => 0, 'description' => 'Language UID for container scan (default 0 = default language).'],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $languageUid = (int) ($params['languageUid'] ?? 0);
        $this->assertPagePerm($pageId, Permission::PAGE_SHOW);

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (null === $page) {
            return new CallToolResult([new TextContent("Page {$pageId} not found.")], isError: true);
        }

        $backendLayout = $this->backendLayoutView->getBackendLayoutForPage($pageId);
        $columns = [];

        if (null !== $backendLayout) {
            $config = $backendLayout->getStructure();
            $rows = $config['__config']['backend_layout.']['rows.'] ?? [];
            foreach ($rows as $row) {
                foreach ($row['columns.'] ?? [] as $col) {
                    $colPos = (int) ($col['colPos'] ?? -1);
                    if ($colPos >= 0) {
                        $columns[$colPos] = $this->resolveLabel($col['name'] ?? 'Column '.$colPos);
                    }
                }
            }
        }

        if (empty($columns)) {
            $columns[0] = 'Main Content';
        }

        ksort($columns);
        $text = sprintf("Column positions on page %d:\n\n", $pageId);
        foreach ($columns as $colPos => $label) {
            $text .= sprintf("- **%s** (colPos: %d)\n", $label, $colPos);
        }

        $text .= $this->renderContainerInstances($pageId, $languageUid);

        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * Render existing container instances on a page with their inner slots.
     * Empty when EXT:container is not loaded or no containers exist on the page.
     */
    private function renderContainerInstances(int $pageId, int $languageUid): string
    {
        $registry = $this->getContainerRegistry();
        if (null === $registry) {
            return '';
        }

        $cTypes = $registry->getRegisteredCTypes();
        if ([] === $cTypes) {
            return '';
        }

        $containers = $this->contentRepository->findContainersOnPage($pageId, $languageUid, $cTypes);
        if ([] === $containers) {
            return '';
        }

        $text = "\nExisting containers on this page (drop children into them via `tx_container_parent` + `colPos`):\n";
        foreach ($containers as $container) {
            $cType = (string) $container['CType'];
            $headerLabel = '' !== (string) ($container['header'] ?? '')
                ? (string) $container['header']
                : sprintf('Container UID %d', (int) $container['uid']);
            $text .= sprintf("\n- **%s** (`%s`, UID: %d)\n", $headerLabel, $cType, (int) $container['uid']);
            foreach ($registry->getAvailableColumns($cType) as $col) {
                $text .= sprintf(
                    "    - %s → tx_container_parent: %d, colPos: %d\n",
                    $this->resolveLabel((string) ($col['name'] ?? '')),
                    (int) $container['uid'],
                    (int) ($col['colPos'] ?? 0),
                );
            }
        }

        return $text;
    }
}
