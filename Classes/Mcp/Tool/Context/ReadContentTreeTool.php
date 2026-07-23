<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Bulk read of the editorial content across a whole page subtree, paginated by page.
 * Collapses the N×readPageContent loop that site-wide tasks would otherwise need
 * (du/Sie consistency, tone/terminology audits, "find/replace X everywhere",
 * preparing a bulk edit) into a few calls — and returns each element's UID so the
 * model can feed them straight into a writeRecords batch.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadContentTreeTool extends AbstractTool
{
    private const ELEMENTS_PER_PAGE_CAP = 100;

    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ContentRepository $contentRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readContentTree';
    }

    public function getDescription(): string
    {
        return 'Read the content elements of every page in a subtree at once, paginated by page. The bulk '
            .'alternative to readPageContent, which reads one page. Each element comes back with its UID. '
            .'Returns only pages within your backend webmounts.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rootPageId' => ['type' => 'integer', 'default' => 0, 'description' => 'Subtree root page UID (0 = all accessible sites).'],
                'depth' => ['type' => 'integer', 'default' => 3, 'minimum' => 1, 'maximum' => 10, 'description' => 'Levels to descend. Default: 3.'],
                'language' => ['type' => 'string', 'description' => 'ISO language code to read (e.g. "fr"). Default: default language. Resolved against the rootPageId site.'],
                'includeHidden' => ['type' => 'boolean', 'default' => false, 'description' => 'Include hidden content elements.'],
                'maxLength' => ['type' => 'integer', 'default' => 200, 'description' => 'Truncate each element text to this many characters. Use 0 / fullText for no truncation.'],
                'fullText' => ['type' => 'boolean', 'default' => false, 'description' => 'Return untruncated element text.'],
                'limitPages' => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100, 'description' => 'Pages per call. Default: 25. Page through the rest with offset.'],
                'offset' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'Skip the first N pages (pagination).'],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $rootPageId = (int) ($params['rootPageId'] ?? 0);
        $depth = max(1, min(10, (int) ($params['depth'] ?? 3)));
        $includeHidden = (bool) ($params['includeHidden'] ?? false);
        $limitPages = max(1, min(100, (int) ($params['limitPages'] ?? 25)));
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $maxLength = 200;
        if ((bool) ($params['fullText'] ?? false)) {
            $maxLength = null;
        } elseif (isset($params['maxLength'])) {
            $maxLength = (int) $params['maxLength'] > 0 ? (int) $params['maxLength'] : null;
        }

        if ($rootPageId > 0) {
            $this->recordAccess->assertPagePerm($rootPageId, Permission::PAGE_SHOW);
        }

        $languageUid = $this->recordAccess->resolveLanguageUid($params['language'] ?? null, $rootPageId);

        $pageIds = $this->recordAccess->getReadablePageIds($rootPageId, $depth);
        sort($pageIds);
        $total = count($pageIds);

        if (0 === $total) {
            return $this->textResult('No accessible pages found in this subtree.');
        }

        $pageSlice = array_slice($pageIds, $offset, $limitPages);

        $langLabel = $languageUid > 0 ? sprintf(' (language uid %d)', $languageUid) : '';
        $text = sprintf("Content tree from page %d, depth %d%s — pages %d–%d of %d:\n\n", $rootPageId, $depth, $langLabel, $offset + 1, $offset + count($pageSlice), $total);

        foreach ($pageSlice as $pid) {
            $page = BackendUtility::getRecordWSOL('pages', (int) $pid);
            if (null === $page) {
                continue;
            }

            $text .= sprintf("### Page %d: \"%s\" (%s)\n", (int) $pid, (string) ($page['title'] ?? ''), (string) ($page['slug'] ?? ''));

            $rows = $this->contentRepository->findByPage((int) $pid, $languageUid, $includeHidden, self::ELEMENTS_PER_PAGE_CAP, 0);
            if ([] === $rows) {
                $text .= "_(no content elements)_\n\n";

                continue;
            }

            foreach ($rows as $row) {
                $header = trim((string) ($row['header'] ?? ''));
                $hidden = !empty($row['hidden']) ? ' [hidden]' : '';
                $body = $this->outputFormatter->displayValue($row['bodytext'] ?? '', $maxLength);
                $text .= sprintf(
                    "- %d [%s]%s %s: %s\n",
                    (int) $row['uid'],
                    (string) $row['CType'],
                    $hidden,
                    '' !== $header ? '"'.$header.'"' : '(no header)',
                    $body,
                );
            }
            $text .= "\n";
        }

        if ($offset + $limitPages < $total) {
            $text .= sprintf("_More pages remain — %d of %d shown. Next: offset=%d._\n", $offset + count($pageSlice), $total, $offset + $limitPages);
        }

        return $this->textResult($text);
    }
}
