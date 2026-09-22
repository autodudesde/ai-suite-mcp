<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Http;

use AutoDudes\AiSuiteMcp\Mcp\Service\BackendLinkBounceService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Localization\Locales;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class BackendLinkEndpoint
{
    public const PATH = '/aisuite-mcp/open';
    public const SCRIPT_PATH = '/aisuite-mcp/open.js';

    private const LABELS = 'LLL:EXT:ai_suite_mcp/Resources/Private/Language/locallang_mcp.xlf:aiSuite.mcp.backendLink.';
    private const SCRIPT_FILE = 'Resources/Private/JavaScript/backend-link-bounce.js';

    private const PAGE_HEADERS = [
        'Cache-Control' => 'no-store',
        'Referrer-Policy' => 'same-origin',
        'X-Robots-Tag' => 'noindex, nofollow',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; script-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
    ];

    public function __construct(
        private readonly BackendLinkBounceService $bounceService,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly Locales $locales,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        if (!\in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return new Response('php://temp', 405, ['Allow' => 'GET, HEAD']);
        }

        if (self::SCRIPT_PATH === $request->getUri()->getPath()) {
            return $this->script();
        }

        $query = $request->getQueryParams();
        $target = $this->bounceService->resolveTarget(
            \is_string($query['target'] ?? null) ? $query['target'] : '',
            \is_string($query['signature'] ?? null) ? $query['signature'] : '',
        );

        $language = $this->locales->getPreferredClientLanguage($request->getHeaderLine('Accept-Language'));
        $languageService = $this->languageServiceFactory->create($language);

        if (null === $target) {
            $this->logger->warning('MCP: backend link refused, the signature does not match the target');

            return $this->page(403, $language, $languageService, sprintf(
                '<p>%s</p>',
                htmlspecialchars($languageService->sL(self::LABELS.'invalid')),
            ));
        }

        return $this->page(200, $language, $languageService, sprintf(
            '<p><a id="aisuite-backend-link" href="%s">%s</a></p><script src="%s"></script>',
            htmlspecialchars($target),
            htmlspecialchars($languageService->sL(self::LABELS.'continue')),
            self::SCRIPT_PATH,
        ));
    }

    private function page(int $status, string $language, LanguageService $languageService, string $body): ResponseInterface
    {
        return new HtmlResponse(sprintf(
            '<!DOCTYPE html><html lang="%s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>%s</title></head><body>%s</body></html>',
            htmlspecialchars('default' === $language ? 'en' : $language),
            htmlspecialchars($languageService->sL(self::LABELS.'title')),
            $body,
        ), $status, self::PAGE_HEADERS);
    }

    private function script(): ResponseInterface
    {
        $response = new Response('php://temp', 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->getBody()->write((string) file_get_contents(
            ExtensionManagementUtility::extPath('ai_suite_mcp', self::SCRIPT_FILE),
        ));

        return $response;
    }
}
