<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Resource;

use AutoDudes\AiSuite\Domain\Repository\GlobalInstructionsRepository;
use AutoDudes\AiSuite\Service\GlobalInstructionService;
use Mcp\Server\Server;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Registers MCP Resources (passive data for clients to read).
 *
 * Resources:
 * - aisuite://instructions/page/{pid} — Global Instructions
 * - aisuite://config/site — Site languages and domains
 * - aisuite://credits/status — Credit balance + provider info
 * - aisuite://dashboard/usage — Dashboard usage data
 */
class McpResourceHandler
{
    public function __construct(
        private readonly GlobalInstructionService $globalInstructionService,
        private readonly SiteFinder $siteFinder,
        private readonly GlobalInstructionsRepository $globalInstructionsRepository,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function registerHandlers(Server $server): void
    {
        $server->registerHandler('resources/list', $this->handleList(...));
        $server->registerHandler('resources/read', $this->handleRead(...));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function handleList(?array $params): array
    {
        $resources = [];

        // Global Instructions per page
        foreach ($this->globalInstructionsRepository->findDistinctPidScopes() as $instr) {
            $resources[] = [
                'uri' => 'aisuite://instructions/page/'.$instr['pid'],
                'name' => 'Content guidelines for page '.$instr['pid'],
                'description' => 'Tone, target audience, and style guidelines for AI operations',
                'mimeType' => 'text/plain',
            ];
        }

        // Site configuration
        $resources[] = [
            'uri' => 'aisuite://config/site',
            'name' => 'Site Configuration',
            'description' => 'Available languages, domains, and site settings',
            'mimeType' => 'application/json',
        ];

        // Credit status
        $resources[] = [
            'uri' => 'aisuite://credits/status',
            'name' => 'AI Suite Credit Status',
            'description' => 'Current credit balance, configured providers, and session usage',
            'mimeType' => 'application/json',
        ];

        // Dashboard usage
        $resources[] = [
            'uri' => 'aisuite://dashboard/usage',
            'name' => 'AI Suite Usage Dashboard',
            'description' => 'Request statistics and credit consumption overview',
            'mimeType' => 'application/json',
        ];

        return ['resources' => $resources];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function handleRead(?array $params): array
    {
        $uri = $params['uri'] ?? '';

        // Global Instructions
        if (str_starts_with($uri, 'aisuite://instructions/page/')) {
            $pid = (int) substr($uri, strlen('aisuite://instructions/page/'));

            return ['contents' => [[
                'uri' => $uri,
                'mimeType' => 'text/plain',
                'text' => $this->globalInstructionService->buildGlobalInstruction('', 'pages', $pid) ?: 'No instructions configured for this page.',
            ]]];
        }

        // Site configuration
        if ('aisuite://config/site' === $uri) {
            return ['contents' => [[
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($this->getSiteConfig(), JSON_PRETTY_PRINT),
            ]]];
        }

        // Credit status
        if ('aisuite://credits/status' === $uri) {
            return ['contents' => [[
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($this->getCreditStatus(), JSON_PRETTY_PRINT),
            ]]];
        }

        // Dashboard usage
        if ('aisuite://dashboard/usage' === $uri) {
            return ['contents' => [[
                'uri' => $uri,
                'mimeType' => 'application/json',
                'text' => json_encode($this->getDashboardUsage(), JSON_PRETTY_PRINT),
            ]]];
        }

        return ['contents' => []];
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
                    'twoLetterIsoCode' => $lang->getTwoLetterIsoCode(),
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
