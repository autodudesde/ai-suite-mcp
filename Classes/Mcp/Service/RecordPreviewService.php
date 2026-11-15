<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RecordPreviewService
{
    private const DEFAULT_MAX_VALUE_LENGTH = 300;

    private const MAX_TITLE_LENGTH = 70;

    private const CHECKBOX_STATES = [
        'LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:no',
        'LLL:EXT:core/Resources/Private/Language/locallang_common.xlf:yes',
    ];

    private const ALL_LANGUAGES = 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.allLanguages';

    private const DEFAULT_LANGUAGE = 'LLL:EXT:core/Resources/Private/Language/locallang_general.xlf:LGL.default_value';

    private const RELATION_POINTER_KEYS = ['foreign_field', 'foreign_table_field', 'foreign_sortby'];

    private const HIDDEN_CTRL_KEYS = ['sortby', 'transOrigPointerField', 'translationSource', 'transOrigDiffSourceField'];

    private const FLEXFORM_STRUCTURE_KEYS = ['data', 'lDEF', 'el', 'vDEF'];

    /**
     * @var array<int, string>
     */
    private array $batchTitles = [];

    /**
     * @var array<string, array<string, true>>
     */
    private array $hiddenFieldsByTable = [];

    private ?int $currentPid = null;

    public function __construct(
        private readonly RecordAccessService $recordAccess,
        private readonly TcaLabelService $tcaLabel,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly DataHandlerSanitizerService $sanitizer,
        private readonly RecordTypeAliasNormalizer $typeAliasNormalizer,
        private readonly TranslationFieldAliasNormalizer $translationAliasNormalizer,
        private readonly RelationResolutionService $relations,
        private readonly NestedChildExpanderService $nestedChildExpander,
        private readonly SiteFinder $siteFinder,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<mixed> $records
     *
     * @return list<array<string, mixed>>
     */
    public function describeWrite(array $records, int $maxValueLength = self::DEFAULT_MAX_VALUE_LENGTH): array
    {
        $records = $this->typeAliasNormalizer->normalize($records);

        $this->batchTitles = [];
        foreach (array_values($records) as $index => $record) {
            if (is_array($record) && is_array($record['fields'] ?? null)) {
                $this->batchTitles[$index] = $this->recordTitle((string) ($record['table'] ?? ''), $record['fields']);
            }
        }

        $described = [];
        foreach ($records as $record) {
            $described[] = is_array($record)
                ? $this->describeSingleWrite($record, $maxValueLength)
                : $this->invalid('Invalid (not an object)');
        }

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    public function describeExisting(string $table, int $uid, string $action, ?string $note = null): array
    {
        $descriptor = $this->emptyDescriptor($action);
        $descriptor['table'] = $table;
        $descriptor['tableLabel'] = $this->tableLabel($table);
        $descriptor['uid'] = $uid;
        $descriptor['note'] = $note;

        try {
            $this->recordAccess->validateTableReadAccess($table);
            $row = $this->recordAccess->assertRecordReadAccess($table, $uid);
        } catch (\Throwable $e) {
            $this->logger->warning('RecordPreview: cannot read record for preview', [
                'table' => $table,
                'uid' => $uid,
                'reason' => $e->getMessage(),
            ]);

            return $descriptor;
        }

        if (is_array($row)) {
            $descriptor['recordLabel'] = $this->recordTitle($table, $row);
            $descriptor['pid'] = isset($row['pid']) ? (int) $row['pid'] : null;
            $descriptor['pageLabel'] = $this->pageLabel($descriptor['pid']);
        }

        return $descriptor;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function labelFor(string $table, array $fields): string
    {
        if ('' === $table) {
            return '';
        }

        $labelField = $this->tcaCompatibilityService->getLabelField($table);
        $labelValue = $fields[$labelField] ?? null;
        if (is_scalar($labelValue) && '' !== (string) $labelValue) {
            $titles = $this->relationTitles($table, $labelField, (string) $labelValue, $this->fieldConfiguration($table, $labelField));
            if (null !== $titles) {
                return $titles;
            }
        }

        return $this->recordTitle($table, $fields);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function describeSingleWrite(array $record, int $maxValueLength): array
    {
        $table = (string) ($record['table'] ?? '');
        $uid = isset($record['uid']) ? (int) $record['uid'] : null;
        $pid = isset($record['pid']) ? (int) $record['pid'] : null;
        $fields = $record['fields'] ?? [];

        if ('' === $table || !is_array($fields) || [] === $fields) {
            return $this->invalid('Invalid (missing table or fields)');
        }

        $isCreate = null === $uid;
        $descriptor = $this->emptyDescriptor($isCreate ? 'create' : 'update');
        $descriptor['table'] = $table;
        $descriptor['tableLabel'] = $this->tableLabel($table);
        $descriptor['uid'] = $uid;
        $descriptor['pid'] = $pid;

        try {
            $this->recordAccess->validateTableReadAccess($table);
            if ($isCreate && null !== $pid) {
                $this->recordAccess->assertRecordCreateAccess($table, $pid);
            } elseif (null !== $uid) {
                $this->recordAccess->assertRecordEditAccess($table, $uid);
            }
            $fields = $this->translationAliasNormalizer->normalizeFields($table, $fields)['fields'];
            $fields = $this->recordAccess->filterAccessibleFields($table, $fields);
            $this->recordAccess->assertKnownRecordType($table, $fields);
            $this->nestedChildExpander->validate($record);
        } catch (InsufficientPermissionException $e) {
            $this->logger->warning('RecordPreview: skipping record, insufficient permission', [
                'table' => $table,
                'uid' => $uid,
                'pid' => $pid,
                'reason' => $e->getMessage(),
            ]);
            $descriptor['action'] = 'skipped';
            $descriptor['note'] = $e->getMessage();

            return $descriptor;
        } catch (InvalidParameterException|\RuntimeException $e) {
            $this->logger->warning('RecordPreview: skipping record, invalid input', [
                'table' => $table,
                'uid' => $uid,
                'pid' => $pid,
                'reason' => $e->getMessage(),
            ]);
            $descriptor['action'] = 'invalid';
            $descriptor['note'] = $e->getMessage();

            return $descriptor;
        }

        $existing = null;
        if (!$isCreate && null !== $uid) {
            $row = BackendUtility::getRecordWSOL($table, $uid);
            $existing = is_array($row) ? $row : null;
        }

        if (null !== $existing) {
            $descriptor['recordLabel'] = $this->recordTitle($table, $existing);
            $descriptor['pid'] = isset($existing['pid']) ? (int) $existing['pid'] : $pid;
        } else {
            $descriptor['recordLabel'] = $this->recordTitle($table, $fields);
            $descriptor['position'] = isset($record['position']) && is_scalar($record['position'])
                ? (string) $record['position']
                : 'end';
        }

        $report = $this->sanitize($table, $fields, $existing);
        if ([] !== $report['blocked']) {
            $descriptor['action'] = 'invalid';
            $descriptor['note'] = sprintf(
                'Raw markup is not allowed in the code editor field(s) %s of %s (setting "%s"); this record cannot be written',
                implode(', ', $report['blocked']),
                $table,
                RawMarkupPolicyService::SETTING_KEY,
            );

            return $descriptor;
        }

        $descriptor['pageLabel'] = $this->pageLabel(
            is_int($descriptor['pid']) ? $descriptor['pid'] : null,
        );
        $this->currentPid = is_int($descriptor['pid']) ? $descriptor['pid'] : null;
        $descriptor['fields'] = $this->describeFields($table, $report['data'], $existing, $maxValueLength);
        $descriptor['translations'] = $this->plannedTranslationLanguages($record);

        return $descriptor;
    }

    /**
     * @param array<string, mixed>      $sanitized
     * @param null|array<string, mixed> $existing
     *
     * @return list<array<string, mixed>>
     */
    private function describeFields(string $table, array $sanitized, ?array $existing, int $maxValueLength): array
    {
        $described = [];
        foreach ($sanitized as $field => $value) {
            $field = (string) $field;
            if (isset($this->hiddenFields($table)[$field])) {
                continue;
            }
            $new = $this->displayValue($table, $field, $this->resultingFlexForm($table, $field, $value, $existing), $maxValueLength);

            $old = null;
            if (null !== $existing && array_key_exists($field, $existing)) {
                $old = $this->displayValue($table, $field, $existing[$field], $maxValueLength);
            }

            $described[] = [
                'name' => $field,
                'label' => $this->tcaLabel->getFieldLabel($table, $field),
                'old' => null === $old ? null : $old['text'],
                'new' => $new['text'],
                'rawNew' => $new['raw'],
                'changed' => null === $old || $old['text'] !== $new['text'],
                'truncated' => $new['truncated'] || (null !== $old && $old['truncated']),
            ];
        }

        return $described;
    }

    /**
     * @param null|array<string, mixed> $existing
     */
    private function resultingFlexForm(string $table, string $field, mixed $value, ?array $existing): mixed
    {
        $stored = $existing[$field] ?? null;
        if (!is_array($value) || !is_string($stored) || 'flex' !== ($this->fieldConfiguration($table, $field)['type'] ?? '')) {
            return $value;
        }

        $storedValues = GeneralUtility::xml2array($stored);

        return is_array($storedValues) ? array_replace_recursive($storedValues, $value) : $value;
    }

    /**
     * @param array<string, mixed>      $fields
     * @param null|array<string, mixed> $existing
     *
     * @return array{data: array<string, mixed>, blocked: list<string>}
     */
    private function sanitize(string $table, array $fields, ?array $existing): array
    {
        try {
            $report = $this->sanitizer->sanitizeFieldsWithReport($table, $fields, null, $existing ?? []);

            return ['data' => $report['data'], 'blocked' => $report['blocked']];
        } catch (\Throwable $e) {
            $this->logger->warning('RecordPreview: sanitizing failed, showing raw values', [
                'table' => $table,
                'reason' => $e->getMessage(),
            ]);

            return ['data' => $fields, 'blocked' => []];
        }
    }

    /**
     * @return array{text: string, raw: string, truncated: bool}
     */
    private function displayValue(string $table, string $field, mixed $value, int $maxValueLength): array
    {
        $config = $this->fieldConfiguration($table, $field);
        $isFlexForm = 'flex' === ($config['type'] ?? '');

        if (is_array($value)) {
            $text = $isFlexForm ? $this->flexFormText($value) : $this->childrenText($config, $value);
            $truncated = $this->truncate($text, $maxValueLength);

            return ['text' => $truncated['text'], 'raw' => (string) json_encode($value), 'truncated' => $truncated['truncated']];
        }

        $raw = is_scalar($value) || null === $value ? (string) $value : (string) json_encode($value);
        $text = match (true) {
            1 === preg_match('/^\$ref:(\d+)$/', $raw, $ref) => $this->batchReferenceText((int) $ref[1]),
            $isFlexForm && str_starts_with(ltrim($raw), '<') => $this->flexFormText(GeneralUtility::xml2array($raw)),
            in_array($config['type'] ?? '', ['inline', 'file'], true) && is_numeric($raw) => 0 === (int) $raw ? '' : sprintf('%d item(s)', (int) $raw),
            is_numeric($raw) && $field === $this->tcaCompatibilityService->getLanguageFieldName($table) => $this->languageTitle((int) $raw),
            $this->isSingleCheckbox($config) => $this->checkboxText($config, $raw),
            default => $this->relationTitles($table, $field, $raw, $config) ?? $this->resolveItemLabel($table, $field, $raw),
        };

        if (str_contains($text, '<')) {
            $text = $this->sanitizer->toPlainText($text);
        }

        $truncated = $this->truncate($text, $maxValueLength);

        return ['text' => $truncated['text'], 'raw' => $raw, 'truncated' => $truncated['truncated']];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldConfiguration(string $table, string $field): array
    {
        try {
            return $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function isSingleCheckbox(array $config): bool
    {
        return 'check' === ($config['type'] ?? '') && count(is_array($config['items'] ?? null) ? $config['items'] : [[]]) <= 1;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function checkboxText(array $config, string $value): string
    {
        if ('' === $value || !is_numeric($value)) {
            return $value;
        }

        $item = is_array($config['items'][0] ?? null) ? $config['items'][0] : [];
        $state = (bool) ((int) $value & 1);
        if (true === ($item['invertStateDisplay'] ?? false)) {
            $state = !$state;
        }

        return $this->tcaLabel->resolveLabel(self::CHECKBOX_STATES[(int) $state]);
    }

    private function batchReferenceText(int $index): string
    {
        $title = $this->batchTitles[$index] ?? '';

        return '' === $title ? sprintf('new record #%d of this batch', $index + 1) : sprintf('%s (new)', $title);
    }

    /**
     * @param array<mixed>         $children
     * @param array<string, mixed> $config
     */
    private function childrenText(array $config, array $children): string
    {
        $foreignTable = (string) ($config['foreign_table'] ?? '');
        $labels = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                $labels[] = is_scalar($child) ? '#'.$child : '';

                continue;
            }
            $fields = is_array($child['fields'] ?? null) ? $child['fields'] : $child;
            $labels[] = $this->labelFor($foreignTable, $fields);
        }
        $labels = array_values(array_filter($labels, static fn (string $label): bool => '' !== $label));

        return [] === $labels ? sprintf('%d item(s)', count($children)) : implode(', ', $labels);
    }

    /**
     * @param array<mixed>|string $flexForm
     */
    private function flexFormText(array|string $flexForm): string
    {
        if (!is_array($flexForm)) {
            return '';
        }

        $values = [];
        $this->collectFlexFormValues($flexForm, '', $values);

        $pairs = [];
        foreach ($values as $name => $value) {
            $pairs[] = sprintf('%s: %s', $name, $value);
        }

        return implode(' · ', $pairs);
    }

    /**
     * @param array<mixed>          $node
     * @param array<string, string> $values
     */
    private function collectFlexFormValues(array $node, string $name, array &$values): void
    {
        foreach ($node as $key => $child) {
            $key = (string) $key;
            $childName = in_array($key, self::FLEXFORM_STRUCTURE_KEYS, true) || is_numeric($key) ? $name : $key;
            if ('vDEF' === $key && is_scalar($child)) {
                $values[(string) preg_replace('/^settings\./', '', $name)] = trim(strip_tags((string) $child));
            } elseif (is_array($child)) {
                $this->collectFlexFormValues($child, $childName, $values);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function relationTitles(string $table, string $field, string $raw, array $config): ?string
    {
        if ('' === $raw || [] === $config || !$this->tcaCompatibilityService->isRelationalFieldConfig($config)) {
            return null;
        }

        return $this->relations->resolveTitles($table, $field, $raw);
    }

    private function languageTitle(int $languageId): string
    {
        if (-1 === $languageId) {
            return $this->tcaLabel->resolveLabel(self::ALL_LANGUAGES);
        }

        try {
            return $this->siteFinder->getSiteByPageId((int) $this->currentPid)->getLanguageById($languageId)->getTitle();
        } catch (\Throwable) {
            return 0 === $languageId ? $this->tcaLabel->resolveLabel(self::DEFAULT_LANGUAGE) : (string) $languageId;
        }
    }

    /**
     * @return array<string, true>
     */
    private function hiddenFields(string $table): array
    {
        if (isset($this->hiddenFieldsByTable[$table])) {
            return $this->hiddenFieldsByTable[$table];
        }

        $hidden = [];

        try {
            $ctrl = $this->tcaCompatibilityService->getRawConfiguration($table);
            foreach (self::HIDDEN_CTRL_KEYS as $key) {
                if (is_string($ctrl[$key] ?? null) && '' !== $ctrl[$key]) {
                    $hidden[$ctrl[$key]] = true;
                }
            }
            foreach ($this->tcaCompatibilityService->getAllTableNames() as $parentTable) {
                foreach ($this->tcaCompatibilityService->getColumnConfigs($parentTable) as $config) {
                    if (!in_array($config['type'] ?? '', ['inline', 'file'], true) || $table !== ($config['foreign_table'] ?? '')) {
                        continue;
                    }
                    foreach (self::RELATION_POINTER_KEYS as $key) {
                        if (is_string($config[$key] ?? null) && '' !== $config[$key]) {
                            $hidden[$config[$key]] = true;
                        }
                    }
                    foreach (array_keys(is_array($config['foreign_match_fields'] ?? null) ? $config['foreign_match_fields'] : []) as $matchField) {
                        $hidden[(string) $matchField] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('RecordPreview: could not derive the relation pointer fields', ['table' => $table, 'reason' => $e->getMessage()]);
        }

        return $this->hiddenFieldsByTable[$table] = $hidden;
    }

    private function resolveItemLabel(string $table, string $field, string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        try {
            $config = $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
        } catch (\Throwable) {
            return $value;
        }

        $type = $config['type'] ?? '';
        if (!in_array($type, ['select', 'radio'], true) || !isset($config['items'])) {
            return $value;
        }

        $label = $this->tcaLabel->getTypeItemLabel($table, $field, $value);

        return '' === $label ? $value : $label;
    }

    /**
     * @return array{text: string, truncated: bool}
     */
    private function truncate(string $value, int $maxValueLength): array
    {
        if (mb_strlen($value) <= $maxValueLength) {
            return ['text' => $value, 'truncated' => false];
        }

        return ['text' => mb_substr($value, 0, $maxValueLength), 'truncated' => true];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function recordTitle(string $table, array $row): string
    {
        try {
            $title = BackendUtility::getRecordTitle($table, $row);
        } catch (\Throwable) {
            return '';
        }

        if (str_contains($title, '<')) {
            $title = $this->sanitizer->toPlainText($title);
        }
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));

        return $this->truncate($title, self::MAX_TITLE_LENGTH)['text'];
    }

    private function pageLabel(?int $pid): ?string
    {
        if (null === $pid || $pid <= 0) {
            return null;
        }

        try {
            $page = $this->recordAccess->assertPagePerm($pid, Permission::PAGE_SHOW);
        } catch (\Throwable) {
            return null;
        }

        return $this->recordTitle('pages', $page);
    }

    private function tableLabel(string $table): string
    {
        try {
            return $this->tcaLabel->getTableLabel($table);
        } catch (\Throwable) {
            return $table;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function invalid(string $note): array
    {
        $descriptor = $this->emptyDescriptor('invalid');
        $descriptor['note'] = $note;

        return $descriptor;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDescriptor(string $action): array
    {
        return [
            'action' => $action,
            'table' => '',
            'tableLabel' => '',
            'recordLabel' => '',
            'uid' => null,
            'pid' => null,
            'pageLabel' => null,
            'position' => null,
            'note' => null,
            'fields' => [],
            'translations' => [],
        ];
    }

    /**
     * @param array<string, mixed> $record
     *
     * @return list<string>
     */
    private function plannedTranslationLanguages(array $record): array
    {
        $translations = $record[TranslationExpanderService::TRANSLATIONS_KEY] ?? null;
        if (!is_array($translations)) {
            return [];
        }

        $languages = [];
        foreach (array_keys($translations) as $language) {
            $code = trim((string) $language);
            if ('' !== $code) {
                $languages[] = $code;
            }
        }

        return $languages;
    }
}
