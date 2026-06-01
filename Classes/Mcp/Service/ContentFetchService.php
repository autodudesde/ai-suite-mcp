<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuite\Service\BasicAuthService;
use AutoDudes\AiSuite\Service\SiteService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Session\UserSessionManager;

class ContentFetchService
{
    private const TEXT_FIELD_TYPES = ['text', 'input'];

    private const NUMERIC_EVAL_TYPES = ['int', 'num', 'double2', 'date', 'datetime', 'time', 'timesec', 'year'];

    private const FIELD_NAME_DENYLIST = [
        'slug',
        'path_segment',
        'space_before_class',
        'space_after_class',
        'frame_class',
        'header_position',
        'header_layout',
        'header_link',
        'image_zoom',
        'list_type',
    ];

    private const FIELD_NAME_SUFFIX_DENYLIST = [
        '_class',
        '_layout',
        '_url',
        '_target',
        '_icon',
        '_position',
        '_align',
        '_id',
        '_uid',
    ];

    private const MIN_TEXT_LENGTH = 3;

    public function __construct(
        private readonly McpUserContext $userContext,
        private readonly RequestFactory $requestFactory,
        private readonly PageRepository $pageRepository,
        private readonly ContentRepository $contentRepository,
        private readonly SiteService $siteService,
        private readonly BasicAuthService $basicAuthService,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly Context $context,
        private readonly LoggerInterface $logger,
    ) {}

