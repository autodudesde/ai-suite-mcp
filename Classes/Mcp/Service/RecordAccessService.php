<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\NotInMountPointException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

class RecordAccessService
{
    public const MAX_FILTERABLE_PAGES = 10000;

    private const MAX_LISTED_FIELDS = 40;

    private const MAX_SUGGESTIONS = 2;

    private const MAX_SUGGESTION_DISTANCE = 3;

    private const MIN_CONTAINED_SUGGESTION_LENGTH = 4;

    private const RECORD_PERMISSION_MAP = [
        'pages' => [
            'read' => [Permission::PAGE_SHOW, 'self'],
            'edit' => [Permission::PAGE_EDIT, 'self'],
            'create' => [Permission::PAGE_NEW, 'parent'],
        ],
        'tt_content' => [
            'read' => [Permission::PAGE_SHOW, 'parent'],
            'edit' => [Permission::CONTENT_EDIT, 'parent'],
            'create' => [Permission::CONTENT_EDIT, 'parent'],
        ],
    ];

    private const DEFAULT_RECORD_PERMISSION = [
        'read' => [Permission::PAGE_SHOW, 'parent'],
        'edit' => [Permission::PAGE_EDIT, 'parent'],
        'create' => [Permission::PAGE_EDIT, 'parent'],
    ];

    private const FILE_REFERENCE_FIELD_MAP = [
        'sys_file_metadata' => 'file',
        'sys_file_reference' => 'uid_local',
    ];

    private const TYPE_LOOKUP_TOOL = [
        'tt_content' => 'listContentTypes',
        'pages' => 'listPageTypes',
    ];

    private const MAX_LISTED_TYPE_VALUES = 20;

