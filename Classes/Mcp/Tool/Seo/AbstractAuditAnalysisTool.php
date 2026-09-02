<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Seo;

use AutoDudes\AiSuite\Enumeration\GenerationLibraryEnumeration;
use AutoDudes\AiSuite\Service\LibraryService;
use AutoDudes\AiSuite\Service\MetadataService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractAiTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;

abstract class AbstractAuditAnalysisTool extends AbstractAiTool
{
    /**
     * @var array<string, string>
     */
    private const LANGUAGE_MAIN_MARKET = [
        'de' => 'DE', 'en' => 'US', 'fr' => 'FR', 'it' => 'IT', 'es' => 'ES',
        'nl' => 'NL', 'pl' => 'PL', 'pt' => 'PT', 'da' => 'DK', 'sv' => 'SE',
        'nb' => 'NO', 'no' => 'NO', 'fi' => 'FI', 'cs' => 'CZ', 'tr' => 'TR',
    ];
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        protected readonly MetadataService $metadataService,
        protected readonly LibraryService $libraryService,
    ) {
        parent::__construct($mcpToolContext);
    }

    /**
     * @return array{type: string, description: string}
     */
    protected static function urlSchemaProperty(): array
    {
        return [
            'type' => 'string',
            'description' => 'Absolute, publicly reachable URL of the page to analyse.',
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected static function commonSchemaProperties(): array
    {
        return [
            'model' => [
                'type' => 'string',
                'description' => 'Optional text model for the AI coverage rating (e.g. ChatGPT). Omit to use the first model available to your user.',
            ],
            'market' => [
                'type' => 'string',
                'description' => 'Search market as an ISO locale like "de-DE" or "de-AT". Omit to use the default language of the TYPO3 site (page tree) the URL belongs to; required for URLs outside this instance.',
            ],
            'language' => [
                'type' => 'string',
                'description' => 'Two-letter language for the analysis texts. Default: de.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function resolveMarket(array $params, string $url): CallToolResult|string
    {
        $market = str_replace('_', '-', trim((string) ($params['market'] ?? '')));
        if (1 === preg_match('/^([a-zA-Z]{2})-([a-zA-Z]{2})$/', $market, $matches)) {
            return strtolower($matches[1]).'-'.strtoupper($matches[2]);
        }

        $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteHost = (string) preg_replace('/^www\./', '', strtolower($site->getBase()->getHost()));
                if ('' === $siteHost || $siteHost !== $host) {
                    continue;
                }
                $locale = $site->getDefaultLanguage()->getLocale();
                $language = strtolower($locale->getLanguageCode());
                $country = strtoupper((string) $locale->getCountryCode());
                if ('' === $country) {
                    $country = self::LANGUAGE_MAIN_MARKET[$language] ?? '';
                }
                if ('' !== $language && '' !== $country) {
                    return $language.'-'.$country;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Market derivation from sites failed', ['error' => $e->getMessage()]);
        }

        return $this->textError('market is required for this URL (ISO locale like "de-DE") - it could not be derived from the TYPO3 sites.');
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function validatedUrl(array $params): CallToolResult|string
    {
        $url = trim((string) ($params['url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL)
            || !\in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
        ) {
            return $this->textError('url must be an absolute http(s) URL (e.g. https://example.com/page).');
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function normalizedLanguage(array $params): string
    {
        $language = strtolower(substr(trim((string) ($params['language'] ?? '')), 0, 2));

        return '' !== $language ? $language : 'de';
    }

    protected function fetchAnalysisContent(string $url): CallToolResult|string
    {
        try {
            $content = $this->metadataService->fetchContentFromUrl($url);
        } catch (\Throwable $e) {
            $this->logger->warning('Audit analysis could not fetch the page content', ['url' => $url, 'error' => $e->getMessage()]);
            $content = '';
        }
        if ('' === trim($content)) {
            return $this->textError('The page content could not be fetched — the URL must be publicly reachable.');
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function resolveTextModel(array $params): CallToolResult|string
    {
        $model = trim((string) ($params['model'] ?? ''));
        if ('' !== $model) {
            $this->permissionService->validateModelAccess($model);

            return $model;
        }

        $librariesAnswer = $this->sendRequestService->sendLibrariesRequest(
            GenerationLibraryEnumeration::METADATA,
            'createMetadata',
            ['text'],
        );
        if ('Error' !== $librariesAnswer->getType()) {
            $libraries = $this->libraryService->prepareLibraries(
                $librariesAnswer->getResponseData()['textGenerationLibraries'] ?? [],
            );
            $first = $libraries[0]['model_identifier'] ?? '';
            if ('' !== (string) $first) {
                return (string) $first;
            }
        }

        return $this->textError($this->translateOrFallback(
            'hint.no_models_available',
            [],
            'No models available. Check your backend user permissions.',
        ));
    }

    /**
     * @param array<string, mixed> $summary
     */
    protected static function coverageLine(array $summary): string
    {
        return sprintf(
            '- Coverage: %d open, %d partial, %d answered (of %d)',
            (int) ($summary['open'] ?? 0),
            (int) ($summary['partial'] ?? 0),
            (int) ($summary['answered'] ?? 0),
            (int) ($summary['total'] ?? 0),
        );
    }

    protected static function volumeLabel(mixed $searchVolume, mixed $difficulty): string
    {
        return sprintf(
            '%s searches/month, difficulty %s',
            null === $searchVolume ? 'unknown' : (string) (int) $searchVolume,
            null === $difficulty ? 'unknown' : (string) (int) $difficulty,
        );
    }
}
