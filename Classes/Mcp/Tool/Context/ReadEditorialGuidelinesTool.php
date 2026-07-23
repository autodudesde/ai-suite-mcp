<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Exposes the editors' Global Instructions (tone, target audience, style) for a page subtree.
 *
 * These used to take effect only inside the credit-costing AI tools, which made them invisible
 * whenever the model composed content itself. A model that writes content without them silently
 * ignores the site's editorial rules.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class ReadEditorialGuidelinesTool extends AbstractTool
{
    /**
     * The scopes editors can configure instructions for. `pageTree` and `contentElement` remain
     * meaningful even though the matching generate* tools are gone — the model now composes that
     * content itself and must honour the same rules.
     *
     * `general` is the default an editor gets when creating a Global Instruction (see
     * ScopeItemsProcFunc), so it is the most used scope of all — and it was missing here, which made
     * a call with scope `general` fail validation. Every other scope already returns the general
     * rules as well, folded in by the repository.
     *
     * @var list<string>
     */
    private const SCOPES = ['general', 'metadata', 'contentElement', 'pageTree', 'editContent', 'translation', 'imageWizard'];

    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly GlobalInstructionService $globalInstructionService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readEditorialGuidelines';
    }

    public function getDescription(): string
    {
        return "Read the editors' content guidelines (tone, target audience, style) that apply to a page. "
            .'Call it with the page the content will live on, before you write or translate that content, and honour what it returns. '
            .'It is the only source of those rules.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'UID of the page the content belongs to (not the page the editor happens to be on). Instructions are inherited down the page tree.'],
                'scope' => [
                    'type' => 'string',
                    'enum' => self::SCOPES,
                    'default' => 'contentElement',
                    'description' => 'Which kind of content the guidelines are for. The `general` rules apply to everything and are always returned on top of the scope you pick, both inherited down the page tree.',
                ],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $scope = (string) ($params['scope'] ?? 'contentElement');

        if ($pageId <= 0) {
            return $this->textError('pageId must be a positive integer.');
        }

        if (!in_array($scope, self::SCOPES, true)) {
            return $this->textError(sprintf('Unknown scope "%s". Valid: %s', $scope, implode(', ', self::SCOPES)));
        }

        $this->recordAccess->assertPagePerm($pageId, Permission::PAGE_SHOW);

        // GlobalInstructionsRepository::findByScope() already folds the `general` instructions into
        // every scope (`scope = :scope OR scope = 'general'`), so one call returns both. Fetching
        // `general` separately on top of this would print those rules twice.
        $instructions = $this->globalInstructionService->buildGlobalInstruction('pages', $scope, $pageId);

        if ('' === trim($instructions)) {
            return $this->textResult(sprintf('No editorial guidelines configured for page %d (scope: %s).', $pageId, $scope));
        }

        return $this->textResult(sprintf("## Editorial guidelines for page %d (%s, including the general rules)\n\n%s", $pageId, $scope, $instructions));
    }
}
