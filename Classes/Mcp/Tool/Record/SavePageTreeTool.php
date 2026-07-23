<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Record;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use Mcp\Types\CallToolResult;
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
        // No "requires user confirmation before calling": the host gates the call. Told to ask,
        // small models describe the tree in prose and never call the tool. Measured — see the
        // OperatingGuidelines docblock for the same mistake in three other places.
        return 'Persist a page tree structure (writes). Each page needs at least a title; children create '
            .'nested subpages recursively. Pages land in the active workspace, like writeRecords.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'parentPageId' => ['type' => 'integer', 'description' => 'Parent page UID'],
                'pages' => [
                    'type' => 'array',
                    'description' => 'The pages to create under parentPageId. Each: {title, seoTitle?, seoDescription?, doktype?, children?}. `children` nests the same shape to any depth and creates SUBPAGES. Use children only when the editor asked for a page hierarchy: a page\'s content is content elements (writeRecords), not a subpage. "A landing page" is one page whose sections are content elements, not a page with an "Inhalte"/"Content" subpage under it. Only `title` is required.',
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
            return $this->textError('pages must be a non-empty array.');
        }

        $this->recordAccess->validateTableWriteAccess('pages');
        $this->recordAccess->assertPagePerm($parentPageId, Permission::PAGE_NEW);

        if ($parentPageId > 0) {
            $parent = BackendUtility::getRecordWSOL('pages', $parentPageId);
            if (null === $parent) {
                return $this->textError(sprintf('Parent page %d not found.', $parentPageId));
            }
        }

        $datamap = ['pages' => []];
        $planned = $this->collectPagesIntoDatamap($pages, $parentPageId, $datamap);
        $count = $this->countNodes($planned);

        if (0 === $count) {
            return $this->textError('No valid pages to create (every entry was missing a title).');
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();

        if ([] !== $dataHandler->errorLog) {
            return $this->errorResult(
                'Page-tree creation reported errors: '.$this->dataHandlerError->joinLog($dataHandler->errorLog),
                McpErrorType::DataHandlerError,
            );
        }

        try {
            $created = $this->resolveNewIds($planned, $parentPageId, $dataHandler->substNEWwithIDs);
        } catch (\RuntimeException $e) {
            // DataHandler reported no error yet dropped a page — never report that as success,
            // and never hand back uid 0, which the client would happily use as a pid.
            return $this->errorResult($e->getMessage(), McpErrorType::DataHandlerError);
        }

        return $this->structuredResult(
            $this->renderResult($created, $count, $parentPageId),
            ['pages' => $created],
        );
    }

    /**
     * Flatten the request into the DataHandler datamap, keeping the tree shape (and each node's
     * NEW-id) so the created UIDs can be mapped back onto it after process_datamap().
     *
     * @param array<int, array<string, mixed>> $pages
     * @param int|string                       $parentRef parent page UID (int) or NEW-id reference (string) for nested children
     * @param array<string, mixed>             $datamap   accumulator passed by reference
     *
     * @return list<array{newId: string, title: string, children: list<mixed>}>
     */
    private function collectPagesIntoDatamap(array $pages, int|string $parentRef, array &$datamap): array
    {
        $planned = [];
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

            $children = [];
            if (isset($pageData['children']) && is_array($pageData['children']) && [] !== $pageData['children']) {
                $children = $this->collectPagesIntoDatamap($pageData['children'], $newId, $datamap);
            }

            $planned[] = ['newId' => $newId, 'title' => $title, 'children' => $children];
        }

        return $planned;
    }

    /**
     * @param list<array{newId: string, title: string, children: list<mixed>}> $planned
     * @param array<string, mixed>                                             $substNEWwithIDs
     *
     * @return list<array{uid: int, title: string, pid: int, children: list<mixed>}>
     *
     * @throws \RuntimeException if DataHandler did not create a planned page
     */
    private function resolveNewIds(array $planned, int $parentUid, array $substNEWwithIDs): array
    {
        $created = [];
        foreach ($planned as $node) {
            $uid = (int) ($substNEWwithIDs[$node['newId']] ?? 0);
            if ($uid <= 0) {
                throw new \RuntimeException(sprintf('Page "%s" was not created — DataHandler returned no UID for it.', $node['title']));
            }

            /** @var list<array{newId: string, title: string, children: list<mixed>}> $children */
            $children = $node['children'];

            $created[] = [
                'uid' => $uid,
                'title' => $node['title'],
                'pid' => $parentUid,
                'children' => $this->resolveNewIds($children, $uid, $substNEWwithIDs),
            ];
        }

        return $created;
    }

    /**
     * The result used to print each page as "(UID: 42, pid: 1)" and stop there. Measured with
     * gpt-5.4-nano: it then wrote the page's content with pid 1, the parent, because two plausible
     * ids sat side by side and nothing said which one a follow-up write needs. So name the uids once,
     * as the thing to do next, and keep the parent out of the per-page line.
     *
     * @param list<array{uid: int, title: string, pid: int, children: list<mixed>}> $created
     */
    private function renderResult(array $created, int $count, int $parentPageId): string
    {
        $text = sprintf("%d page(s) created under page %d.\n\n%s\n", $count, $parentPageId, $this->renderPageLines($created));

        $uids = $this->collectUids($created);
        if ([] !== $uids) {
            $text .= sprintf(
                "\nTo put content on a new page, use its uid above as the `pid` of the content record (for example pid: %d). Do not use %d, the parent page, for content that belongs on a new page.",
                $uids[0],
                $parentPageId,
            );
        }

        return $text;
    }

    /**
     * @param list<array{uid: int, title: string, pid: int, children: list<mixed>}> $created
     *
     * @return list<int>
     */
    private function collectUids(array $created): array
    {
        $uids = [];
        foreach ($created as $page) {
            $uids[] = $page['uid'];
            if ([] !== $page['children']) {
                /** @var list<array{uid: int, title: string, pid: int, children: list<mixed>}> $children */
                $children = $page['children'];
                $uids = array_merge($uids, $this->collectUids($children));
            }
        }

        return $uids;
    }

    /**
     * @param list<array{uid: int, title: string, pid: int, children: list<mixed>}> $created
     */
    private function renderPageLines(array $created, int $depth = 0): string
    {
        $lines = [];
        foreach ($created as $page) {
            $lines[] = sprintf(
                '%s- %s (uid: %d)',
                str_repeat('  ', $depth),
                $page['title'],
                $page['uid'],
            );
            if ([] !== $page['children']) {
                /** @var list<array{uid: int, title: string, pid: int, children: list<mixed>}> $children */
                $children = $page['children'];
                $lines[] = $this->renderPageLines($children, $depth + 1);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{newId: string, title: string, children: list<mixed>}> $planned
     */
    private function countNodes(array $planned): int
    {
        $count = 0;
        foreach ($planned as $node) {
            ++$count;

            /** @var list<array{newId: string, title: string, children: list<mixed>}> $children */
            $children = $node['children'];
            $count += $this->countNodes($children);
        }

        return $count;
    }

    private function generateNewId(): string
    {
        return 'NEW'.substr(md5((string) microtime(true).random_int(0, 99999999)), 0, 22);
    }
}
