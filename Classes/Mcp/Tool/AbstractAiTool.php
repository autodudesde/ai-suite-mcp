<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\SendRequestService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerSanitizerService;
use AutoDudes\AiSuiteMcp\Mcp\Service\SessionTrackerService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract base class for AI tools that contact the AI Suite Server.
 */
abstract class AbstractAiTool extends AbstractTool
{
    protected readonly SendRequestService $sendRequestService;
    protected readonly SessionTrackerService $creditTracker;
    protected readonly ExtensionConfiguration $extensionConfiguration;
    protected readonly Context $typo3Context;
    protected readonly DataHandlerSanitizerService $dataHandlerSanitizer;
    private bool $dataFlowNotified = false;

    public function __construct(ToolContext $mcpToolContext)
    {
        parent::__construct($mcpToolContext);
        $this->sendRequestService = $mcpToolContext->sendRequestService;
        $this->creditTracker = $mcpToolContext->creditTracker;
        $this->extensionConfiguration = $mcpToolContext->extensionConfiguration;
        $this->typo3Context = $mcpToolContext->typo3Context;
        $this->dataHandlerSanitizer = $mcpToolContext->dataHandlerSanitizer;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $models
     *
     * @return array<string, mixed> Server response body
     *
     * @throws \RuntimeException On server error
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
                $this->translate('hint.server_temporarily_unavailable')
                    ?? 'The AI Suite Server is temporarily unavailable. Please try again in a few moments.',
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
     * @throws \RuntimeException On DataHandler errors
     */
    protected function writeViaDataHandler(string $table, int $uid, array $data): void
    {
        $data = $this->dataHandlerSanitizer->sanitizeFields($table, $data);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([$table => [$uid => $data]], []);
        $dataHandler->process_datamap();

        if ([] !== $dataHandler->errorLog) {
            throw new \RuntimeException(
                $this->translate('hint.save_issue', [implode(', ', $dataHandler->errorLog)])
                    ?? 'The changes could not be saved: '.implode(', ', $dataHandler->errorLog),
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
     * @param int $perm one of Permission::PAGE_SHOW | PAGE_EDIT | PAGE_NEW | CONTENT_EDIT
     *
     * @return array<string, mixed>|CallToolResult page record array, or error result
     *
     * @throws InsufficientPermissionException permission denied
     * @throws \RuntimeException               when admin requests a non-existent page (assertPagePerm admin path)
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
        try {
            $workspaceId = $this->typo3Context->getPropertyFromAspect('workspace', 'id');
            if ($workspaceId > 0) {
                return "\n\n".($this->translate('success.written_to_workspace', [$workspaceId])
                    ?? sprintf('Changes saved to workspace %d. They must be published to become visible.', $workspaceId));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AbstractAiTool: could not resolve workspace aspect for workspace info hint', [
                'error' => $e->getMessage(),
            ]);
        }

        return '';
    }

    /**
     * @param LibraryService        $libraryService injected from the concrete tool
     * @param string                $libraryType    GenerationLibraryEnumeration constant
     * @param string                $endpoint       server endpoint name
     * @param list<string>          $featureTypes   library response keys (e.g. ['text'], ['text', 'image'])
     * @param int                   $creditCost     credit cost per generation
     * @param array<string, string> $featureLabels  human labels per feature key (e.g. ['text' => 'Text models'])
     */
    protected function listAvailableModels(
        LibraryService $libraryService,
        string $libraryType,
        string $endpoint,
        array $featureTypes,
        int $creditCost,
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
                    $this->translate('hint.model_list_unavailable')
                        ?? 'Could not fetch available models. The AI Suite Server may be temporarily unavailable.',
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
                    $this->translate('hint.no_models_available')
                        ?? 'No models available. Check your backend user permissions.',
                )],
            );
        }

        $text .= sprintf("\nEach operation costs %d credit(s).\n", $creditCost);
        $text .= 'Ask the user which model they would like to use.';

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

        // Filemount-aware
        $folder = $this->recordAccess->assertFolderReadAccess($combinedIdentifier);
        $files = $folder->getStorage()->getFilesInFolder($folder);

        return array_map(static fn ($file) => $file->getUid(), array_values($files));
    }
}
