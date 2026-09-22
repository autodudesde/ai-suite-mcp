<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuite\Service\TranslationService;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

class TranslatedPageSlugService
{
    public function __construct(
        private readonly TranslationService $translationService,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array-key, mixed> $writtenFields
     */
    public function refreshInheritedSlug(int $pageUid, array $writtenFields): bool
    {
        $writtenFieldNames = array_map('strval', array_keys($writtenFields));
        if (in_array('slug', $writtenFieldNames, true) || [] === array_intersect($this->generatorFields(), $writtenFieldNames)) {
            return false;
        }

        $languageField = $this->tcaCompatibilityService->getLanguageFieldName('pages');
        $pointerField = $this->tcaCompatibilityService->getTranslationOriginPointerFieldName('pages');
        $page = BackendUtility::getRecordWSOL('pages', $pageUid);
        if (null === $languageField || null === $pointerField || !is_array($page) || (int) ($page[$languageField] ?? 0) <= 0) {
            return false;
        }

        $origin = BackendUtility::getRecordWSOL('pages', (int) ($page[$pointerField] ?? 0));
        $slug = (string) ($page['slug'] ?? '');
        if (!is_array($origin) || '/' === $slug || $slug !== (string) ($origin['slug'] ?? '')) {
            return false;
        }

        try {
            $this->translationService->updatePageSlug($pageUid);
        } catch (\Throwable $e) {
            $this->logger->warning('TranslatedPageSlug: the inherited slug could not be rebuilt', [
                'pageUid' => $pageUid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function generatorFields(): array
    {
        $configured = $this->tcaCompatibilityService->getSlugFieldConfig()['generatorOptions']['fields'] ?? [];
        $fields = [];
        if (!is_array($configured)) {
            return $fields;
        }

        array_walk_recursive($configured, static function (mixed $field) use (&$fields): void {
            if (is_string($field) && '' !== $field) {
                $fields[] = $field;
            }
        });

        return $fields;
    }
}
