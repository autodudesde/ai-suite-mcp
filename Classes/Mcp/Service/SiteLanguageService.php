<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\SiteService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * The ISO language codes this installation actually has, for tool schemas and the tools/list filter.
 *
 * Without it every language parameter is a free string ("ISO target language code (de, en, fr, es,
 * ...)"), so the model can ask for `fr` on a site that only has `de` and `en` and only finds out
 * after the call. An enum moves that from a failed round-trip to something the model cannot express.
 *
 * Deliberately a union across *all* sites, not the current one: tools/list has no page context, so
 * there is no site to scope to. On a multi-site install this over-includes — `fr` may be valid for
 * one site and not another — but the per-call check (`RecordAccessService::resolveLanguageUid()` plus
 * `assertLanguageAccess()`) still runs and is the authority. Over-including keeps a working call
 * working; under-including would make a legitimate one unexpressible.
 */
final class SiteLanguageService implements SingletonInterface
{
    /**
     * Memoised because getSchema() runs on every tools/list and would otherwise re-walk every site.
     *
     * Per-instance, so it assumes the FPM lifecycle of one request per process — the result depends
     * on the backend user, so a persistent worker (FrankenPHP, RoadRunner) would serve one user's
     * language set to the next. Same caveat as AbstractTool::$readablePageIdsCache; if the deployment
     * ever moves, both move to McpUserContext together.
     *
     * @var null|list<string>
     */
    private ?array $isoCodeCache = null;

    public function __construct(
        private readonly SiteService $siteService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return list<string> ISO codes across every site the backend user may use, e.g. ['en', 'de']
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

    /**
     * Whether translation tooling is pointless here because there is only one language to begin with.
     *
     * Fails **open**: an empty list means "could not tell" (no backend user, no site configured, site
     * config broken), not "one language". Wrongly hiding the translation tools is a silent failure a
     * user cannot diagnose — the tools simply are not there — while wrongly showing them costs a few
     * tokens and one honest error message.
     */
    public function isSingleLanguageInstallation(): bool
    {
        return 1 === count($this->getAvailableIsoCodes());
    }

    /**
     * Add an `enum` to a language parameter, but only when the codes are actually known.
     *
     * An empty enum is not "no constraint", it is "no value is valid" — every provider would reject
     * every call. When the list cannot be resolved the parameter stays a free string and the server
     * side rejects a bad code, exactly as it does today.
     *
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
}
