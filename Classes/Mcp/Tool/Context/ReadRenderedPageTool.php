<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuiteMcp\Mcp\Service\ContentFetchService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Exposes the rendered frontend text of a page.
 *
 * `ContentFetchService` used to be reachable only from inside the metadata generation tools, which
 * meant the one input no other tool can reconstruct — the page as a visitor sees it, including
 * plugin output and TypoScript-composed content — was locked behind a credit-costing AI call.
 * `readPageContent` reads `tt_content` rows and therefore misses all of it.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadRenderedPageTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ContentFetchService $contentFetchService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readRenderedPage';
    }

    public function getDescription(): string
    {
        return 'Read a page as a visitor sees it: the rendered frontend text, including plugin output and TypoScript-composed content. '
            .'Unlike readPageContent, which returns the stored tt_content records.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'UID of the page to render.'],
                'language' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'sys_language_uid of the language to render. Default: 0 (default language).'],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $language = max(0, (int) ($params['language'] ?? 0));

        if ($pageId <= 0) {
            return $this->textError('pageId must be a positive integer.');
        }

        $this->recordAccess->assertPagePerm($pageId, Permission::PAGE_SHOW);

        $text = $this->contentFetchService->fetchPageContent($pageId, $language);

        if ('' === trim($text)) {
            return $this->textResult(sprintf('Page %d rendered no text content.', $pageId));
        }

        return $this->textResult(sprintf("## Rendered content of page %d\n\n%s", $pageId, $text));
    }
}