    /**
     * @var array<string, list<int>>
     */
    private array $readablePageIdsCache = [];

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly LocalizationService $localizationService,
        private readonly SiteFinder $siteFinder,
        private readonly ResourceFactory $resourceFactory,
        private readonly McpExcludedTablesService $excludedTablesService,
        private readonly LoggerInterface $logger,
    ) {}

    public function validateTableReadAccess(string $table): void
    {
        if (!$this->tcaCompatibilityService->hasTable($table)) {
            throw new \RuntimeException(sprintf('Table "%s" does not exist.', $table));
        }
        if ($this->excludedTablesService->isExcluded($table)) {
            throw new \RuntimeException(sprintf('Table "%s" is excluded from MCP access.', $table));
        }
        $beUser = $this->getBackendUser();
        if (null !== $beUser && !$beUser->isAdmin() && !$beUser->check('tables_select', $table)) {
            throw new \RuntimeException(sprintf('No read access to table "%s".', $table));
        }
    }

    public function validateTableWriteAccess(string $table): void
    {
        $this->validateTableReadAccess($table);
        $beUser = $this->getBackendUser();
        if (null !== $beUser && !$beUser->isAdmin() && !$beUser->check('tables_modify', $table)) {
            throw new \RuntimeException(sprintf('No write access to table "%s".', $table));
        }
    }

    public function hasTableReadAccess(string $table): bool
    {
        if (!$this->tcaCompatibilityService->hasTable($table)) {
            return false;
        }
        if ($this->excludedTablesService->isExcluded($table)) {
            return false;
        }
        $beUser = $this->getBackendUser();

        return null === $beUser || $beUser->isAdmin() || $beUser->check('tables_select', $table);
    }

    public function hasTableWriteAccess(string $table): bool
    {
        if (!$this->hasTableReadAccess($table)) {
            return false;
        }
        $beUser = $this->getBackendUser();

        return null === $beUser || $beUser->isAdmin() || $beUser->check('tables_modify', $table);
    }

    public function canAccessField(string $table, string $field): bool
    {
        try {
            if (!$this->tcaCompatibilityService->hasField($table, $field)) {
                return false;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('RecordAccessService::canAccessField: TCA lookup failed, denying access', [
                'table' => $table,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $beUser = $this->getBackendUser();
        if (null === $beUser || $beUser->isAdmin()) {
            return true;
        }

        $fieldConfig = $this->tcaCompatibilityService->getFieldConfiguration($table, $field);
        if ($fieldConfig['exclude'] ?? false) {
            return $beUser->check('non_exclude_fields', $table.':'.$field);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     *
     * @throws InvalidParameterException
     */
    public function filterAccessibleFields(string $table, array $fields): array
    {
        $filtered = [];
        $unknownFields = [];
        $deniedFields = [];

        foreach ($fields as $field => $value) {
            if ($this->canAccessField($table, $field)) {
                $filtered[$field] = $value;
            } elseif (!$this->fieldExistsInSchema($table, $field)) {
                $unknownFields[] = $field;
            } else {
                $deniedFields[] = $field;
            }
        }

        if ([] !== $unknownFields) {
            throw new InvalidParameterException($this->describeUnknownFields($table, $unknownFields));
        }

        if ([] !== $deniedFields) {
            throw new InvalidParameterException(sprintf(
                'Access denied to field(s) for table "%s": %s.',
                $table,
                implode(', ', $deniedFields),
            ));
        }

        return $filtered;
    }

    public function fieldExistsInSchema(string $table, string $field): bool
    {
        try {
            return $this->tcaCompatibilityService->hasField($table, $field);
        } catch (\Throwable $e) {
            $this->logger->warning('RecordAccessService::fieldExistsInSchema: TCA lookup failed, treating field as missing', [
                'table' => $table,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function getSchemaFieldNames(string $table): array
    {
        try {
            return $this->tcaCompatibilityService->getFieldNames($table);
        } catch (\Throwable $e) {
            $this->logger->warning('RecordAccessService::getSchemaFieldNames: TCA lookup failed, returning empty list', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @throws InvalidParameterException
     */
    public function assertKnownRecordType(string $table, array $fields): void
    {
        $divisor = $this->tcaCompatibilityService->getSubSchemaDivisorFieldName($table);
        if (null === $divisor || !array_key_exists($divisor, $fields)) {
            // No type column at all, or a partial update that does not touch it.
            return;
        }

        if ($this->tcaCompatibilityService->isSubSchemaDivisorForeignPointer($table)) {
            return;
        }

        $value = $fields[$divisor];
        if (!is_scalar($value) || '' === (string) $value) {
            // Empty means "not provided" -- DataHandler applies the TCA default.
            return;
        }
        $value = (string) $value;

        if ($this->tcaCompatibilityService->hasSubSchema($table, $value)) {
            return;
        }

        $allowed = $this->allowedTypeValues($table, $divisor);
        if ([] === $allowed) {
            return;
        }

        throw (new InvalidParameterException($this->unknownTypeMessage($table, $divisor, $value, $allowed)))
            ->withErrorContext(['table' => $table, 'field' => $divisor])
        ;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     *
     * @throws InvalidParameterException
     * @throws \RuntimeException
     */
    public function findMissingRequiredFields(string $table, ?string $typeValue, array $fields): array
    {
        $missing = [];

        // Outside the try below: its catch would reclassify InvalidParameterException.
        $this->assertKnownRecordType($table, $fields);

        try {
            $typeKey = (null !== $typeValue && '' !== $typeValue && $this->tcaCompatibilityService->hasSubSchema($table, $typeValue))
                ? $typeValue
                : $this->tcaCompatibilityService->resolveDefaultSubSchemaType($table);

            foreach ($this->tcaCompatibilityService->getFieldNamesForType($table, $typeKey) as $field) {
                $config = $this->tcaCompatibilityService->getEffectiveFieldConfiguration($table, $typeKey, $field);

                if (!$this->tcaCompatibilityService->isFieldRequired($config)) {
                    continue;
                }
                if (\in_array($config['type'] ?? '', ['inline', 'file'], true)) {
                    continue;
                }
                if (isset($config['default']) && '' !== (string) $config['default']) {
                    continue;
                }

                $value = $fields[$field] ?? null;
                // Nested values (FlexForm) are never empty and must not be cast to string.
                $scalar = is_array($value)
                    ? implode('', array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '1', $value))
                    : (string) $value;
                if (null === $value || '' === $scalar) {
                    $missing[] = $field;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('RecordAccessService::findMissingRequiredFields: TCA introspection failed', [
                'table' => $table,
                'type' => $typeValue,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException(sprintf('Could not validate required fields for "%s": %s', $table, $e->getMessage()), 0, $e);
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InsufficientPermissionException
     * @throws \RuntimeException
     */
    public function assertPagePerm(int $pageId, int $perm): array
    {
        $beUser = $this->requireBackendUser();

        if ($beUser->isAdmin()) {
            if (0 === $pageId) {
                return ['_thePath' => '/'];
            }
            $row = BackendUtility::getRecordWSOL('pages', $pageId);
            if (null === $row) {
                throw new \RuntimeException(sprintf('Page %d does not exist.', $pageId));
            }

            return $row;
        }

        if (0 === $pageId && Permission::PAGE_NEW === $perm) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_page_access', [$pageId], sprintf('No permission to create root subpage (page %d).', $pageId)),
            );
        }

        $row = BackendUtility::readPageAccess($pageId, $beUser->getPagePermsClause($perm));
        if (!is_array($row)) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_page_access', [$pageId], sprintf('No permission to access page %d.', $pageId)),
            );
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InsufficientPermissionException
     * @throws \RuntimeException
     */
    public function assertRecordReadAccess(string $table, int $uid): array
    {
        return $this->assertRecordAccess($table, $uid, 'read');
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InsufficientPermissionException
     * @throws \RuntimeException
     */
    public function assertRecordEditAccess(string $table, int $uid): array
    {
        return $this->assertRecordAccess($table, $uid, 'edit');
    }

    /**
     * @throws InsufficientPermissionException
     */
    public function assertRecordCreateAccess(string $table, int $pid): void
    {
        $beUser = $this->requireBackendUser();

        if ($beUser->isAdmin()) {
            return;
        }

        if (!$beUser->check('tables_modify', $table)) {
            throw new InsufficientPermissionException(
                sprintf('No write access to table "%s".', $table),
            );
        }

        if (0 === $pid) {
            try {
                if ($this->tcaCompatibilityService->isRootLevel($table)) {
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('RecordAccessService::assertRecordCreateAccess: TCA rootLevel check failed, falling through to admin-only', [
                    'table' => $table,
                    'error' => $e->getMessage(),
                ]);
            }

            throw new InsufficientPermissionException(
                sprintf('Creating "%s" records at the root level requires admin privileges.', $table),
            );
        }

        [$bit] = self::RECORD_PERMISSION_MAP[$table]['create'] ?? self::DEFAULT_RECORD_PERMISSION['create'];
        $this->assertPagePerm($pid, $bit);
    }

    public function canReadRecordTitle(string $foreignTable, int $uid): bool
    {
        if ($uid <= 0 || !$this->hasTableReadAccess($foreignTable)) {
            return false;
        }

        $beUser = $this->getBackendUser();
        if (null === $beUser || $beUser->isAdmin()) {
            return true;
        }

        try {
            if ('pages' === $foreignTable) {
                return is_array(BackendUtility::readPageAccess($uid, $beUser->getPagePermsClause(Permission::PAGE_SHOW)));
            }

            $record = BackendUtility::getRecordWSOL($foreignTable, $uid);
            if (null === $record) {
                return false;
            }

            $pid = (int) ($record['pid'] ?? 0);
            if ($pid <= 0) {
                return $this->tcaCompatibilityService->isRootLevel($foreignTable);
            }

            return is_array(BackendUtility::readPageAccess($pid, $beUser->getPagePermsClause(Permission::PAGE_SHOW)));
        } catch (\Throwable $e) {
            $this->logger->warning('RecordAccessService::canReadRecordTitle: access check failed, denying', [
                'table' => $foreignTable,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @throws InsufficientPermissionException
     * @throws \RuntimeException
     */
    public function assertFileReadAccess(int $fileUid): File
    {
        $beUser = $this->requireBackendUser();

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('File UID %d not found.', $fileUid), 0, $e);
        }

        if (!$beUser->isAdmin() && !$file->checkActionPermission('read')) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_file_access', [$fileUid], sprintf('No permission to read file UID %d.', $fileUid)),
            );
        }

        return $file;
    }

    /**
     * @throws InsufficientPermissionException
     * @throws \RuntimeException
     */
    public function assertFileWriteAccess(int $fileUid): File
    {
        $beUser = $this->requireBackendUser();

        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('File UID %d not found.', $fileUid), 0, $e);
        }

        if (!$beUser->isAdmin() && !$file->checkActionPermission('write')) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_file_access', [$fileUid], sprintf('No write permission on file UID %d.', $fileUid)),
            );
        }

        return $file;
    }

    /**
     * @throws InsufficientPermissionException
     */
    public function assertFolderReadAccess(string $combinedIdentifier): Folder
    {
        $beUser = $this->requireBackendUser();

        if ($beUser->isAdmin()) {
            return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
        }

        try {
            return $this->backendUserService->getReadableFolder($combinedIdentifier);
        } catch (InsufficientFolderAccessPermissionsException|NotInMountPointException $e) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_folder_access', [$combinedIdentifier], $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * @throws InsufficientPermissionException
     */
    public function assertFolderWriteAccess(string $combinedIdentifier): Folder
    {
        $beUser = $this->requireBackendUser();

        if ($beUser->isAdmin()) {
            return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($combinedIdentifier);
        }

        try {
            return $this->backendUserService->getWriteableFolder($combinedIdentifier);
        } catch (InsufficientFolderAccessPermissionsException|NotInMountPointException $e) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_folder_access', [$combinedIdentifier], $e->getMessage()),
                0,
                $e,
            );
        }
    }

    /**
     * @throws InsufficientPermissionException
     */
    public function assertLanguageAccess(int $languageUid): void
    {
        $beUser = $this->requireBackendUser();

        if ($beUser->isAdmin()) {
            return;
        }

        if (!$beUser->checkLanguageAccess($languageUid)) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_language_access', [$languageUid], sprintf('No permission to use language UID %d.', $languageUid)),
            );
        }
    }

    /**
     * @return list<int>
     *
     * @throws InsufficientPermissionException
     */
    public function getReadablePageIds(int $rootId = 0, int $depth = 99): array
    {
        $cacheKey = $rootId.':'.$depth;
        if (isset($this->readablePageIdsCache[$cacheKey])) {
            return $this->readablePageIdsCache[$cacheKey];
        }

        $pageIds = $this->backendUserService->getSearchableWebmounts($rootId, $depth);

        if (count($pageIds) > self::MAX_FILTERABLE_PAGES) {
            throw new InsufficientPermissionException(sprintf(
                'Webmount too large (%d pages) for filter-only mode; please specify pid or uid to scope the query.',
                count($pageIds),
            ));
        }

        return $this->readablePageIdsCache[$cacheKey] = $pageIds;
    }

    public function resolveLanguageUid(?string $isoCode, int $pageId): int
    {
        if (null === $isoCode || '' === $isoCode) {
            return 0;
        }

        if ($pageId <= 0) {
            return 0;
        }

        $needle = strtolower($isoCode);

        try {
            foreach ($this->siteFinder->getSiteByPageId($pageId)->getAllLanguages() as $language) {
                if (strtolower($language->getLocale()->getLanguageCode()) === $needle) {
                    return $language->getLanguageId();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('RecordAccessService::resolveLanguageUid: site lookup failed, falling back to default language (0)', [
                'pageId' => $pageId,
                'isoCode' => $isoCode,
                'error' => $e->getMessage(),
            ]);
        }

        return 0;
    }

    public function getBackendUser(): ?BackendUserAuthentication
    {
        return $this->backendUserService->getBackendUser();
    }

    /**
     * @param list<string> $unknownFields
     */
    private function describeUnknownFields(string $table, array $unknownFields): string
    {
        $validFields = $this->getSchemaFieldNames($table);

        $suggestions = [];
        foreach ($unknownFields as $unknown) {
            $closest = $this->closestFieldNames($unknown, $validFields);
            if ([] !== $closest) {
                $suggestions[] = sprintf('%s → %s', $unknown, implode(' or ', $closest));
            }
        }

        $message = sprintf(
            'Unknown field(s) for table "%s": %s.',
            $table,
            implode(', ', $unknownFields),
        );

        if ([] !== $suggestions) {
            $message .= sprintf(' Did you mean: %s?', implode('; ', $suggestions));
        }

        $shown = array_slice($validFields, 0, self::MAX_LISTED_FIELDS);
        $message .= sprintf(' Available fields: %s', implode(', ', $shown));
        $remaining = count($validFields) - count($shown);
        $message .= $remaining > 0
            ? sprintf(' and %d more — readRecordSchema lists them all.', $remaining)
            : '. Use readRecordSchema for their types.';

        return $message;
    }

    /**
     * @param list<string> $validFields
     *
     * @return list<string>
     */
    private function closestFieldNames(string $unknown, array $validFields): array
    {
        $contained = $this->containedFieldNames($unknown, $validFields);
        if ([] !== $contained) {
            return $contained;
        }

        $maxDistance = min(self::MAX_SUGGESTION_DISTANCE, (int) floor(mb_strlen($unknown) / 2));
        if ($maxDistance < 1) {
            return [];
        }

        $scored = [];
        foreach ($validFields as $candidate) {
            $distance = levenshtein($unknown, $candidate);
            if ($distance > 0 && $distance <= $maxDistance) {
                $scored[$candidate] = $distance;
            }
        }

        asort($scored);

        return array_slice(array_keys($scored), 0, self::MAX_SUGGESTIONS);
    }

    /**
     * @param list<string> $validFields
     *
     * @return list<string>
     */
    private function containedFieldNames(string $unknown, array $validFields): array
    {
        $matches = [];
        foreach ($validFields as $candidate) {
            $length = mb_strlen($candidate);
            if ($length < self::MIN_CONTAINED_SUGGESTION_LENGTH) {
                continue;
            }
            if (str_contains($unknown, $candidate) || str_contains($candidate, $unknown)) {
                $matches[$candidate] = -$length;
            }
        }

        asort($matches);

        return array_slice(array_keys($matches), 0, self::MAX_SUGGESTIONS);
    }

    /**
     * @return list<string>
     */
    private function allowedTypeValues(string $table, string $divisor): array
    {
        $values = [];

        try {
            $items = $this->tcaCompatibilityService->getFieldConfiguration($table, $divisor)['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item) || !isset($item['value']) || !is_scalar($item['value'])) {
                        continue;
                    }
                    $value = (string) $item['value'];
                    if ('' === $value || '--div--' === $value) {
                        continue;
                    }
                    $values[] = $value;
                }
            }

            if ([] === $values) {
                foreach (array_keys($this->tcaCompatibilityService->getTypes($table)) as $typeKey) {
                    $values[] = (string) $typeKey;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('RecordAccessService::allowedTypeValues: TCA lookup failed, skipping type validation', [
                'table' => $table,
                'field' => $divisor,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return array_values(array_unique($values));
    }

    /**
     * @param list<string> $allowed
     */
    private function unknownTypeMessage(string $table, string $divisor, string $value, array $allowed): string
    {
        $message = sprintf('Unknown %s "%s" for table "%s".', $divisor, $value, $table);

        $suggestion = $this->closestTypeValue($value, $allowed);
        if (null !== $suggestion) {
            $message .= sprintf(' Did you mean "%s"?', $suggestion);
        }

        $message .= ' '.sprintf(
            'Call %s for the values available here.',
            self::TYPE_LOOKUP_TOOL[$table] ?? sprintf('readRecordSchema(table: "%s")', $table),
        );

        if (count($allowed) <= self::MAX_LISTED_TYPE_VALUES) {
            $message .= ' Valid values: '.implode(', ', $allowed).'.';
        }

        return $message;
    }

    /**
     * @param list<string> $allowed
     */
    private function closestTypeValue(string $value, array $allowed): ?string
    {
        $threshold = max(2, (int) floor(mb_strlen($value) / 3));
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($allowed as $candidate) {
            $distance = levenshtein($value, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $bestDistance <= $threshold ? $best : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InsufficientPermissionException
     * @throws \RuntimeException
     */
    private function assertRecordAccess(string $table, int $uid, string $op): array
    {
        $beUser = $this->requireBackendUser();

        if ('pages' === $table) {
            [$bit] = self::RECORD_PERMISSION_MAP['pages'][$op] ?? self::DEFAULT_RECORD_PERMISSION[$op];

            return $this->assertPagePerm($uid, $bit);
        }

        $record = BackendUtility::getRecordWSOL($table, $uid);
        if (null === $record) {
            throw new \RuntimeException(sprintf('Record %s:%d not found.', $table, $uid));
        }

        if ($beUser->isAdmin()) {
            return $record;
        }

        $tableCheck = 'edit' === $op ? 'tables_modify' : 'tables_select';
        if (!$beUser->check($tableCheck, $table)) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_record_access', [$table, $uid], sprintf('No permission to access record %s:%d.', $table, $uid)),
            );
        }

        $pid = (int) ($record['pid'] ?? 0);
        if ($pid > 0) {
            [$bit] = self::RECORD_PERMISSION_MAP[$table][$op] ?? self::DEFAULT_RECORD_PERMISSION[$op];
            $this->assertPagePerm($pid, $bit);
        }
        if ('edit' === $op) {
            $allowed = $beUser->recordEditAccessInternals(
                $table,
                $record,
                false,
                $this->tcaCompatibilityService->getRecordEditAccessDeletedArgument(),
            );
            if (!$allowed) {
                $message = $this->translateOrFallback('hint.no_record_access', [$table, $uid], sprintf('No edit permission on record %s:%d.', $table, $uid));

                throw new InsufficientPermissionException($message);
            }
        }

        if (isset(self::FILE_REFERENCE_FIELD_MAP[$table])) {
            $fileField = self::FILE_REFERENCE_FIELD_MAP[$table];
            $referencedFileUid = (int) ($record[$fileField] ?? 0);
            if ($referencedFileUid > 0) {
                $this->assertFileReadAccess($referencedFileUid);
            }
        }

        return $record;
    }

    private function requireBackendUser(): BackendUserAuthentication
    {
        $beUser = $this->getBackendUser();
        if (null === $beUser) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback('hint.no_be_user_context', [], 'No backend user context available.'),
            );
        }

        return $beUser;
    }

    /**
     * @param list<int|string> $arguments
     */
    private function translateOrFallback(string $key, array $arguments, string $fallback): string
    {
        $translated = $this->localizationService->translate('mcp:'.$key, $arguments);

        return '' !== $translated ? $translated : $fallback;
    }
}
