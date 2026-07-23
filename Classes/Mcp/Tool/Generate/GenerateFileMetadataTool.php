<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Generate;

use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\MetadataService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GenerateFileMetadataTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:generate';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly LibraryService $libraryService,
        private readonly MetadataService $metadataService,
        private readonly UuidService $uuidService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'generateFileMetadata';
    }

    public function getDescription(): string
    {
        return 'Generate alt text, title and description for a file by looking at the file itself with AI vision '
            .DescriptionSnippets::COSTS_CREDITS.'. '
            .'The write target is sys_file_metadata, not sys_file.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileUid' => ['type' => 'integer', 'description' => 'sys_file UID. To save results, write to sys_file_metadata (not sys_file) — use readRecords(table: "sys_file_metadata", filters: {"file": fileUid}) to find the matching metadata UID.'],
                'model' => ['type' => 'string', 'description' => 'Vision model identifier. Omit to get a list of available models first.'],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['alternative', 'title', 'description']],
                    'default' => ['alternative', 'title'],
                    'description' => 'Metadata fields to generate: alternative (alt text), title, description. Default: alternative, title.',
                ],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
            ],
            'required' => ['fileUid'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $fileUid = (int) $params['fileUid'];
        $model = (string) ($params['model'] ?? '');

        if ('' === $model) {
            return $this->listModels();
        }

        $fields = $params['fields'] ?? ['alternative', 'title'];
        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), 1);

        $this->permissionService->validateModelAccess($model);
        $this->recordAccess->assertFileReadAccess($fileUid);

        try {
            $fileContent = $this->metadataService->getFileContent($fileUid);
            $filename = $this->metadataService->getFilename($fileUid);
        } catch (\Throwable $e) {
            $this->logger->error('GenerateFileMetadata: could not fetch file content, aborting metadata generation', [
                'fileUid' => $fileUid,
                'error' => $e->getMessage(),
            ]);

            return new CallToolResult(
                [new TextContent('Could not fetch file content: '.$e->getMessage())],
                isError: true,
            );
        }

        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('sys_file_metadata', 'metadata');
        $globalInstructionsOverride = $this->globalInstructionService->checkOverridePredefinedPrompt('files', 'metadata', []);

        $text = $this->appendDataFlowInfo('', $model);
        $allSuggestions = [];
        $lastResult = [];

        foreach ($fields as $fieldLabel) {
            $uuid = $this->uuidService->generateUuid();

            $requestData = [
                'uuid' => $uuid,
                'field_label' => $fieldLabel,
                'request_content' => $fileContent,
                'global_instructions' => $globalInstructions,
                'override_predefined_prompt' => $globalInstructionsOverride,
                'filename' => $filename,
            ];

            $result = $this->sendAiRequest('createMetadata', $requestData, ['text' => $model], $langIsoCode);
            $lastResult = $result;

            $metadataResult = $result['metadataResult'] ?? [];
            if (!empty($metadataResult)) {
                $allSuggestions[$fieldLabel] = $metadataResult;
            }
        }

        $text .= sprintf(
            "Generated suggestions for %d field(s):\n%s",
            count($allSuggestions),
            json_encode($allSuggestions, JSON_PRETTY_PRINT),
        );

        return $this->appendCreditInfo($this->textResult($text), $lastResult);
    }

    private function listModels(): CallToolResult
    {
        $librariesAnswer = $this->sendRequestService->sendLibrariesRequest(
            GenerationLibraryEnumeration::METADATA,
            'createMetadata',
            ['text'],
        );

        if ('Error' === $librariesAnswer->getType()) {
            return new CallToolResult(
                [new TextContent('Could not fetch available models. The AI Suite Server may be temporarily unavailable.')],
                isError: true,
            );
        }

        $libraries = $librariesAnswer->getResponseData()['textGenerationLibraries'] ?? [];
        $filtered = $this->libraryService->prepareLibraries(
            array_filter($libraries, fn ($lib) => in_array($lib['model_identifier'] ?? '', ['Vision', 'MittwaldMinistral14BVision'], true)),
        );

        if (empty($filtered)) {
            $filtered = $this->libraryService->prepareLibraries($libraries);
        }

        if (empty($filtered)) {
            return new CallToolResult(
                [new TextContent('No models available. Check your backend user permissions.')],
            );
        }

        $text = "Present the following numbered list to the user and ask them to pick one:\n\n";
        $i = 1;
        foreach ($filtered as $library) {
            $text .= sprintf("%d. %s\n", $i, $library['model_identifier']);
            ++$i;
        }
        $text .= "\nEach generation costs at least one credit. Tell the user this before they choose.";
        $text .= "\nDo NOT pick a model yourself. Show this exact list and wait for the user to choose.";

        return $this->textResult($text);
    }
}
