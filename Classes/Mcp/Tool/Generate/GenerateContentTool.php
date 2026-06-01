<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Generate;

use AutoDudes\AiSuite\Enumeration\CreditCostEnumeration;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\ContentService;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Utility\DescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\ImageContent;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GenerateContentTool extends AbstractAiTool
{
    protected ?string $requiredScope = 'mcp:generate';

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ContentService $contentService,
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'generateContent';
    }

    public function getDescription(): string
    {
        return 'Create content elements on a page. '
            .'Call getContentTypes(pageId) + getColumnPositions(pageId) first to discover valid CTypes and colPos values. '
            .'Two approaches: '
            .DescriptionSnippets::APPROACH_A.'Supports AI-generated images. '
            .'(B) Compose content yourself '.DescriptionSnippets::APPROACH_B_PERSIST.' '
            .'If the user names a model or requests AI images → use A. '
            .DescriptionSnippets::APPROACH_A_PREVIEW_AND_PERSIST;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pageId' => ['type' => 'integer', 'description' => 'Target page UID'],
                'prompt' => ['type' => 'string', 'description' => 'What content to generate (topic, instructions)'],
                'model' => ['type' => 'string', 'description' => 'Text AI model identifier. Omit to get a list of available models first.'],
                'imageModel' => ['type' => 'string', 'description' => 'Image AI model for content with images.'],
                'contentType' => ['type' => 'string', 'default' => 'textmedia', 'description' => 'TYPO3 CType (e.g. textmedia, text, textpic, image)'],
                'colPos' => ['type' => 'integer', 'default' => 0, 'description' => 'Column position'],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
                'textFields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Text field names to generate (e.g. ["header","bodytext"]). Omit to generate all available text fields for the CType.',
                ],
                'imageFields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Image field names to generate (e.g. ["assets"]). Requires imageModel. Omit to skip image generation.',
                ],
            ],
            'required' => ['pageId', 'prompt'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $pageId = (int) $params['pageId'];
        $model = (string) ($params['model'] ?? '');
        $cType = (string) ($params['contentType'] ?? 'textmedia');
        $colPos = (int) ($params['colPos'] ?? 0);

        if ('' === $model) {
            return $this->listModels();
        }

        $page = $this->validatePageForAi($pageId, Permission::CONTENT_EDIT);
        if ($page instanceof CallToolResult) {
            return $page;
        }

        $this->permissionService->validateModelAccess($model);

        $imageModel = (string) ($params['imageModel'] ?? '');
        if ('' !== $imageModel) {
            $this->permissionService->validateModelAccess($imageModel);
        }

        $table = 'tt_content';
        $defVals = [$table => ['CType' => $cType, 'colPos' => $colPos, 'pid' => $pageId]];
        $serverRequest = $this->userContext->getServerRequest();
        if (null === $serverRequest) {
            return $this->textError($this->translate('hint.no_request') ?? 'No server request available.');
        }
        $allRequestFields = $this->contentService->fetchRequestFields(
            $serverRequest,
            $defVals,
            $cType,
            $pageId,
            $table,
        );

        foreach ($allRequestFields as $tableName => &$tableFields) {
            if (isset($tableFields['text']) && empty($tableFields['text'])) {
                unset($tableFields['text']);
            }
            if (isset($tableFields['image']) && empty($tableFields['image'])) {
                unset($tableFields['image']);
            }
        }
        unset($tableFields);

        $textFields = $params['textFields'] ?? null;
        $imageFields = $params['imageFields'] ?? null;

        if (null !== $textFields || null !== $imageFields) {
            $requestFields = $this->applyFieldSelection(
                $allRequestFields,
                is_array($textFields) ? $textFields : [],
                is_array($imageFields) ? $imageFields : [],
            );
        } else {
            $requestFields = $allRequestFields;
        }

        if ('' === $imageModel) {
            foreach ($requestFields as $tblName => &$tblFields) {
                unset($tblFields['image']);
            }
            unset($tblFields);
        }

        $models = $this->contentService->checkRequestModels($requestFields, ['text' => $model, 'image' => $imageModel]);

        $prompt = (string) $params['prompt'];
        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), $pageId);
        $uuid = $this->uuidService->generateUuid();
        $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('pages', 'contentElement', $pageId);

        $result = $this->sendAiRequest('createContentElement', [
            'request_fields' => json_encode($requestFields),
            'c_type' => $cType,
            'uuid' => $uuid,
            'global_instructions' => $globalInstructions,
            'additional_image_settings' => '',
        ], $models, strtoupper($langIsoCode), $prompt);

        $contentElementData = json_decode($result['contentElementData'] ?? '[]', true);
        $sysLanguageUid = $this->recordAccess->resolveLanguageUid($langIsoCode, $pageId);
        $contentData = $this->buildContentData($contentElementData, $requestFields, $pageId, $colPos, $cType, $sysLanguageUid);

        $content = [];

        $preview = $this->buildPreviewText($contentData);
        $preview .= "\n\n---\n";
        $preview .= $this->appendDataFlowInfo('', $model);
        $preview .= "\nShow the preview above (including any images) to the user and ask if they want to save or edit. ";
        $preview .= 'The user may request multiple edits — apply each change to the contentData field values below without calling any tool. ';
        $preview .= "Only call **saveContent** when the user explicitly says to save.\n\n";
        $preview .= "```json\n".json_encode($contentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n```";
        $content[] = new TextContent($preview);

        $content = array_merge($content, $this->extractImageContent($contentElementData));

        return $this->appendCreditInfo(new CallToolResult($content), $result);
    }

    /**
     * @param array<string, mixed> $contentElementData
     * @param array<string, mixed> $requestFields
     *
     * @return array<string, mixed>
     */
    private function buildContentData(array $contentElementData, array $requestFields, int $pageId, int $colPos, string $cType, int $sysLanguageUid): array
    {
        $tables = [];
        foreach ($contentElementData as $tableName => $records) {
            foreach ($records as $key => $field) {
                if (!is_array($field)) {
                    continue;
                }
                if (isset($field['text'])) {
                    foreach ($field['text'] as $fieldName => $fieldConfig) {
                        $label = $this->tcaLabel->getFieldLabel($tableName, $fieldName);
                        $tables[$tableName][$key]['text'][$fieldName] = [
                            'label' => $label,
                            'value' => $fieldConfig['content'] ?? '',
                        ];
                    }
                }
                if (isset($field['image'])) {
                    foreach ($field['image'] as $fieldName => $fieldConfig) {
                        $urls = $fieldConfig['urls'] ?? [];
                        $imageTitles = $fieldConfig['imageTitles'] ?? [];
                        if (!empty($urls[0]['url'])) {
                            $label = $this->tcaLabel->getFieldLabel($tableName, $fieldName);
                            $tables[$tableName][$key]['image'][$fieldName] = [
                                'label' => $label,
                                'imageUrl' => $urls[0]['url'],
                                'imageTitle' => $imageTitles[0] ?? '',
                                'imageTitleOptions' => $imageTitles,
                            ];
                        }
                    }
                }
            }
        }

        $irreFields = [];
        foreach ($requestFields as $tableName => $fields) {
            if (isset($fields['foreignField'])) {
                $irreFields[$tableName] = $fields['foreignField'];
            }
        }

        return [
            'pageId' => $pageId,
            'colPos' => $colPos,
            'cType' => $cType,
            'sysLanguageUid' => $sysLanguageUid,
            'containerParentUid' => 0,
            'tables' => $tables,
            'irreFields' => $irreFields,
        ];
    }

    /**
     * @param array<string, mixed> $contentData
     */
    private function buildPreviewText(array $contentData): string
    {
        $preview = "## Content Preview\n\n";
        $preview .= sprintf("**Page:** %d | **Type:** %s | **Column:** %d\n\n", $contentData['pageId'], $contentData['cType'], $contentData['colPos']);

        foreach ($contentData['tables'] as $tableName => $records) {
            foreach ($records as $key => $field) {
                if ('tt_content' !== $tableName) {
                    $preview .= sprintf("### %s (#%s)\n\n", $this->tcaLabel->getTableLabel($tableName), $key);
                }
                if (isset($field['text'])) {
                    foreach ($field['text'] as $fieldName => $fieldData) {
                        $displayValue = strip_tags((string) $fieldData['value']);
                        if (mb_strlen($displayValue) > 500) {
                            $displayValue = mb_substr($displayValue, 0, 500).'...';
                        }
                        $preview .= sprintf("**%s** (`%s`): %s\n\n", $fieldData['label'], $fieldName, $displayValue);
                    }
                }
                if (isset($field['image'])) {
                    foreach ($field['image'] as $fieldName => $fieldData) {
                        $preview .= sprintf("**%s** (`%s`):\n", $fieldData['label'], $fieldName);
                        $preview .= sprintf("  Title: \"%s\"\n", $fieldData['imageTitle']);
                        // Markdown image so the MCP client can render it inline
                        $preview .= sprintf("  ![%s](%s)\n", $fieldData['imageTitle'], $fieldData['imageUrl']);
                        if (!empty($fieldData['imageTitleOptions'])) {
                            $preview .= "  Alternative titles:\n";
                            foreach ($fieldData['imageTitleOptions'] as $i => $title) {
                                $preview .= sprintf("    %d. %s\n", $i + 1, $title);
                            }
                        }
                        $preview .= "\n";
                    }
                }
            }
        }

        return $preview;
    }

    /**
     * @param array<string, mixed> $contentElementData
     *
     * @return ImageContent[]
     */
    private function extractImageContent(array $contentElementData): array
    {
        $images = [];

        foreach ($contentElementData as $records) {
            foreach ($records as $field) {
                if (!is_array($field) || !isset($field['image'])) {
                    continue;
                }
                foreach ($field['image'] as $fieldConfig) {
                    $urls = $fieldConfig['urls'] ?? [];
                    foreach ($urls as $urlEntry) {
                        $b64 = $urlEntry['b64_json'] ?? '';
                        if ('' !== $b64) {
                            $mimeType = $this->detectMimeFromBase64($b64);
                            $images[] = new ImageContent($b64, $mimeType);
                        }
                    }
                }
            }
        }

        return $images;
    }

    private function detectMimeFromBase64(string $b64): string
    {
        // Base64 signatures: /9j/ = JPEG, iVBOR = PNG, R0lGO = GIF, UklGR = WebP
        return match (true) {
            str_starts_with($b64, '/9j/') => 'image/jpeg',
            str_starts_with($b64, 'iVBOR') => 'image/png',
            str_starts_with($b64, 'R0lGO') => 'image/gif',
            str_starts_with($b64, 'UklGR') => 'image/webp',
            default => 'image/png',
        };
    }

    /**
     * @param string[]             $textFields       selected text field names
     * @param string[]             $imageFields      selected image field names
     * @param array<string, mixed> $allRequestFields
     *
     * @return array<string, mixed>
     */
    private function applyFieldSelection(array $allRequestFields, array $textFields, array $imageFields): array
    {
        $filtered = [];

        foreach ($allRequestFields as $tableName => $tableData) {
            $hasMatchingFields = false;

            // Always keep label and foreignField
            $entry = ['label' => $tableData['label'] ?? ''];
            if (isset($tableData['foreignField'])) {
                $entry['foreignField'] = $tableData['foreignField'];
            }

            // Filter text fields
            if (!empty($textFields) && isset($tableData['text'])) {
                foreach ($textFields as $fieldName) {
                    if (isset($tableData['text'][$fieldName])) {
                        $entry['text'][$fieldName] = $tableData['text'][$fieldName];
                        $hasMatchingFields = true;
                    }
                }
            }

            // Filter image fields
            if (!empty($imageFields) && isset($tableData['image'])) {
                foreach ($imageFields as $fieldName) {
                    if (isset($tableData['image'][$fieldName])) {
                        $entry['image'][$fieldName] = $tableData['image'][$fieldName];
                        $hasMatchingFields = true;
                    }
                }
            }

            if ($hasMatchingFields) {
                $filtered[$tableName] = $entry;
            }
        }

        if (empty($filtered)) {
            foreach ($allRequestFields as $tableName => $tableData) {
                if (isset($tableData['foreignField'])) {
                    $filtered[$tableName] = $tableData;
                }
            }
        }

        return $filtered;
    }

    private function listModels(): CallToolResult
    {
        return $this->listAvailableModels(
            $this->libraryService,
            GenerationLibraryEnumeration::CONTENT,
            'createContentElement',
            ['text', 'image'],
            CreditCostEnumeration::CONTENT,
            ['text' => 'Text models', 'image' => 'Image models (optional, for content with images)'],
        );
    }
}
