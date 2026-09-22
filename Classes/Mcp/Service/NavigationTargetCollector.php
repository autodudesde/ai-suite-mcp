<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\ProvenanceStructureService;
use AutoDudes\AiSuiteMcp\Mcp\Enum\LinkStyle;

/**
 * @phpstan-type NavigationTarget array{label: string, url: string, download?: bool}
 * @phpstan-type NavigationGroup array{table: string, label: string, targets: list<NavigationTarget>, omitted: int}
 */
class NavigationTargetCollector
{
    public const MAX_TARGETS_PER_GROUP = 6;

    public const AUDIT_GROUP = 'audit';

    /** @var list<string> */
    public const EXPORTABLE_AUDIT_TYPES = ['seo', 'a11y'];

    public function __construct(
        private readonly BackendNavigationResolver $resolver,
        private readonly RecordLabelService $recordLabelService,
        private readonly TcaLabelService $tcaLabelService,
        private readonly ProvenanceStructureService $structureService,
        private readonly LocalizationService $localizationService,
    ) {}

    /**
     * @param array<string, mixed> $structured
     *
     * @return list<NavigationGroup>
     */
    public function collect(array $structured, LinkStyle $style = LinkStyle::Session): array
    {
        $groups = [];

        $batch = $structured['batch'] ?? null;
        if (\is_array($batch) && \is_array($batch['records'] ?? null)) {
            foreach ($batch['records'] as $record) {
                if (\is_array($record) && 'delete' !== ($record['action'] ?? null)) {
                    $this->addRecord($groups, (string) ($record['table'] ?? ''), (int) ($record['uid'] ?? 0), $style);
                }
            }
        }

        $translation = $structured['translation'] ?? null;
        if (\is_array($translation)) {
            if (isset($translation['pageId'])) {
                $this->addPage($groups, (int) $translation['pageId'], $style);
            } else {
                $this->addRecord($groups, (string) ($translation['table'] ?? ''), (int) ($translation['uid'] ?? 0), $style);
            }
        }

        if (\is_array($structured['pages'] ?? null)) {
            $this->addPageTree($groups, $structured['pages'], $style);
        }

        if (\is_array($structured['auditStored'] ?? null)) {
            $this->addAudit($groups, $structured['auditStored'], $style);
        }

        return $this->sorted($groups);
    }

    /**
     * @param array<string, mixed> $structured
     *
     * @return list<NavigationGroup>
     */
    public function collectFound(array $structured, LinkStyle $style = LinkStyle::Session): array
    {
        $groups = [];
        if (\is_array($structured['found'] ?? null)) {
            foreach ($structured['found'] as $record) {
                if (\is_array($record)) {
                    $this->addRecord($groups, (string) ($record['table'] ?? ''), (int) ($record['uid'] ?? 0), $style);
                }
            }
        }

        return $this->sorted($groups);
    }

    /**
     * @param list<NavigationGroup> $existing
     * @param list<NavigationGroup> $additional
     *
     * @return list<NavigationGroup>
     */
    public function merge(array $existing, array $additional): array
    {
        $groups = [];
        foreach ($existing as $group) {
            $groups[$group['table']] = $group;
        }

        foreach ($additional as $group) {
            $table = $group['table'];
            if (!isset($groups[$table])) {
                $groups[$table] = $group;

                continue;
            }

            $groups[$table]['omitted'] += $group['omitted'];
            foreach ($group['targets'] as $target) {
                $this->addTarget($groups[$table], $target['url'], $target['label'], $target['download'] ?? false);
            }
        }

        return $this->sorted($groups);
    }

    /**
     * @param array<string, NavigationGroup> $groups
     * @param list<mixed>                    $pages
     */
    private function addPageTree(array &$groups, array $pages, LinkStyle $style): void
    {
        foreach ($pages as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $title = trim((string) ($page['title'] ?? ''));
            $this->addPage($groups, (int) ($page['uid'] ?? 0), $style, '' !== $title ? $title : null);
            if (\is_array($page['children'] ?? null)) {
                $this->addPageTree($groups, $page['children'], $style);
            }
        }
    }

