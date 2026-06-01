<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\PagesRepository;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetPageTreeTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly PagesRepository $pagesRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getPageTree';
    }

    public function getDescription(): string
    {
        return 'Navigate the TYPO3 page tree. Returns page hierarchy with titles, slugs, '
            .'doctypes, and child counts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rootPageId' => [
                    'type' => 'integer',
                    'description' => 'Start page UID (0 = all accessible sites)',
                    'default' => 0,
                ],
                'depth' => [
                    'type' => 'integer',
                    'description' => 'How many levels deep to traverse (1-10)',
                    'default' => 3,
                    'minimum' => 1,
                    'maximum' => 10,
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'ISO language code (e.g., "de", "en", "fr"). Default: default language.',
                ],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $rootPageId = $params['rootPageId'] ?? 0;
        $depth = $params['depth'] ?? 3;
        $languageUid = $this->recordAccess->resolveLanguageUid($params['language'] ?? null, (int) $rootPageId);

        if (0 === $rootPageId) {
            $tree = $this->getAllAccessibleTrees($depth, $languageUid);
        } else {
            if (!$this->hasAccessToPage($rootPageId)) {
                return new CallToolResult(
                    [new TextContent($this->translate('hint.page_access', [$rootPageId])
                        ?? sprintf('Page %d is outside your accessible page tree.', $rootPageId))],
                    isError: true,
                );
            }

            $tree = $this->buildTree($rootPageId, $depth, $languageUid);
        }

        return new CallToolResult([
            new TextContent((string) json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getAllAccessibleTrees(int $depth, int $languageUid): array
    {
        $webMounts = $this->getWebMounts();

        if (empty($webMounts)) {
            return $this->getChildPages(0, $depth, $languageUid);
        }

        $trees = [];
        foreach ($webMounts as $mountId) {
            $trees[] = $this->buildTree($mountId, $depth, $languageUid);
        }

        return $trees;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTree(int $pageId, int $depth, int $languageUid): array
    {
        $page = BackendUtility::getRecord('pages', $pageId);

        if (null === $page) {
            return [];
        }

        if ($languageUid > 0) {
            $localizedTitle = $this->pagesRepository->getLocalizedTitle($pageId, $languageUid);
            if (null !== $localizedTitle) {
                $page['title'] = $localizedTitle;
            }
        }

        $children = [];
        if ($depth > 0) {
            $children = $this->getChildPages($pageId, $depth - 1, $languageUid);
        }

        return [
            'uid' => (int) $page['uid'],
            'title' => (string) $page['title'],
            'slug' => (string) ($page['slug'] ?? ''),
            'doktype' => (int) $page['doktype'],
            'sys_language_uid' => $languageUid,
            'childCount' => count($children),
            'children' => $children,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getChildPages(int $parentId, int $remainingDepth, int $languageUid): array
    {
        $rows = $this->pagesRepository->getChildPageRows($parentId);

        $children = [];
        foreach ($rows as $row) {
            if (!$this->hasAccessToPage((int) $row['uid'])) {
                continue;
            }

            $subChildren = [];
            if ($remainingDepth > 0) {
                $subChildren = $this->getChildPages((int) $row['uid'], $remainingDepth - 1, $languageUid);
            }

            $title = (string) $row['title'];
            if ($languageUid > 0) {
                $localizedTitle = $this->pagesRepository->getLocalizedTitle((int) $row['uid'], $languageUid);
                if (null !== $localizedTitle) {
                    $title = $localizedTitle;
                }
            }

            $children[] = [
                'uid' => (int) $row['uid'],
                'title' => $title,
                'slug' => (string) ($row['slug'] ?? ''),
                'doktype' => (int) $row['doktype'],
                'sys_language_uid' => $languageUid,
                'childCount' => count($subChildren),
                'children' => $subChildren,
            ];
        }

        return $children;
    }

    private function hasAccessToPage(int $pageId): bool
    {
        $beUser = $this->getBackendUser();

        if (null === $beUser) {
            return false;
        }

        if ($beUser->isAdmin()) {
            return true;
        }

        $page = BackendUtility::getRecord('pages', $pageId);
        if (null === $page) {
            return false;
        }

        return $beUser->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    /**
     * @return list<int>
     */
    private function getWebMounts(): array
    {
        $beUser = $this->getBackendUser();

        if (null === $beUser || $beUser->isAdmin()) {
            return [];
        }

        return array_map('intval', $beUser->getWebmounts());
    }
}
