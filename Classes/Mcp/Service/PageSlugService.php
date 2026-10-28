<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class PageSlugService
{
    private const PREFIX_PROBE = 'mcp-slug-prefix-probe';

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly RecordRepository $recordRepository,
    ) {}

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    public function nestSuppliedSlug(array $fields, int $parentPid): array
    {
        $slug = trim((string) ($fields['slug'] ?? ''));
        if ('' === $slug || '/' === $slug) {
            return $fields;
        }

        $languageField = $this->tcaCompatibilityService->getLanguageFieldName('pages');
        $languageId = null !== $languageField ? (int) ($fields[$languageField] ?? 0) : 0;
        $prefix = $this->parentPrefix($parentPid, $languageId);
        if ('' === $prefix || $slug === $prefix || str_starts_with($slug, $prefix.'/')) {
            return $fields;
        }

        $fields['slug'] = $prefix.'/'.ltrim($slug, '/');

        return $fields;
    }

    /**
     * @return array{pid: int, slugs: array<int, array{uid: int, slug: string, row: array<string, mixed>}>}
     */
    public function snapshotBeforeMove(int $pageUid): array
    {
        $page = BackendUtility::getRecordWSOL('pages', $pageUid);
        if (!is_array($page)) {
            return ['pid' => 0, 'slugs' => []];
        }

        $languageField = $this->tcaCompatibilityService->getLanguageFieldName('pages');
        $helper = $this->slugHelper();
        $slugs = [];
        foreach (array_merge([$page], $this->translationsOf($pageUid)) as $row) {
            $slug = (string) ($row['slug'] ?? '');
            if ('' !== $slug && '/' !== $slug && $slug === $helper->generate($row, (int) $row['pid'])) {
                $languageId = null !== $languageField ? (int) ($row[$languageField] ?? 0) : 0;
                $slugs[$languageId] = ['uid' => (int) $row['uid'], 'slug' => $slug, 'row' => $row];
            }
        }

        return ['pid' => (int) $page['pid'], 'slugs' => $slugs];
    }

    /**
     * @param array{pid: int, slugs: array<int, array{uid: int, slug: string, row: array<string, mixed>}>} $snapshot
     *
     * @return array{slug: ?string, subpages: int}
     */
    public function followMove(int $pageUid, array $snapshot): array
    {
        $page = BackendUtility::getRecordWSOL('pages', $pageUid);
        $newPid = is_array($page) ? (int) $page['pid'] : $snapshot['pid'];
        if ([] === $snapshot['slugs'] || $newPid === $snapshot['pid']) {
            return ['slug' => null, 'subpages' => 0];
        }

        $helper = $this->slugHelper();
        $datamap = [];
        foreach ($snapshot['slugs'] as $entry) {
            $datamap[$entry['uid']] = ['slug' => $helper->generate(['pid' => $newPid] + $entry['row'], $newPid)];
        }
        $this->writeSlugs($datamap);

        $renamed = [];
        foreach ($snapshot['slugs'] as $languageId => $entry) {
            $current = BackendUtility::getRecordWSOL('pages', $entry['uid'], 'slug');
            $newSlug = (string) ($current['slug'] ?? '');
            if ('' !== $newSlug && $newSlug !== $entry['slug']) {
                $renamed[$languageId] = ['old' => $entry['slug'], 'new' => $newSlug];
            }
        }

        return [
            'slug' => $renamed[0]['new'] ?? null,
            'subpages' => $this->rewriteSubpageSlugs($pageUid, $renamed),
        ];
    }

    /**
     * @param array<int, array{old: string, new: string}> $renamed
     */
    private function rewriteSubpageSlugs(int $pageUid, array $renamed): int
    {
        if ([] === $renamed) {
            return 0;
        }

        $languageField = $this->tcaCompatibilityService->getLanguageFieldName('pages');
        $datamap = [];
        foreach ($this->subtreeRows($pageUid) as $row) {
            $languageId = null !== $languageField ? (int) ($row[$languageField] ?? 0) : 0;
            $slug = (string) ($row['slug'] ?? '');
            $old = $renamed[$languageId]['old'] ?? null;
            if (null !== $old && str_starts_with($slug, $old.'/')) {
                $datamap[(int) $row['uid']] = ['slug' => $renamed[$languageId]['new'].substr($slug, strlen($old))];
            }
        }
        $this->writeSlugs($datamap);

        return count($datamap);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function subtreeRows(int $pageUid): array
    {
        $pointerField = $this->tcaCompatibilityService->getTranslationOriginPointerFieldName('pages');
        $rows = [];
        $parents = [$pageUid];
        while ([] !== $parents) {
            $children = $this->overlaidPages('pid', $parents);
            $parents = [];
            foreach ($children as $child) {
                $rows[] = $child;
                if (null === $pointerField || 0 === (int) ($child[$pointerField] ?? 0)) {
                    $parents[] = (int) $child['uid'];
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function translationsOf(int $pageUid): array
    {
        $pointerField = $this->tcaCompatibilityService->getTranslationOriginPointerFieldName('pages');

        return null === $pointerField ? [] : $this->overlaidPages($pointerField, [$pageUid]);
    }

    /**
     * @param list<int> $values
     *
     * @return list<array<string, mixed>>
     */
    private function overlaidPages(string $field, array $values): array
    {
        $workspaceId = $this->workspaceContextService->getWorkspaceId();

        $rows = [];
        foreach ($this->recordRepository->findPagesByFieldValues($field, $values) as $row) {
            BackendUtility::workspaceOL('pages', $row, $workspaceId);
            if (is_array($row) && !$this->tcaCompatibilityService->isDeletePlaceholderState($row['t3ver_state'] ?? 0)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array{slug: string}> $datamap
     */
    private function writeSlugs(array $datamap): void
    {
        if ([] === $datamap) {
            return;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => $datamap], []);
        $dataHandler->process_datamap();
    }

    private function parentPrefix(int $parentPid, int $languageId): string
    {
        $generatorField = $this->firstGeneratorField();
        $languageField = $this->tcaCompatibilityService->getLanguageFieldName('pages');
        if (null === $generatorField || $parentPid <= 0) {
            return '';
        }

        $record = [$generatorField => self::PREFIX_PROBE];
        if (null !== $languageField) {
            $record[$languageField] = $languageId;
        }
        $probe = $this->slugHelper()->generate($record, $parentPid);
        $position = strrpos($probe, '/'.self::PREFIX_PROBE);

        return false === $position ? '' : substr($probe, 0, $position);
    }

    private function firstGeneratorField(): ?string
    {
        $configured = $this->tcaCompatibilityService->getSlugFieldConfig()['generatorOptions']['fields'] ?? [];
        $first = is_array($configured) ? reset($configured) : null;
        if (is_array($first)) {
            $first = reset($first);
        }
        if (is_string($first)) {
            $first = GeneralUtility::trimExplode(',', $first, true)[0] ?? null;
        }

        return is_string($first) && '' !== $first ? $first : null;
    }

    private function slugHelper(): SlugHelper
    {
        return GeneralUtility::makeInstance(
            SlugHelper::class,
            'pages',
            'slug',
            $this->tcaCompatibilityService->getSlugFieldConfig(),
            $this->workspaceContextService->getWorkspaceId(),
        );
    }
}