    /**
     * @param array<string, NavigationGroup> $groups
     */
    private function addRecord(array &$groups, string $table, int $uid, LinkStyle $style): void
    {
        if ('' === $table || $uid <= 0) {
            return;
        }
        if (ProvenanceStructureService::AREA_NONE === $this->structureService->areaOf($table)) {
            $owner = $this->structureService->listableRecordFor($table, $uid);
            if (ProvenanceStructureService::AREA_NONE === $this->structureService->areaOf($owner['table'])) {
                return;
            }
            $table = $owner['table'];
            $uid = $owner['uid'];
        }
        if ('pages' === $table) {
            $this->addPage($groups, $uid, $style);

            return;
        }

        $group = &$this->group($groups, $table);
        if ($this->isFull($group)) {
            return;
        }

        $this->addTarget(
            $group,
            $this->resolver->buildUrl('editRecord', ['table' => $table, 'uid' => $uid], $style),
            $this->recordLabelService->describe($table, $uid),
        );
    }

    /**
     * @param array<string, NavigationGroup> $groups
     * @param array<mixed>                   $stored
     */
    private function addAudit(array &$groups, array $stored, LinkStyle $style): void
    {
        if (true !== ($stored['viewable'] ?? false)) {
            return;
        }
        $params = [
            'pageId' => (int) ($stored['pageId'] ?? 0),
            'auditType' => (string) ($stored['auditType'] ?? ''),
            'languageUid' => (int) ($stored['languageUid'] ?? 0),
        ];

        $groups[self::AUDIT_GROUP] ??= [
            'table' => self::AUDIT_GROUP,
            'label' => $this->localizationService->translate('mcp:navigation.audit.group'),
            'targets' => [],
            'omitted' => 0,
        ];
        $this->addTarget(
            $groups[self::AUDIT_GROUP],
            $this->resolver->buildUrl('openAuditResult', $params, $style),
            $this->localizationService->translate('mcp:navigation.audit.open'),
        );
        if (\in_array($params['auditType'], self::EXPORTABLE_AUDIT_TYPES, true)) {
            $this->addTarget(
                $groups[self::AUDIT_GROUP],
                $this->resolver->buildUrl('exportAudit', $params, $style),
                $this->localizationService->translate('mcp:navigation.audit.export'),
                true,
            );
        }
    }

    /**
     * @param array<string, NavigationGroup> $groups
     */
    private function addPage(array &$groups, int $pageId, LinkStyle $style, ?string $title = null): void
    {
        if ($pageId <= 0) {
            return;
        }

        $group = &$this->group($groups, 'pages');
        if ($this->isFull($group)) {
            return;
        }

        $this->addTarget(
            $group,
            $this->resolver->buildUrl('openPage', ['pageId' => $pageId], $style),
            $title ?? $this->recordLabelService->describe('pages', $pageId),
        );
    }

    /**
     * @param array<string, NavigationGroup> $groups
     *
     * @return NavigationGroup
     */
    private function &group(array &$groups, string $table): array
    {
        $groups[$table] ??= [
            'table' => $table,
            'label' => $this->tcaLabelService->getTableLabel($table),
            'targets' => [],
            'omitted' => 0,
        ];

        return $groups[$table];
    }

    /**
     * @param NavigationGroup $group
     */
    private function isFull(array &$group): bool
    {
        if (\count($group['targets']) < self::MAX_TARGETS_PER_GROUP) {
            return false;
        }
        ++$group['omitted'];

        return true;
    }

    /**
     * @param NavigationGroup $group
     */
    private function addTarget(array &$group, ?string $url, string $label, bool $download = false): void
    {
        if (null === $url || '' === $url) {
            return;
        }
        foreach ($group['targets'] as $existing) {
            if ($existing['url'] === $url) {
                return;
            }
        }
        if (\count($group['targets']) >= self::MAX_TARGETS_PER_GROUP) {
            ++$group['omitted'];

            return;
        }
        $group['targets'][] = $download ? ['label' => $label, 'url' => $url, 'download' => true] : ['label' => $label, 'url' => $url];
    }

    /**
     * @param array<string, NavigationGroup> $groups
     *
     * @return list<NavigationGroup>
     */
    private function sorted(array $groups): array
    {
        $pages = $groups['pages'] ?? null;
        unset($groups['pages']);

        $sorted = null !== $pages ? [$pages] : [];
        foreach ($groups as $group) {
            $sorted[] = $group;
        }

        return array_values(array_filter($sorted, static fn (array $group): bool => [] !== $group['targets']));
    }
}
