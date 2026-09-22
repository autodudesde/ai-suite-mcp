<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Resource;

use AutoDudes\AiSuite\Domain\Repository\GlobalInstructionsRepository;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientScopeException;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use AutoDudes\AiSuiteMcp\Mcp\Utility\OperatingGuidelines;
use AutoDudes\AiSuiteMcp\Mcp\Utility\RequestParamsNormalizer;
use Mcp\Server\Server;
use Mcp\Shared\ErrorData;
use Mcp\Shared\McpError;
use Mcp\Types\CacheableResult;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListResourceTemplatesResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\Resource;
use Mcp\Types\ResourceTemplate;
use Mcp\Types\TextResourceContents;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

class McpResourceHandler
{
    private const REQUIRED_SCOPE = 'mcp:read';

    public function __construct(
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly SiteFinder $siteFinder,
        private readonly GlobalInstructionsRepository $globalInstructionsRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly McpUserContext $userContext,
        private readonly PermissionService $permissionService,
    ) {}

    public function registerHandlers(Server $server): void
    {
        $server->registerHandler('resources/list', $this->handleList(...));
        $server->registerHandler('resources/templates/list', $this->handleTemplatesList(...));
        $server->registerHandler('resources/read', $this->handleRead(...));
    }

    private function canReadResources(): bool
    {
        return $this->permissionService->isScopeGranted(self::REQUIRED_SCOPE, $this->userContext->getScopes());
    }

    private function handleList(mixed $params): ListResourcesResult
    {
        if (!$this->canReadResources()) {
            return $this->cacheable(new ListResourcesResult([]));
        }

        $resources = [];

        $resources[] = new Resource(
            name: 'AI Suite Operating Guidelines',
            uri: 'aisuite://guidelines',
            description: 'Workflow rules the server expects clients to follow',
            mimeType: 'text/markdown',
        );

        foreach ($this->globalInstructionsRepository->findDistinctPidScopes() as $instr) {
            $resources[] = new Resource(
                name: 'Content guidelines for page '.$instr['pid'],
                uri: 'aisuite://instructions/page/'.$instr['pid'],
                description: 'Tone, target audience, and style guidelines for AI operations',
                mimeType: 'text/plain',
            );
        }

        $resources[] = new Resource(
            name: 'Site Configuration',
            uri: 'aisuite://config/site',
            description: 'Available languages, domains, and site settings',
            mimeType: 'application/json',
        );

        $resources[] = new Resource(
            name: 'AI Suite Credit Status',
            uri: 'aisuite://credits/status',
            description: 'Current credit balance, configured providers, and session usage',
            mimeType: 'application/json',
        );

        $resources[] = new Resource(
            name: 'AI Suite Usage Dashboard',
            uri: 'aisuite://dashboard/usage',
            description: 'Request statistics and credit consumption overview',
            mimeType: 'application/json',
        );

        return $this->cacheable(new ListResourcesResult($resources));
    }

    private function handleTemplatesList(mixed $params): ListResourceTemplatesResult
    {
        if (!$this->canReadResources()) {
            return $this->cacheable(new ListResourceTemplatesResult([]));
        }

        return $this->cacheable(new ListResourceTemplatesResult([
            new ResourceTemplate(
                name: 'Content guidelines for a page',
                uriTemplate: 'aisuite://instructions/page/{pid}',
                description: 'Tone, target audience, and style guidelines for AI operations on the given page UID',
                mimeType: 'text/plain',
            ),
        ]));
    }

