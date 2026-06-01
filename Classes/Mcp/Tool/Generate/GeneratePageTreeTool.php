<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Generate;

use AutoDudes\AiSuite\Enumeration\CreditCostEnumeration;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GeneratePageTreeTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:generate';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly LibraryService $libraryService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'generatePageTree';
    }

    public function getDescription(): string
    {
        return 'Generate a page tree structure from a description. Two approaches: '
            .DescriptionSnippets::APPROACH_A
            .'Approach A returns a preview directly in the response — display it to the user (no additional tool call needed). '
            .'After explicit user approval, persist via savePageTree (not writeRecords). '
            .'(B) Plan the tree yourself → savePageTree (requires user confirmation before calling) — no credits.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'parentPageId' => ['type' => 'integer', 'description' => 'Parent page UID'],
                'description' => ['type' => 'string', 'description' => 'Describe the page structure to generate'],
                'model' => ['type' => 'string', 'description' => 'AI model identifier. Omit to get a list of available models first.'],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
            ],
            'required' => ['parentPageId', 'description'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $parentPageId = (int) $params['parentPageId'];
        $model = (string) ($params['model'] ?? '');

        if ('' === $model) {
            return $this->listModels();
        }

        $page = $this->validatePageForAi($parentPageId, Permission::PAGE_NEW);
        if ($page instanceof CallToolResult) {
            return $page;
        }

        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), $parentPageId);

        $this->permissionService->validateModelAccess($model);

        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('pages', 'pageTree', $parentPageId);

        $description = (string) $params['description'];

        $result = $this->sendAiRequest('pageTree', [
            'global_instructions' => $globalInstructions,
        ], ['text' => $model], strtoupper($langIsoCode), $description);

        $text = $this->appendDataFlowInfo('', $model);
        $text .= sprintf('Page tree created under page %d.', $parentPageId);
        $text .= $this->getWorkspaceInfo();

        return $this->appendCreditInfo($this->textResult($text), $result);
    }

    private function listModels(): CallToolResult
    {
        return $this->listAvailableModels(
            $this->libraryService,
            GenerationLibraryEnumeration::PAGETREE,
            'pageTree',
            ['text'],
            CreditCostEnumeration::PAGETREE,
            ['text' => 'Page tree generation models'],
        );
    }
}
