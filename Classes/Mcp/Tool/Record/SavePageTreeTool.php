<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class SavePageTreeTool extends AbstractDataTool
{
    protected ?string $requiredScope = 'mcp:write';

    public function getName(): string
    {
        return 'savePageTree';
    }

    public function getDescription(): string
    {
        return 'Persist a page tree structure — requires user confirmation before calling. '
            .'Each page needs at least a title; children create nested subpages recursively. '
            .'Honors the active workspace context (mcpWriteMode + token binding) — pages land in the same workspace as writeRecords.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'parentPageId' => ['type' => 'integer', 'description' => 'Parent page UID'],
                'pages' => [
                    'type' => 'array',
                    'description' => 'Array of page objects: [{title, seoTitle?, seoDescription?, doktype?, children?:[...]}]',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['parentPageId', 'pages'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $parentPageId = (int) $params['parentPageId'];
        $pages = $params['pages'] ?? [];

        if (!is_array($pages) || empty($pages)) {
            return new CallToolResult([new TextContent('pages must be a non-empty array.')], isError: true);
        }

        $this->validateTableWriteAccess('pages');
        $this->assertPagePerm($parentPageId, Permission::PAGE_NEW);

        if ($parentPageId > 0) {
            $parent = BackendUtility::getRecordWSOL('pages', $parentPageId);
            if (null === $parent) {
                return new CallToolResult([new TextContent(sprintf('Parent page %d not found.', $parentPageId))], isError: true);
            }
        }

        // Build a flat datamap with NEW-id refs so DataHandler resolves the parent/child
        // hierarchy in a single pass. DataHandler then handles slug generation, permission
        // checks, AND workspace versioning automatically — which the legacy
        // PageStructureFactory path bypassed (raw INSERT without workspace awareness).
        $datamap = ['pages' => []];
        $count = $this->collectPagesIntoDatamap($pages, $parentPageId, $datamap);

        if (0 === $count) {
            return new CallToolResult([new TextContent('No valid pages to create (every entry was missing a title).')], isError: true);
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();

        if ([] !== $dataHandler->errorLog) {
            return new CallToolResult(
                [new TextContent('Page-tree creation reported errors: '.implode(', ', $dataHandler->errorLog))],
                isError: true,
            );
        }

        return new CallToolResult([new TextContent(
            sprintf('%d page(s) created under page %d.', $count, $parentPageId),
        )]);
    }

    /**
     * Recursively flattens the nested page tree into a DataHandler datamap.
     * Returns the number of valid (titled) pages added.
     *
     * @param array<int, array<string, mixed>> $pages
     * @param int|string                       $parentRef parent page UID (int) or NEW-id reference (string) for nested children
     * @param array<string, mixed>             $datamap   accumulator passed by reference
     */
    private function collectPagesIntoDatamap(array $pages, int|string $parentRef, array &$datamap): int
    {
        $count = 0;
        foreach ($pages as $pageData) {
            $title = (string) ($pageData['title'] ?? '');
            if ('' === $title) {
                continue;
            }
            $newId = $this->generateNewId();
            $datamap['pages'][$newId] = [
                'pid' => $parentRef,
                'title' => $title,
                'doktype' => (int) ($pageData['doktype'] ?? 1),
                'seo_title' => (string) ($pageData['seoTitle'] ?? ''),
                'description' => (string) ($pageData['seoDescription'] ?? ''),
                'hidden' => (int) ($pageData['hidden'] ?? 0),
            ];
            ++$count;

            if (isset($pageData['children']) && is_array($pageData['children']) && [] !== $pageData['children']) {
                $count += $this->collectPagesIntoDatamap($pageData['children'], $newId, $datamap);
            }
        }

        return $count;
    }

    /**
     * Generate a DataHandler NEW-id placeholder. 24-char hex suffix avoids collisions
     * within a single batch (DataHandler validates uniqueness across the datamap).
     */
    private function generateNewId(): string
    {
        return 'NEW'.substr(md5((string) microtime(true).random_int(0, 99999999)), 0, 22);
    }
}