    private function handleRead(mixed $params): ReadResourceResult
    {
        if (!$this->canReadResources()) {
            throw new InsufficientScopeException('Reading AI Suite resources requires the "mcp:read" scope.');
        }

        $uri = (string) (RequestParamsNormalizer::toArray($params)['uri'] ?? '');

        if ('aisuite://guidelines' === $uri) {
            return $this->cacheable(new ReadResourceResult([
                new TextResourceContents(
                    text: OperatingGuidelines::get(),
                    uri: $uri,
                    mimeType: 'text/markdown',
                ),
            ]));
        }

        if (str_starts_with($uri, 'aisuite://instructions/page/')) {
            $pid = (int) substr($uri, strlen('aisuite://instructions/page/'));

            return $this->cacheable(new ReadResourceResult([
                new TextResourceContents(
                    text: $this->globalInstructionService->buildGlobalInstruction('', 'pages', $pid) ?: 'No instructions configured for this page.',
                    uri: $uri,
                    mimeType: 'text/plain',
                ),
            ]));
        }

        if ('aisuite://config/site' === $uri) {
            return $this->cacheable(new ReadResourceResult([
                new TextResourceContents(
                    text: (string) json_encode($this->getSiteConfig(), JSON_PRETTY_PRINT),
                    uri: $uri,
                    mimeType: 'application/json',
                ),
            ]));
        }

        if ('aisuite://credits/status' === $uri) {
            return $this->cacheable(new ReadResourceResult([
                new TextResourceContents(
                    text: (string) json_encode($this->getCreditStatus(), JSON_PRETTY_PRINT),
                    uri: $uri,
                    mimeType: 'application/json',
                ),
            ]));
        }

        if ('aisuite://dashboard/usage' === $uri) {
            return $this->cacheable(new ReadResourceResult([
                new TextResourceContents(
                    text: (string) json_encode($this->getDashboardUsage(), JSON_PRETTY_PRINT),
                    uri: $uri,
                    mimeType: 'application/json',
                ),
            ]));
        }

        throw new McpError(new ErrorData(
            code: -32602,
            message: sprintf('Unknown resource URI: %s', $uri),
        ));
    }

    /**
     * @template T of CacheableResult
     *
     * @param T $result
     *
     * @return T
     */
    private function cacheable(CacheableResult $result): CacheableResult
    {
        $result->setCacheHints(0, CacheableResult::CACHE_SCOPE_PUBLIC);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function getSiteConfig(): array
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $languages = [];
            foreach ($site->getLanguages() as $lang) {
                $languages[] = [
                    'id' => $lang->getLanguageId(),
                    'title' => $lang->getTitle(),
                    'locale' => $lang->getLocale()->posixFormatted(),
                    'twoLetterIsoCode' => $lang->getLocale()->getLanguageCode(),
                ];
            }
            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'rootPageId' => $site->getRootPageId(),
                'base' => (string) $site->getBase(),
                'languages' => $languages,
            ];
        }

        return ['sites' => $sites];
    }

    /**
     * @return array<string, mixed>
     */
    private function getCreditStatus(): array
    {
        return [
            'message' => 'Credit status is available after the first AI tool call.',
            'configured_providers' => $this->getConfiguredProviders(),
            'data_processing_info' => 'Content is sent to the configured AI providers for processing. '
                .'Ensure your organization has appropriate data processing agreements in place.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getDashboardUsage(): array
    {
        return [
            'message' => 'Dashboard usage data is available after the first AI tool call.',
            'configured_providers' => $this->getConfiguredProviders(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfiguredProviders(): array
    {
        $extConf = $this->extensionConfiguration->get('ai_suite_mcp');
        $providers = [];

        if (!empty($extConf['openAiApiKey'] ?? '')) {
            $providers['text'] = 'ChatGPT (OpenAI)';
        }
        if (!empty($extConf['anthropicApiKey'] ?? '')) {
            $providers['text_alt'] = 'Anthropic (Claude)';
        }
        if (!empty($extConf['deeplApiKey'] ?? '')) {
            $providers['translation'] = 'DeepL (DE/EU)';
        }
        if (!empty($extConf['googleTranslateApiKey'] ?? '')) {
            $providers['translation_alt'] = 'Google Translate';
        }

        return $providers;
    }
}
