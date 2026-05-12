<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Generate;

use AutoDudes\AiSuite\Domain\Model\Dto\BackgroundTask;
use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuite\Enumeration\CreditCostEnumeration;
use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\UuidService;
use AutoDudes\AiSuiteMcp\Mcp\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use AutoDudes\AiSuiteMcp\Mcp\ToolDescriptionSnippets;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Optimize content elements using AI with a free-text prompt.
 *
 * Uses TYPO3 FormDataCompiler to resolve available fields and current values per CType.
 *
 * < 5 field-tasks: synchronous. >= 5: async via Workflow queue.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class OptimizeContentTool extends AbstractAiTool
{
    private const BATCH_THRESHOLD = 5;

    private const OPTIMIZABLE_FIELDS = ['bodytext', 'header', 'subheader'];
    protected ?string $requiredScope = 'mcp:generate';

    /**
     * Per-call buffer for items skipped due to missing edit-permission. Filled in
     * resolveContentElements(); flushed into the result text via formatPermissionSkips().
     *
     * @var list<string>
     */
    private array $permissionSkips = [];

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly LibraryService $libraryService,
        private readonly UuidService $uuidService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly ContentRepository $contentRepository,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'optimizeContent';
    }

    public function getDescription(): string
    {
        return 'Optimize existing content elements with a free-text prompt (e.g. rewrite tone, shorten, simplify). Requires either pageIds or contentUids — at least one must be provided. Two approaches: '
            .ToolDescriptionSnippets::APPROACH_A
            .'(B) Read content via readRecords, rewrite it yourself '.ToolDescriptionSnippets::APPROACH_B_PERSIST.' '
            .ToolDescriptionSnippets::APPROACH_A_PREVIEW_AND_PERSIST;
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Optimization instruction (e.g. "Formuliere von Sie in Du um", "Kürze auf das Wesentliche").',
                ],
                'pageIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Process all content elements on these pages.',
                ],
                'contentUids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'Process specific tt_content UIDs.',
                ],
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'default' => ['bodytext'],
                    'description' => 'Which text fields to optimize: bodytext, header, subheader. Default: bodytext only.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'AI model identifier. Omit to see available models.',
                ],
                'language' => ['type' => 'string', 'description' => 'ISO language code (e.g. de, en). Defaults to the site default language.'],
            ],
            'required' => ['prompt'],
            'anyOf' => [
                ['required' => ['pageIds']],
                ['required' => ['contentUids']],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $this->permissionSkips = [];
        $prompt = (string) ($params['prompt'] ?? '');
        $model = (string) ($params['model'] ?? '');
        $requestedFields = $params['fields'] ?? ['bodytext'];

        if ('' === $prompt) {
            return new CallToolResult([new TextContent('"prompt" is required.')], isError: true);
        }

        if ('' === $model) {
            return $this->showOptions($params, $requestedFields, $prompt);
        }

        $this->permissionService->validateModelAccess($model);

        $elements = $this->resolveContentElements($params, $requestedFields);
        if (empty($elements)) {
            $message = 'No content elements with optimizable text fields found.';
            if (count($this->permissionSkips) > 0) {
                $message .= "\n\n".$this->formatPermissionSkips();
            }

            return new CallToolResult([new TextContent($message)], isError: true);
        }

        $langIsoCode = $this->resolveLanguageIsoCode((string) ($params['language'] ?? ''), (int) ($elements[0]['pid'] ?? 1));
        $totalTasks = array_sum(array_map(static fn (array $el) => count($el['_fields']), $elements));

        if ($totalTasks < self::BATCH_THRESHOLD) {
            return $this->processSynchronous($elements, $prompt, $model, $langIsoCode);
        }

        return $this->processAsync($elements, $prompt, $model, $langIsoCode);
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $requestedFields
     */
    private function showOptions(array $params, array $requestedFields, string $prompt): CallToolResult
    {
        $elements = $this->resolveContentElements($params, $requestedFields);
        $totalTasks = array_sum(array_map(static fn (array $el) => count($el['_fields']), $elements));

        $text = sprintf("## Content Optimization — %d elements, %d fields\n\n", count($elements), $totalTasks);
        $text .= sprintf("**Prompt:** %s\n\n", $prompt);

        if ($totalTasks >= self::BATCH_THRESHOLD) {
            $text .= "⚡ Will be processed asynchronously in the background.\n\n";
        }

        $text .= "### Available models:\n\n";

        $modelsResult = $this->listAvailableModels(
            $this->libraryService,
            GenerationLibraryEnumeration::RTE_CONTENT,
            'editContent',
            ['text'],
            CreditCostEnumeration::EASY_LANGUAGE,
            ['text' => 'Content optimization models'],
        );

        foreach ($modelsResult->content as $content) {
            if ($content instanceof TextContent) {
                $text .= $content->text;
            }
        }
        $text .= "\n\nPresent the models to the user and ask which one to use.";

        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * @param list<array<string, mixed>> $elements
     */
    private function processSynchronous(array $elements, string $prompt, string $model, string $langIsoCode): CallToolResult
    {
        $results = [];
        $lastResult = [];

        foreach ($elements as $element) {
            $uid = (int) $element['uid'];
            $pageId = (int) $element['pid'];
            $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('pages', 'editContent', $pageId);

            foreach ($element['_fields'] as $fieldName => $fieldValue) {
                $plainContent = strip_tags((string) $fieldValue);
                if ('' === trim($plainContent)) {
                    continue;
                }

                $uuid = $this->uuidService->generateUuid();
                $isRte = 'bodytext' === $fieldName;
                $sendContent = $isRte ? (string) $fieldValue : $plainContent;

                $requestData = [
                    'uuid' => $uuid,
                    'selectedContent' => $sendContent,
                    'wholeContent' => $sendContent,
                    'type' => '',
                    'prompt' => $prompt,
                    'globalInstructions' => $globalInstructions,
                ];

                try {
                    $result = $this->sendAiRequest('editContent', $requestData, ['text' => $model], $langIsoCode);
                    $lastResult = $result;
                    $optimized = $result['editContentResult'] ?? '';
                    if ('' !== $optimized) {
                        $results[] = [
                            'uid' => $uid,
                            'field' => $fieldName,
                            'header' => $element['header'] ?? '',
                            'original' => mb_substr($plainContent, 0, 80).(mb_strlen($plainContent) > 80 ? '...' : ''),
                            'optimized' => $optimized,
                        ];
                    }
                } catch (\Throwable $e) {
                    $this->logger->error('OptimizeContent sync failed', ['uid' => $uid, 'field' => $fieldName, 'error' => $e->getMessage()]);
                }
            }
        }

        if (empty($results)) {
            return new CallToolResult([new TextContent('No results could be generated.')]);
        }

        $text = $this->appendDataFlowInfo('', $model);
        $text .= "## Optimization Results\n\n";

        foreach ($results as $r) {
            $text .= sprintf("### tt_content:%d `%s` (%s)\n", $r['uid'], $r['field'], $r['header']);
            $text .= sprintf("**Original:** %s\n", $r['original']);
            $text .= sprintf("**Optimized:**\n%s\n\n", $r['optimized']);
        }

        $text .= "---\n";
        $text .= "Review the results. To apply, call `previewRecords` then `writeRecords` for each element.\n";
        $text .= 'Ask the user which results to apply.';

        if (count($this->permissionSkips) > 0) {
            $text .= "\n\n".$this->formatPermissionSkips();
        }

        return $this->appendCreditInfo(new CallToolResult([new TextContent($text)]), $lastResult);
    }

    /**
     * @param list<array<string, mixed>> $elements
     */
    private function processAsync(array $elements, string $prompt, string $model, string $langIsoCode): CallToolResult
    {
        $parentUuid = $this->uuidService->generateUuid();
        $payload = [];
        $bulkPayload = [];

        foreach ($elements as $element) {
            $uid = (int) $element['uid'];
            $pageId = (int) $element['pid'];
            $globalInstructions = $this->globalInstructionService->buildGlobalInstruction('pages', 'editContent', $pageId);
            $globalInstructionsOverride = $this->globalInstructionService->checkOverridePredefinedPrompt('pages', 'editContent', [$pageId]);

            foreach ($element['_fields'] as $fieldName => $fieldValue) {
                $content = (string) $fieldValue;
                if ('' === trim(strip_tags($content))) {
                    continue;
                }

                $uuid = $this->uuidService->generateUuid();

                $bulkPayload[] = new BackgroundTask(
                    'editContent',
                    'editContent',
                    $parentUuid,
                    $uuid,
                    $fieldName,
                    'tt_content',
                    'uid',
                    $uid,
                    0,
                    '',
                );

                $payload[] = [
                    'field_label' => $fieldName,
                    'request_content' => $content,
                    'uuid' => $uuid,
                    'global_instructions' => $globalInstructions,
                    'override_predefined_prompt' => $globalInstructionsOverride,
                    'operation' => 'custom',
                    'custom_prompt' => $prompt,
                ];
            }
        }

        if (empty($payload)) {
            return new CallToolResult([new TextContent('No optimizable content found.')], isError: true);
        }

        $result = $this->sendRequestService->sendDataRequest(
            'createMassAction',
            [
                'uuid' => $parentUuid,
                'payload' => $payload,
                'scope' => 'editContent',
                'type' => 'editContent',
            ],
            '',
            strtoupper($langIsoCode),
            ['text' => $model],
        );

        if ('Error' === $result->getType()) {
            return new CallToolResult(
                [new TextContent('Server error: '.($result->getResponseData()['message'] ?? 'unknown'))],
                isError: true,
            );
        }

        $this->backgroundTaskRepository->insertBackgroundTasks($bulkPayload);

        $text = "## Batch content optimization started\n\n";
        $text .= sprintf("**Task ID:** `%s`\n", $parentUuid);
        $text .= sprintf("**Elements:** %d | **Tasks:** %d | **Model:** %s\n", count($elements), count($payload), $model);
        $text .= sprintf("\nUse **getTaskStatus(taskId: \"%s\")** to check progress.", $parentUuid);

        if (count($this->permissionSkips) > 0) {
            $text .= "\n\n".$this->formatPermissionSkips();
        }

        return new CallToolResult([new TextContent($text)]);
    }

    private function formatPermissionSkips(): string
    {
        return sprintf(
            "ℹ️  Skipped %d item(s) due to missing edit permission:\n%s",
            count($this->permissionSkips),
            implode("\n", array_map(static fn (string $line): string => '- '.$line, $this->permissionSkips)),
        );
    }

    /**
     * Resolve content elements using TYPO3 FormDataCompiler.
     * Per record: determines available fields (via TCA showitem) and loads current values (via databaseRow).
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $requestedFields
     *
     * @return list<array{uid: int, pid: int, header: string, _fields: array<string, string>}>
     */
    private function resolveContentElements(array $params, array $requestedFields): array
    {
        $contentUids = $params['contentUids'] ?? [];
        $pageIds = $params['pageIds'] ?? [];

        if (empty($contentUids) && empty($pageIds)) {
            return [];
        }

        // Permission-filter (skip-and-report): drop pageIds/contentUids the user cannot edit.
        // Skipped items are recorded in $this->permissionSkips for the result formatter.
        $allowedPageIds = [];
        foreach ($pageIds as $pageId) {
            $pid = (int) $pageId;

            try {
                $this->assertPagePerm($pid, Permission::CONTENT_EDIT);
                $allowedPageIds[] = $pid;
            } catch (InsufficientPermissionException $e) {
                $this->logger->warning('OptimizeContent: skipping page — insufficient permission', [
                    'pageId' => $pid,
                    'reason' => $e->getMessage(),
                ]);
                $this->permissionSkips[] = sprintf('Page %d: %s', $pid, $e->getMessage());
            }
        }

        $allowedContentUids = [];
        foreach ($contentUids as $contentUid) {
            $cuid = (int) $contentUid;

            try {
                $this->assertRecordEditAccess('tt_content', $cuid);
                $allowedContentUids[] = $cuid;
            } catch (InsufficientPermissionException $e) {
                $this->logger->warning('OptimizeContent: skipping tt_content — insufficient permission', [
                    'uid' => $cuid,
                    'reason' => $e->getMessage(),
                ]);
                $this->permissionSkips[] = sprintf('tt_content:%d: %s', $cuid, $e->getMessage());
            } catch (\RuntimeException $e) {
                $this->logger->warning('OptimizeContent: tt_content record not found, skipping', [
                    'uid' => $cuid,
                    'reason' => $e->getMessage(),
                ]);
                $this->permissionSkips[] = sprintf('tt_content:%d: not found', $cuid);
            }
        }

        if (empty($allowedPageIds) && empty($allowedContentUids)) {
            return [];
        }

        $uids = $this->contentRepository->findUidsByPagesOrUids($allowedPageIds, $allowedContentUids);
        $request = $this->userContext->getServerRequest();

        $result = [];
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class);

        foreach ($uids as $uid) {
            try {
                $formData = $formDataCompiler->compile(
                    [
                        'request' => $request,
                        'tableName' => 'tt_content',
                        'vanillaUid' => (int) $uid,
                        'command' => 'edit',
                        'returnUrl' => '',
                        'defaultValues' => [],
                    ],
                    GeneralUtility::makeInstance(TcaDatabaseRecord::class),
                );

                $columnsToProcess = $formData['columnsToProcess'] ?? [];
                $databaseRow = $formData['databaseRow'] ?? [];

                // Intersect: TCA-available fields ∩ requested fields ∩ OPTIMIZABLE_FIELDS ∩ non-empty
                $fields = [];
                foreach ($requestedFields as $field) {
                    $field = (string) $field;
                    if (
                        \in_array($field, self::OPTIMIZABLE_FIELDS, true)
                        && \in_array($field, $columnsToProcess, true)
                    ) {
                        $value = $databaseRow[$field] ?? '';
                        // databaseRow may return arrays for some field types
                        if (\is_array($value)) {
                            $value = implode(' ', $value);
                        }
                        if ('' !== trim(strip_tags((string) $value))) {
                            $fields[$field] = (string) $value;
                        }
                    }
                }

                if (!empty($fields)) {
                    $header = $databaseRow['header'] ?? '';
                    if (\is_array($header)) {
                        $header = implode(' ', $header);
                    }

                    $result[] = [
                        'uid' => (int) $uid,
                        'pid' => (int) ($databaseRow['pid'] ?? 0),
                        'header' => (string) $header,
                        '_fields' => $fields,
                    ];
                }
            } catch (\Throwable $e) {
                $this->logger->error('FormDataCompiler failed for tt_content', ['uid' => $uid, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }
}
