<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use B13\Container\Tca\Registry;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class TcaLabelService
{
    /**
     * @var null|array<string, string>
     */
    private ?array $cTypeLabelMap = null;

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LocalizationService $localizationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * TYPO3 v14 short-form label references (Breaking #107789) carry no `LLL:` prefix: the core TCA
     * now labels tt_content.colPos as `frontend.db.tt_content:column`. A prefix-only check lets those
     * through untranslated, and the raw key ends up in front of an editor. LanguageService::sL()
     * understands both forms, so route both at it.
     */
    public function resolveLabel(string $label): string
    {
        if ('' === $label) {
            return '';
        }
        if (str_starts_with($label, 'LLL:') || $this->isShortFormReference($label)) {
            return $this->localizationService->getLanguageService()->sL($label) ?: $label;
        }

        return $label;
    }

    public function getTableLabel(string $table): string
    {
        try {
            return $this->resolveLabel($this->tcaCompatibilityService->getTitle($table));
        } catch (\Throwable $e) {
            $this->logger->warning('TcaLabelService::getTableLabel: TCA title lookup failed, falling back to raw table name', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return $table;
        }
    }

    public function getFieldLabel(string $table, string $field): string
    {
        try {
            return $this->resolveLabel($this->tcaCompatibilityService->getFieldLabel($table, $field));
        } catch (\Throwable $e) {
            $this->logger->warning('TcaLabelService::getFieldLabel: TCA field-label lookup failed, falling back to raw field name', [
                'table' => $table,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }

        return $field;
    }

    public function resolveCTypeLabel(string $cType, string $table = 'tt_content', string $typeField = 'CType'): string
    {
        if ('' === $cType) {
            return $cType;
        }

        $label = $this->getTypeItemLabel($table, $typeField, $cType);

        return '' !== $label && $label !== $cType
            ? sprintf('%s (`%s`)', $label, $cType)
            : sprintf('`%s`', $cType);
    }

    public function getTypeItemLabel(string $table, string $field, string $value): string
    {
        if ('tt_content' === $table && 'CType' === $field) {
            if (null === $this->cTypeLabelMap) {
                $this->cTypeLabelMap = $this->buildTypeItemMap($table, $field);
            }

            return $this->cTypeLabelMap[$value] ?? $value;
        }

        return $this->buildTypeItemMap($table, $field)[$value] ?? $value;
    }

    /**
     * @param array<int|string, mixed> $items
     *
     * @return list<array{value: string, label: string}>
     */
    public function buildSelectOptions(array $items): array
    {
        $options = [];
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['value']) || '--div--' === $item['value']) {
                continue;
            }
            $options[] = [
                'value' => (string) $item['value'],
                'label' => $this->resolveLabel((string) ($item['label'] ?? (string) $item['value'])),
            ];
        }

        return $options;
    }

    public function getContainerRegistry(): ?Registry
    {
        if (!ExtensionManagementUtility::isLoaded('container')) {
            return null;
        }
        if (!class_exists(Registry::class)) {
            return null;
        }

        return GeneralUtility::makeInstance(Registry::class);
    }

    public function resolveContainerColumnLabel(int $colPos): ?string
    {
        $registry = $this->getContainerRegistry();
        if (null === $registry) {
            return null;
        }

        try {
            foreach ($registry->getRegisteredCTypes() as $cType) {
                foreach ($registry->getAvailableColumns($cType) as $col) {
                    if ((int) ($col['colPos'] ?? -1) === $colPos) {
                        $name = $this->resolveLabel((string) ($col['name'] ?? ''));
                        if ('' !== $name) {
                            return $name;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TcaLabelService::resolveContainerColumnLabel: container registry scan failed', [
                'colPos' => $colPos,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * `<alias>:<key>` with no whitespace. Deliberately strict: a literal label may well contain a
     * colon ("Price: net"), and handing that to sL() would be a lookup for something that is already
     * the answer.
     */
    private function isShortFormReference(string $label): bool
    {
        return 1 === preg_match('/^[a-z0-9_]+(?:\.[a-z0-9_]+)*:[A-Za-z0-9_.\-]+$/', $label);
    }

    /**
     * @return array<string, string>
     */
    private function buildTypeItemMap(string $table, string $field): array
    {
        $map = [];

        try {
            $items = $this->tcaCompatibilityService->getFieldConfiguration($table, $field)['items'] ?? [];
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['value']) || '--div--' === $item['value'] || '' === $item['value']) {
                    continue;
                }
                $map[(string) $item['value']] = $this->resolveLabel((string) ($item['label'] ?? $item['value']));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('TcaLabelService::buildTypeItemMap: TCA items lookup failed, falling back to raw values', [
                'table' => $table,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }

        return $map;
    }
}
