<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Enum\LinkStyle;

/**
 * @phpstan-type NavigationTarget array{label: string, url: string}
 * @phpstan-type NavigationGroup array{table: string, label: string, targets: list<NavigationTarget>, omitted: int}
 */
class NavigationTargetCollector
{
    public const MAX_TARGETS_PER_GROUP = 6;

    public function __construct(
        private readonly BackendNavigationResolver $resolver,
        private readonly RecordLabelService $recordLabelService,
        private readonly TcaLabelService $tcaLabelService,
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
                if (\is_array($record)) {
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
                $this->addTarget($groups[$table], $target['url'], $target['label']);
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
    private function addTarget(array &$group, ?string $url, string $label): void
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
        $group['targets'][] = ['label' => $label, 'url' => $url];
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
