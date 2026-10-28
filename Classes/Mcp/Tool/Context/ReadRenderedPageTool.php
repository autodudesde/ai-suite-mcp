<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuiteMcp\Mcp\Service\ContentFetchService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class ReadRenderedPageTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

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
            .'Unlike readPageContent, which returns the stored tt_content records. '
            .'In a workspace, or when the page cannot be rendered, the text is assembled from the stored records instead, and the answer says so.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'UID of the page to render.'],
                'language' => ['type' => 'integer', 'default' => 0, 'minimum' => 0, 'description' => 'sys_language_uid of the language to render; 0 is the default language.'],
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

        $content = $this->contentFetchService->fetch($pageId, $language);

        if ($content->rendered) {
            return $this->textResult(sprintf("## Rendered content of page %d\n\n%s", $pageId, $content->text));
        }

        $source = 0 !== $this->mcpToolContext->workspaceContextService->getWorkspaceId()
            ? 'Workspace drafts are not rendered, so this text was assembled from the stored records of the draft.'
            : 'The page could not be rendered, so this text was assembled from the stored records.';
        $source .= ' Plugin output and TypoScript-composed content are missing.';

        if ('' === trim($content->text)) {
            return $this->textResult(sprintf("Page %d has no text content.\n\n%s", $pageId, $source));
        }

        return $this->textResult(sprintf("## Content of page %d (not rendered)\n\n%s\n\n%s", $pageId, $source, $content->text));
    }
}
