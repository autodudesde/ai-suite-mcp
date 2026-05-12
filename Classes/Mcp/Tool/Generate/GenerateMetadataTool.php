<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Generate;

use AutoDudes\AiSuite\Enumeration\CreditCostEnumeration;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\ContentFetchService;
use AutoDudes\AiSuiteMcp\Mcp\ToolDescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Generate metadata suggestions for pages using AI.
 * Returns suggestions — saving is done via writeRecords.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class GenerateMetadataTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:generate';

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly ContentFetchService $contentFetchService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'generateMetadata';
    }

    public function getDescription(): string
    {
        return 'Generate SEO metadata for pages (seo_title, description, og_*, twitter_*). Two approaches: '
            .ToolDescriptionSnippets::APPROACH_A
            .'(B) Read content via getPageContent, compose metadata yourself '.ToolDescriptionSnippets::APPROACH_B_PERSIST.' '
            .ToolDescriptionSnippets::APPROACH_A_PREVIEW_AND_PERSIST;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'Page UID to generate metadata for.'],
                'model' => ['type' => 'string', 'description' => 'AI model identifier. Omit to get a list of available models first.'],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'SEO fields to generate: seo_title, description, og_title, og_description, twitter_title, twitter_description.',
                ],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
            ],
            'required' => ['pageId'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $model = (string) ($params['model'] ?? '');

        // No model → list available models
        if ('' === $model) {
            return $this->listAvailableModels(
                $this->libraryService,
                GenerationLibraryEnumeration::METADATA,
                'createMetadata',
                ['text'],
                CreditCostEnumeration::METADATA,
                ['text' => 'Metadata generation models'],
            );
        }

        $this->permissionService->validateModelAccess($model);

        return $this->generatePageMetadata($params, $model);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function generatePageMetadata(array $params, string $model): CallToolResult
    {
        $pageId = (int) ($params['pageId'] ?? 0);
        if (0 === $pageId) {
            return new CallToolResult([new TextContent('pageId is required.')], isError: true);
        }

        $fields = $params['fields'] ?? ['seo_title', 'description'];
        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), $pageId);

        $page = $this->validatePageForAi($pageId);
        if ($page instanceof CallToolResult) {
            return $page;
        }

        $requestContent = $this->contentFetchService->fetchPageContent($pageId, (int) ($page['sys_language_uid'] ?? 0));
        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('pages', 'metadata', $pageId);
        $globalInstructionsOverride = $this->globalInstructionService->checkOverridePredefinedPrompt('pages', 'metadata', [$pageId]);

        return $this->generateSuggestions($fields, $requestContent, $globalInstructions, $globalInstructionsOverride, $model, $langIsoCode);
    }

    /**
     * Shared generation loop: one AI request per field, returns numbered suggestions.
     *
     * @param array<string, mixed> $fields
     */
    private function generateSuggestions(
        array $fields,
        string $requestContent,
        string $globalInstructions,
        bool|string $globalInstructionsOverride,
        string $model,
        string $langIsoCode,
    ): CallToolResult {
        $text = $this->appendDataFlowInfo('', $model);
        $allSuggestions = [];
        $lastResult = [];

        foreach ($fields as $fieldLabel) {
            $uuid = $this->uuidService->generateUuid();

            $requestData = [
                'uuid' => $uuid,
                'field_label' => $fieldLabel,
                'request_content' => $requestContent,
                'global_instructions' => $globalInstructions,
                'override_predefined_prompt' => $globalInstructionsOverride,
            ];

            $result = $this->sendAiRequest('createMetadata', $requestData, ['text' => $model], $langIsoCode);
            $lastResult = $result;

            $metadataResult = $result['metadataResult'] ?? [];
            if (!empty($metadataResult)) {
                $allSuggestions[$fieldLabel] = $metadataResult;
            }
        }

        if (empty($allSuggestions)) {
            $text .= 'No suggestions could be generated.';

            return new CallToolResult([new TextContent($text)]);
        }

        $text .= sprintf("## Suggestions (Model: %s)\n\n", $model);

        foreach ($allSuggestions as $fieldLabel => $suggestions) {
            $text .= sprintf("### %s\n", $fieldLabel);
            foreach ($suggestions as $index => $suggestion) {
                $text .= sprintf("  %d. %s\n", $index + 1, $suggestion);
            }
            $text .= "\n";
        }

        $text .= "---\n";
        $text .= "Present the suggestions as a numbered list per field.\n";
        $text .= "Ask the user to pick ONE number per field.\n";
        $text .= 'After the user chooses, call `previewRecords` with the selected values, then `writeRecords` after confirmation.';

        return $this->appendCreditInfo(new CallToolResult([new TextContent($text)]), $lastResult);
    }
}
