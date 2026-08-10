<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\SiteService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

final class SiteLanguageService implements SingletonInterface
{
    /**
     * @var null|list<string>
     */
    private ?array $isoCodeCache = null;

    /**
     * @var array<int, array<int, string>>
     */
    private array $isoCodeByPage = [];

    public function __construct(
        private readonly SiteService $siteService,
        private readonly SiteFinder $siteFinder,
        private readonly LoggerInterface $logger,
    ) {}

    public function getIsoCodeForLanguageUid(int $pageId, int $languageUid): ?string
    {
        if (!array_key_exists($pageId, $this->isoCodeByPage)) {
            $this->isoCodeByPage[$pageId] = $this->loadLanguageMap($pageId);
        }

        return $this->isoCodeByPage[$pageId][$languageUid] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getAvailableIsoCodes(): array
    {
        if (null !== $this->isoCodeCache) {
            return $this->isoCodeCache;
        }

        try {
            // Keys are the ISO codes; SiteService filters by the user's language access.
            $codes = array_keys($this->siteService->getAvailableLanguages());
        } catch (\Throwable $e) {
            // A broken site config must not take the tool schema down with it.
            $this->logger->warning('SiteLanguageService: could not resolve site languages, falling back to no enum', [
                'error' => $e->getMessage(),
            ]);
            $codes = [];
        }

        return $this->isoCodeCache = array_values(array_filter($codes, static fn (mixed $code): bool => is_string($code) && '' !== $code));
    }

    public function isSingleLanguageInstallation(): bool
    {
        return 1 === count($this->getAvailableIsoCodes());
    }

    /**
     * @param array<string, mixed> $property
     *
     * @return array<string, mixed>
     */
    public function withLanguageEnum(array $property): array
    {
        $codes = $this->getAvailableIsoCodes();

        if ([] === $codes) {
            return $property;
        }

        $property['enum'] = $codes;

        return $property;
    }

    /**
     * @return array<int, string>
     */
    private function loadLanguageMap(int $pageId): array
    {
        try {
            $map = [];
            foreach ($this->siteFinder->getSiteByPageId($pageId)->getAllLanguages() as $language) {
                $map[$language->getLanguageId()] = $language->getLocale()->getLanguageCode();
            }

            return $map;
        } catch (SiteNotFoundException $e) {
            $this->logger->info('SiteLanguageService: page belongs to no site, hit stays without an ISO code', [
                'pageId' => $pageId,
                'error' => $e->getMessage(),
            ]);

            return [];
        } catch (\Throwable $e) {
            $this->logger->warning('SiteLanguageService: resolving the site languages failed', [
                'pageId' => $pageId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