    public function fetchPageContent(int $pageId, int $languageUid = 0): string
    {
        // Skip HTTP preview when in a workspace
        if (0 === $this->currentWorkspaceId()) {
            try {
                $html = $this->fetchViaPreviewUrl($pageId, $languageUid);
                if ('' !== $html) {
                    $text = $this->extractTextFromHtml($html);
                    if (mb_strlen($text) > 50) {
                        return $text;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('HTTP content fetch failed, using DB fallback', [
                    'pageId' => $pageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->fetchFromDatabase($pageId, $languageUid);
    }

    private function currentWorkspaceId(): int
    {
        try {
            return (int) $this->context->getPropertyFromAspect('workspace', 'id', 0);
        } catch (\Throwable $e) {
            $this->logger->warning('ContentFetchService: could not resolve workspace aspect, falling back to live workspace (0)', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    private function fetchViaPreviewUrl(int $pageId, int $languageUid): string
    {
        $previewUrl = $this->buildPreviewUrl($pageId, $languageUid);
        if ('' === $previewUrl) {
            return '';
        }

        $sessionManager = UserSessionManager::create('BE');
        $session = $sessionManager->elevateToFixatedUserSession(
            $sessionManager->createAnonymousSession(),
            $this->userContext->getBeUserUid(),
        );

        try {
            $options = [
                'headers' => [
                    'Cookie' => 'be_typo_user='.$session->getJwt(),
                ],
                'timeout' => 10,
                'allow_redirects' => ['max' => 3],
            ];

            $basicAuth = $this->basicAuthService->getBasicAuth();
            if ('' !== $basicAuth) {
                $options['headers']['Authorization'] = 'Basic '.$basicAuth;
            }

            $response = $this->requestFactory->request($previewUrl, 'GET', $options);

            return $response->getBody()->getContents();
        } finally {
            $sessionManager->removeSession($session);
        }
    }

    private function buildPreviewUrl(int $pageId, int $languageUid): string
    {
        try {
            $page = $this->pageRepository->getPage($pageId);
            if (($page['is_siteroot'] ?? 0) === 1 && ($page['l10n_parent'] ?? 0) > 0) {
                $pageId = (int) $page['l10n_parent'];
            }

            $additionalGetVars = '_language='.$languageUid;

            $previewUri = PreviewUriBuilder::create($pageId)
                ->withLanguage($languageUid)
                ->withAdditionalQueryParameters($additionalGetVars)
                ->buildUri()
            ;

            if (null === $previewUri) {
                return '';
            }

            return $this->siteService->buildAbsoluteUri($previewUri);
        } catch (\Throwable $e) {
            $this->logger->error('Could not build preview URL', [
                'pageId' => $pageId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function extractTextFromHtml(string $html): string
    {
        // Remove script, style, nav, header, footer elements
        $html = preg_replace('/<(script|style|nav|header|footer)\b[^>]*>.*?<\/\1>/si', '', $html) ?? $html;

        // Try to extract only the main content area
        if (preg_match('/<main\b[^>]*>(.*?)<\/main>/si', $html, $matches)) {
            $html = $matches[1];
        } elseif (preg_match('/<article\b[^>]*>(.*?)<\/article>/si', $html, $matches)) {
            $html = $matches[1];
        } elseif (preg_match('/<div[^>]*(?:id|class)\s*=\s*["\'][^"\']*(?:content|main)[^"\']*["\'][^>]*>(.*?)<\/div>/si', $html, $matches)) {
            $html = $matches[1];
        }

        // Strip remaining HTML tags
        $text = strip_tags($html);

        // Normalize whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function fetchFromDatabase(int $pageId, int $languageUid): string
    {
        $workspaceId = $this->currentWorkspaceId();

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (null === $page || [] === $page) {
            return '';
        }

        $parts = [];
        $parts[] = 'Page: '.($page['title'] ?? '');

        if (!empty($page['subtitle'])) {
            $parts[] = 'Subtitle: '.$page['subtitle'];
        }
        if (!empty($page['nav_title'])) {
            $parts[] = 'Navigation title: '.$page['nav_title'];
        }
        if (!empty($page['abstract'])) {
            $parts[] = 'Abstract: '.$page['abstract'];
        }
        if (!empty($page['description'])) {
            $parts[] = 'Existing meta description: '.$page['description'];
        }

        $rows = $this->contentRepository->findContentForExtraction($pageId, $languageUid, $workspaceId);

        foreach ($rows as $row) {
            $elementParts = $this->extractTextFieldsFromRow('tt_content', $row);

            if (!empty($row['pi_flexform'])) {
                $flexText = $this->extractFlexFormText($row['pi_flexform']);
                if ('' !== $flexText) {
                    $elementParts[] = $flexText;
                }
            }

            if (!empty($elementParts)) {
                $parts[] = implode("\n", $elementParts);
            }
        }

        $irreText = $this->fetchIrreChildContent($languageUid, $rows, $workspaceId);
        if ('' !== $irreText) {
            $parts[] = $irreText;
        }

        return implode("\n\n", $parts);
    }

    private function extractFlexFormText(string $flexFormXml): string
    {
        if ('' === trim($flexFormXml)) {
            return '';
        }

        try {
            $xml = @simplexml_load_string($flexFormXml);
            if (false === $xml) {
                return '';
            }

            $texts = [];
            // Find all <value> nodes that contain text
            foreach ($xml->xpath('//value') ?? [] as $value) {
                $text = trim(strip_tags((string) $value));
                if (mb_strlen($text) > 10) {
                    $texts[] = $text;
                }
            }

            return implode("\n", $texts);
        } catch (\Throwable $e) {
            $this->logger->warning('ContentFetchService: could not parse FlexForm XML for text extraction', [
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * @param array<int, array<string, mixed>> $parentRows
     */
    private function fetchIrreChildContent(int $languageUid, array $parentRows, int $workspaceId): string
    {
        if (empty($parentRows)) {
            return '';
        }

        if (!$this->tcaCompatibilityService->hasField('tt_content', 'tx_container_parent')) {
            return '';
        }

        $parentUids = array_map('intval', array_column($parentRows, 'uid'));
        $children = $this->contentRepository->findContainerChildrenByParents($parentUids, $languageUid, $workspaceId);

        $parts = [];
        foreach ($children as $child) {
            $childParts = $this->extractTextFieldsFromRow('tt_content', $child);
            if (!empty($childParts)) {
                $parts[] = implode("\n", $childParts);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function extractTextFieldsFromRow(string $table, array $row): array
    {
        try {
            $typeKey = null;
            $typeFieldName = $this->tcaCompatibilityService->getSubSchemaDivisorFieldName($table);
            if (null !== $typeFieldName && !empty($row[$typeFieldName])) {
                $typeKey = (string) $row[$typeFieldName];
            }

            $fieldNames = $this->tcaCompatibilityService->getFieldNamesForType($table, $typeKey);
        } catch (\Throwable $e) {
            $this->logger->warning('ContentFetchService: TCA field walk failed, skipping row', [
                'table' => $table,
                'uid' => $row['uid'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $parts = [];
        foreach ($fieldNames as $fieldName) {
            if (!array_key_exists($fieldName, $row) || '' === (string) $row[$fieldName] || null === $row[$fieldName]) {
                continue;
            }
            if ($this->isDenylistedField($fieldName)) {
                continue;
            }

            try {
                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $fieldName);
            } catch (\Throwable) {
                continue;
            }

            $type = (string) ($config['type'] ?? '');
            if (!in_array($type, self::TEXT_FIELD_TYPES, true)) {
                continue;
            }

            if ('input' === $type) {
                $eval = (string) ($config['eval'] ?? '');
                if ('' !== $eval) {
                    $evalParts = array_map('trim', explode(',', $eval));
                    if ([] !== array_intersect($evalParts, self::NUMERIC_EVAL_TYPES)) {
                        continue;
                    }
                }
            }

            $value = (string) $row[$fieldName];
            if ($this->tcaCompatibilityService->isRichTextFieldConfig($config) || str_contains($value, '<')) {
                $value = strip_tags($value);
            }
            $value = trim($value);

            if (mb_strlen($value) < self::MIN_TEXT_LENGTH) {
                continue;
            }

            $parts[] = $value;
        }

        return $parts;
    }

    private function isDenylistedField(string $fieldName): bool
    {
        if (in_array($fieldName, self::FIELD_NAME_DENYLIST, true)) {
            return true;
        }
        foreach (self::FIELD_NAME_SUFFIX_DENYLIST as $suffix) {
            if (str_ends_with($fieldName, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
