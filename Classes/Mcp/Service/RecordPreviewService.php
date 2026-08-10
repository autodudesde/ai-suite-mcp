<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;

class RecordPreviewService
{
    private const DEFAULT_MAX_VALUE_LENGTH = 300;

    private const MAX_TITLE_LENGTH = 70;

    public function __construct(
        private readonly RecordAccessService $recordAccess,
        private readonly TcaLabelService $tcaLabel,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly DataHandlerSanitizerService $sanitizer,
        private readonly RecordTypeAliasNormalizer $typeAliasNormalizer,
        private readonly TranslationFieldAliasNormalizer $translationAliasNormalizer,
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
            $row = BackendUtility::getRecordWSOL($table, $uid);
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
            $new = $this->displayValue($table, $field, $value, $maxValueLength);

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
        if (is_array($value)) {
            $raw = (string) json_encode($value);
            $text = sprintf('%d nested item(s)', count($value));

            return ['text' => $this->truncate($text, $maxValueLength)['text'], 'raw' => $raw, 'truncated' => false];
        }

        $raw = is_scalar($value) || null === $value ? (string) $value : (string) json_encode($value);
        $text = $this->resolveItemLabel($table, $field, $raw);

        if (str_contains($text, '<')) {
            $text = $this->sanitizer->toPlainText($text);
        }

        $truncated = $this->truncate($text, $maxValueLength);

        return ['text' => $truncated['text'], 'raw' => $raw, 'truncated' => $truncated['truncated']];
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

        $page = BackendUtility::getRecordWSOL('pages', $pid);

        return is_array($page) ? $this->recordTitle('pages', $page) : null;
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
