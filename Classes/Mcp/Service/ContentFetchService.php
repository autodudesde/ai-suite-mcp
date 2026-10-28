<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Domain\Repository\ContentRepository;
use AutoDudes\AiSuite\Service\BasicAuthService;
use AutoDudes\AiSuite\Service\MetadataService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\FetchedPageContent;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
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

    private const INLINE_DEPTH = 2;

    private const INLINE_CHILDREN_PER_FIELD = 100;

    public function __construct(
        private readonly McpUserContext $userContext,
        private readonly RequestFactory $requestFactory,
        private readonly ContentRepository $contentRepository,
        private readonly MetadataService $metadataService,
        private readonly BasicAuthService $basicAuthService,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly RecordRepository $recordRepository,
        private readonly WorkspaceRecordService $workspaceRecords,
        private readonly RenderedHtmlTextExtractor $htmlTextExtractor,
        private readonly RecordAccessService $recordAccess,
        private readonly LoggerInterface $logger,
    ) {}

    public function fetchPageContent(int $pageId, int $languageUid = 0): string
    {
        return $this->fetch($pageId, $languageUid)->text;
    }

    public function fetch(int $pageId, int $languageUid = 0): FetchedPageContent
    {
        if (0 === $this->workspaceContextService->getWorkspaceId()) {
            try {
                $html = $this->fetchViaPreviewUrl($pageId, $languageUid);
                if ('' !== $html) {
                    $text = $this->htmlTextExtractor->extract($html);
                    if (mb_strlen($text) > 50) {
                        return new FetchedPageContent($text, true);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('HTTP content fetch failed, using DB fallback', [
                    'pageId' => $pageId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return new FetchedPageContent($this->fetchFromDatabase($pageId, $languageUid), false);
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
            return $this->metadataService->getPreviewUrl($pageId, [], $languageUid);
        } catch (\Throwable $e) {
            $this->logger->error('Could not build preview URL', [
                'pageId' => $pageId,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }

    private function fetchFromDatabase(int $pageId, int $languageUid): string
    {
        $workspaceId = $this->workspaceContextService->getWorkspaceId();

        $page = BackendUtility::getRecordWSOL('pages', $pageId);
        if (null === $page || [] === $page) {
            return '';
        }
        $page = $this->pageTranslation($pageId, $languageUid) ?? $page;

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

        $rows = $this->recordAccess->hasTableReadAccess('tt_content')
            ? $this->contentRepository->findContentForExtraction($pageId, $languageUid, $workspaceId)
            : [];

        foreach ($rows as $row) {
            $elementParts = $this->extractTextFieldsFromRow('tt_content', $row);

            if (!empty($row['pi_flexform'])) {
                $flexText = $this->extractFlexFormText($row['pi_flexform']);
                if ('' !== $flexText) {
                    $elementParts[] = $flexText;
                }
            }
            array_push($elementParts, ...$this->extractInlineChildText('tt_content', $row, self::INLINE_DEPTH));

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

        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($flexFormXml);
            if (false === $xml) {
                return '';
            }

            $texts = [];
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
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
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
            $childParts = [
                ...$this->extractTextFieldsFromRow('tt_content', $child),
                ...$this->extractInlineChildText('tt_content', $child, self::INLINE_DEPTH),
            ];
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

    /**
     * @return null|array<string, mixed>
     */
    private function pageTranslation(int $pageId, int $languageUid): ?array
    {
        if ($languageUid <= 0) {
            return null;
        }

        try {
            $pointerField = $this->tcaCompatibilityService->getTranslationOriginPointerFieldName('pages');
            $languageField = $this->tcaCompatibilityService->getLanguageFieldName('pages');
        } catch (\Throwable) {
            return null;
        }
        if (null === $pointerField || null === $languageField) {
            return null;
        }

        $translationUid = $this->recordRepository->findTranslationUid('pages', $pageId, $languageUid, $pointerField, $languageField);
        if (null === $translationUid) {
            return null;
        }

        $translation = BackendUtility::getRecordWSOL('pages', $translationUid);

        return is_array($translation) && [] !== $translation ? $translation : null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function extractInlineChildText(string $table, array $row, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        try {
            $typeKey = $this->tcaCompatibilityService->resolveSubSchemaType($table, $row);
            $fieldNames = $this->tcaCompatibilityService->getFieldNamesForType($table, $typeKey);
        } catch (\Throwable) {
            return [];
        }

        $targets = $this->workspaceRecords->relationTargets($table, (int) ($row['uid'] ?? 0));
        if ([] === $targets) {
            return [];
        }

        $parts = [];
        foreach ($fieldNames as $fieldName) {
            try {
                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $fieldName);
            } catch (\Throwable) {
                continue;
            }

            $childTable = (string) ($config['foreign_table'] ?? '');
            $pointerField = (string) ($config['foreign_field'] ?? '');
            if ('inline' !== ($config['type'] ?? '') || '' === $childTable || '' === $pointerField || 'sys_file_reference' === $childTable
                || !$this->recordAccess->hasTableReadAccess($childTable)
            ) {
                continue;
            }
            $tableField = (string) ($config['foreign_table_field'] ?? '');

            $children = [];
            $uids = $this->recordRepository->findUidsByRelation($childTable, $pointerField, $targets, self::INLINE_CHILDREN_PER_FIELD);
            foreach ($this->workspaceRecords->overlay($childTable, $uids) as $child) {
                if (!in_array((int) ($child[$pointerField] ?? 0), $targets, true)
                    || ('' !== $tableField && $table !== (string) ($child[$tableField] ?? ''))
                    || $this->isDisabled($childTable, $child)
                ) {
                    continue;
                }
                $children[] = $child;
            }

            $sortField = $this->workspaceRecords->inlineSortField($childTable, $config);
            foreach ($this->workspaceRecords->sortRows($children, [$sortField]) as $child) {
                array_push(
                    $parts,
                    ...$this->extractTextFieldsFromRow($childTable, $child),
                    ...$this->extractInlineChildText($childTable, $child, $depth - 1),
                );
            }
        }

        return $parts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isDisabled(string $table, array $row): bool
    {
        try {
            $disabledField = $this->tcaCompatibilityService->getDisabledFieldName($table);
        } catch (\Throwable) {
            return false;
        }

        return null !== $disabledField && !empty($row[$disabledField]);
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
