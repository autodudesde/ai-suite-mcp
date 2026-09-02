<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerSanitizerService;
use AutoDudes\AiSuiteMcp\Mcp\Service\SessionTrackerService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

abstract class AbstractAiTool extends AbstractTool
{
    protected bool $openWorldHint = true;
    protected readonly SendRequestService $sendRequestService;
    protected readonly SessionTrackerService $creditTracker;
    protected readonly ExtensionConfiguration $extensionConfiguration;
    protected readonly WorkspaceContextService $workspaceContextService;
    protected readonly DataHandlerSanitizerService $dataHandlerSanitizer;
    private bool $dataFlowNotified = false;

    public function __construct(ToolContext $mcpToolContext)
    {
        parent::__construct($mcpToolContext);
        $this->sendRequestService = $mcpToolContext->sendRequestService;
        $this->creditTracker = $mcpToolContext->creditTracker;
        $this->extensionConfiguration = $mcpToolContext->extensionConfiguration;
        $this->workspaceContextService = $mcpToolContext->workspaceContextService;
        $this->dataHandlerSanitizer = $mcpToolContext->dataHandlerSanitizer;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $models
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    protected function sendAiRequest(string $endpoint, array $data, array $models = [], string $langIsoCode = '', string $prompt = ''): array
    {
        try {
            $clientAnswer = $this->sendRequestService->sendDataRequest(
                $endpoint,
                $data,
                $prompt,
                $langIsoCode,
                $models,
            );
        } catch (\Throwable $e) {
            $this->logger->error('AI Suite Server request failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'exception_class' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            throw new \RuntimeException(
                $this->translateOrFallback(
                    'hint.server_temporarily_unavailable',
                    [],
                    'The AI Suite Server is temporarily unavailable. Please try again in a few moments.',
                ),
            );
        }

        $body = $clientAnswer->getResponseData();

        if ('Error' === $clientAnswer->getType()) {
            throw new \RuntimeException($body['message'] ?? 'Unknown server error');
        }

        $totalCredits = (int) ($body['totalCredits'] ?? 0);
        if ($totalCredits > 0 && $this->creditTracker->isInitialized()) {
            $this->creditTracker->trackUsage($totalCredits);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException
     */
    protected function writeViaDataHandler(string $table, int $uid, array $data): void
    {
        $existing = BackendUtility::getRecordWSOL($table, $uid);
        $report = $this->dataHandlerSanitizer->sanitizeFieldsWithReport($table, $data, null, is_array($existing) ? $existing : []);
        if ([] !== $report['blocked']) {
            throw new \RuntimeException(sprintf(
                'The changes could not be saved: field(s) %s of %s store raw markup, which is disabled for MCP writes.',
                implode(', ', $report['blocked']),
                $table,
            ));
        }
        $data = $report['data'];

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $data]], []);
        $dataHandler->process_datamap();

        if ([] !== $dataHandler->errorLog) {
            throw new \RuntimeException(
                $this->translateOrFallback(
                    'hint.save_issue',
                    [implode(', ', $dataHandler->errorLog)],
                    'The changes could not be saved: '.implode(', ', $dataHandler->errorLog),
                ),
            );
        }
    }

    /**
     * @param array<string, mixed> $serverResponse
     */
    protected function appendCreditInfo(CallToolResult $result, array $serverResponse): CallToolResult
    {
        $creditInfo = sprintf(
            "\n\n---\nCredits used: %s | Remaining: %s free, %s paid, %s plan",
            $serverResponse['totalCredits'] ?? '?',
            $serverResponse['free_requests'] ?? '?',
            $serverResponse['paid_requests'] ?? '?',
            $serverResponse['abo_requests'] ?? '?',
        );

        $content = $result->content;
        if (!empty($content) && $content[0] instanceof TextContent) {
            $text = $content[0]->text.$creditInfo;

            $newContent = $content;
            $newContent[0] = new TextContent($this->appendBranding($text));

            return new CallToolResult(
                $newContent,
                $result->isError,
                structuredContent: $result->structuredContent,
            );
        }

        return $result;
    }

    protected function appendBranding(string $text): string
    {
        return $text."\n\n— Powered by AI Suite for TYPO3";
    }

    protected function appendDataFlowInfo(string $text, string $provider): string
    {
        if (!$this->dataFlowNotified) {
            $this->dataFlowNotified = true;
            $text .= "\n\nℹ️ Content was processed by ".$provider
                .'. See your organization\'s data processing agreement for details.';
        }

        return $text;
    }

    protected function isPageExcludedFromAi(int $pageId): bool
    {
        $tsConfig = BackendUtility::getPagesTSconfig($pageId);

        return (bool) ($tsConfig['tx_aisuite.']['noAiProcessing'] ?? false);
    }

