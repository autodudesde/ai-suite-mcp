<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

class ReadEditorialGuidelinesTool extends AbstractTool
{
    /**
     * @var list<string>
     */
    private const SCOPES = ['general', 'metadata', 'contentElement', 'pageTree', 'editContent', 'translation', 'imageWizard'];

    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

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

        $rootlineUids = $this->rootlineUids($pageId);
        if (null === $rootlineUids) {
            return $this->unresolvablePage($pageId);
        }

        $instructions = $this->globalInstructionService->buildGlobalInstruction('pages', $scope, $pageId);

        if ('' === trim($instructions)) {
            $otherScopes = $this->guidelinesOfOtherScopes($pageId, $scope);
            if ([] !== $otherScopes) {
                $sections = [];
                foreach ($otherScopes as $otherScope => $text) {
                    $sections[] = sprintf("### Scope %s\n\n%s", $otherScope, $text);
                }

                return $this->textResult(sprintf(
                    "## Editorial guidelines for page %d\n\nNo guidelines are set for the scope %s, but this page has "
                        ."guidelines under other scopes. Apply those that fit the task.\n\n%s",
                    $pageId,
                    $scope,
                    implode("\n\n", $sections),
                ));
            }

            return $this->textResult(sprintf(
                'No editorial guidelines apply to page %d (scope: %s). Guidelines set on an ancestor page reach this one '
                    ."only when their record has \"use for subtree\" enabled. Rootline checked: %s.\nProceed with the "
                    .'house style visible in the existing content of the page.',
                $pageId,
                $scope,
                implode(' → ', $rootlineUids),
            ));
        }

        return $this->textResult(sprintf("## Editorial guidelines for page %d (%s, including the general rules)\n\n%s", $pageId, $scope, $instructions));
    }

    /**
     * @return array<string, string>
     */
    private function guidelinesOfOtherScopes(int $pageId, string $requestedScope): array
    {
        $found = [];
        foreach (self::SCOPES as $scope) {
            if ($scope === $requestedScope || 'general' === $scope) {
                continue;
            }
            $instructions = trim($this->globalInstructionService->buildGlobalInstruction('pages', $scope, $pageId));
            if ('' !== $instructions) {
                $found[$scope] = $instructions;
            }
        }

        return $found;
    }

    /**
     * @return null|list<string>
     */
    private function rootlineUids(int $pageId): ?array
    {
        try {
            $rootline = GeneralUtility::makeInstance(RootlineUtility::class, $pageId, '')->get();
        } catch (\Throwable) {
            return null;
        }

        return array_map(
            static fn (array $page): string => (string) ($page['uid'] ?? '?'),
            array_reverse($rootline),
        );
    }

    private function unresolvablePage(int $pageId): CallToolResult
    {
        $message = sprintf('Page %d cannot be resolved in the current workspace, so its editorial guidelines are unknown, not empty.', $pageId);
        $parentUid = (int) (BackendUtility::getRecord('pages', $pageId, 'pid')['pid'] ?? 0);
        if ($parentUid > 0) {
            $message .= sprintf(' Read them from its parent page %d instead.', $parentUid);
        }

        return $this->textError($message, McpErrorType::NotFound);
    }
}