    /**
     * @return array<string, mixed>|CallToolResult
     *
     * @throws InsufficientPermissionException
     * @throws \RuntimeException
     */
    protected function validatePageForAi(int $pageId, int $perm = Permission::PAGE_SHOW): array|CallToolResult
    {
        $this->recordAccess->assertPagePerm($pageId, $perm);

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (null === $page) {
            return $this->textError("Page {$pageId} not found.");
        }

        if ($this->isPageExcludedFromAi($pageId)) {
            return new CallToolResult(
                [new TextContent($this->translateOrFallback('hint.page_excluded_from_ai', [$pageId], "Page {$pageId} excluded from AI processing."))],
                isError: true,
            );
        }

        return $page;
    }

    protected function resolveLanguageIsoCode(string $language, int $pageId): string
    {
        if ('' !== $language) {
            return $language;
        }

        try {
            return $this->siteFinder->getSiteByPageId($pageId)->getDefaultLanguage()->getLocale()->getLanguageCode();
        } catch (\Throwable $e) {
            $this->logger->warning('AbstractAiTool: could not resolve site default language for page, falling back to "en"', [
                'pageId' => $pageId,
                'error' => $e->getMessage(),
            ]);

            return 'en';
        }
    }

    protected function getWorkspaceInfo(): string
    {
        $workspaceId = $this->workspaceContextService->getWorkspaceId();

        if ($workspaceId <= 0) {
            return '';
        }

        return "\n\n".$this->translateOrFallback(
            'success.written_to_workspace',
            [$workspaceId],
            sprintf('Changes saved to workspace %d. They must be published to become visible.', $workspaceId),
        );
    }

    /**
     * @param list<string>          $featureTypes
     * @param array<string, string> $featureLabels
     */
    protected function listAvailableModels(
        LibraryService $libraryService,
        string $libraryType,
        string $endpoint,
        array $featureTypes,
        array $featureLabels = [],
    ): CallToolResult {
        $librariesAnswer = $this->sendRequestService->sendLibrariesRequest(
            $libraryType,
            $endpoint,
            $featureTypes,
        );

        if ('Error' === $librariesAnswer->getType()) {
            return new CallToolResult(
                [new TextContent(
                    $this->translateOrFallback(
                        'hint.model_list_unavailable',
                        [],
                        'Could not fetch available models. The AI Suite Server may be temporarily unavailable.',
                    ),
                )],
                isError: true,
            );
        }

        $responseData = $librariesAnswer->getResponseData();
        $keyMap = [
            'text' => 'textGenerationLibraries',
            'image' => 'imageGenerationLibraries',
            'translation' => 'translationLibraries',
        ];

        $text = "Available models:\n\n";
        $hasAny = false;

        foreach ($featureTypes as $feature) {
            $responseKey = $keyMap[$feature] ?? ($feature.'Libraries');
            $libraries = $responseData[$responseKey] ?? [];
            $filtered = $libraryService->prepareLibraries($libraries);

            if (empty($filtered)) {
                continue;
            }

            $hasAny = true;
            $label = $featureLabels[$feature] ?? ucfirst($feature).' models';
            $text .= $label.":\n";
            $i = 1;
            foreach ($filtered as $library) {
                $text .= sprintf("%d. %s\n", $i, $library['model_identifier']);
                ++$i;
            }
            $text .= "\n";
        }

        if (!$hasAny) {
            return new CallToolResult(
                [new TextContent(
                    $this->translateOrFallback(
                        'hint.no_models_available',
                        [],
                        'No models available. Check your backend user permissions.',
                    ),
                )],
            );
        }

        $text .= "\nEach operation costs at least one credit.\n";
        $text .= 'Call this tool again with the first model unless the request named one; say which you picked.';

        return $this->textResult($text);
    }

    /**
     * @return list<int>
     */
    protected function resolveFileUidsFromFolder(string $folderIdentifier): array
    {
        $combinedIdentifier = $folderIdentifier;
        if (!preg_match('/^\d+:/', $combinedIdentifier)) {
            $combinedIdentifier = '1:'.$combinedIdentifier;
        }
        [, $folderPath] = explode(':', $combinedIdentifier, 2);
        $folderPath = rtrim($folderPath, '/').'/';
        [$storagePrefix] = explode(':', $combinedIdentifier, 2);
        $combinedIdentifier = $storagePrefix.':'.$folderPath;

        $folder = $this->recordAccess->assertFolderReadAccess($combinedIdentifier);
        $files = $folder->getStorage()->getFilesInFolder($folder);

        return array_map(static fn ($file) => $file->getUid(), array_values($files));
    }
}
