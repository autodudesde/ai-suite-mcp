<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientScopeException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpExcludedTablesService;
use B13\Container\Tca\Registry;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Abstract base class for all MCP tools.
 */
abstract class AbstractTool implements ToolInterface
{
    public const MAX_FILTERABLE_PAGES = 10000;

    /**
     * Per-(table, op) page-permission descriptor: [bit, anchor]. `anchor='self'` means
     * the bit is checked on $record['uid']; `anchor='parent'` means $record['pid'] (read/edit)
     * or $pid (create, supplied by caller).
     */
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

    /**
     * Tables that point to a sys_file UID via a column. Permission checks for these
     * tables additionally verify file-mount access on the referenced sys_file.
     */
    private const FILE_REFERENCE_FIELD_MAP = [
        'sys_file_metadata' => 'file',
        'sys_file_reference' => 'uid_local',
    ];

    protected ?string $requiredScope = null;

    protected readonly McpUserContext $userContext;
    protected readonly McpPermissionService $permissionService;
    protected readonly LoggerInterface $logger;
    protected readonly TcaCompatibilityService $tcaCompatibilityService;
    protected readonly SiteFinder $siteFinder;
    protected readonly LocalizationService $localizationService;
    protected readonly BackendUserService $backendUserService;
    protected readonly ResourceFactory $resourceFactory;
    protected readonly McpExcludedTablesService $excludedTablesService;

    /**
     * @var array<string, list<int>> keyed by "{rootId}:{depth}"
     */
    private array $readablePageIdsCache = [];

    public function __construct(
        protected readonly McpToolContext $mcpToolContext,
    ) {
        $this->userContext = $mcpToolContext->userContext;
        $this->permissionService = $mcpToolContext->permissionService;
        $this->logger = $mcpToolContext->logger;
        $this->tcaCompatibilityService = $mcpToolContext->tcaCompatibilityService;
        $this->siteFinder = $mcpToolContext->siteFinder;
        $this->localizationService = $mcpToolContext->localizationService;
        $this->backendUserService = $mcpToolContext->backendUserService;
        $this->resourceFactory = $mcpToolContext->resourceFactory;
        $this->excludedTablesService = $mcpToolContext->excludedTablesService;
    }

    final public function execute(array $params): CallToolResult
    {
        try {
            $params = $this->validateAndSanitizeParams($params);
            $this->validatePermissions();
            $this->initialize();

            return $this->doExecute($params);
        } catch (InvalidParameterException $e) {
            $this->logger->warning('MCP tool received invalid input', [
                'tool' => $this->getName(),
                'beUserUid' => $this->getBackendUser()?->user['uid'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return new CallToolResult(
                [new TextContent(
                    $this->translate('hint.invalid_input', [$e->getMessage()])
                        ?? 'Please check the input: '.$e->getMessage(),
                )],
                isError: true,
            );
        } catch (InsufficientPermissionException|InsufficientScopeException $e) {
            $this->logger->info('MCP tool permission denied', [
                'tool' => $this->getName(),
                'beUserUid' => $this->getBackendUser()?->user['uid'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return new CallToolResult(
                [new TextContent($e->getMessage())],
                isError: true,
            );
        } catch (\RuntimeException $e) {
            // RuntimeExceptions carry actionable messages (e.g. from AI Suite Server errors).
            // Pass them through so the MCP client sees the actual error and error codes.
            $this->logger->error('MCP tool execution failed', [
                'tool' => $this->getName(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new CallToolResult(
                [new TextContent($e->getMessage())],
                isError: true,
            );
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool execution failed', [
                'tool' => $this->getName(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return new CallToolResult(
                [new TextContent(
                    $this->translate('hint.internal_issue', [$this->getName()])
                        ?? sprintf(
                            'Something unexpected happened while running "%s". Please try again or contact your administrator if the issue persists.',
                            $this->getName(),
                        ),
                )],
                isError: true,
            );
        }
    }

    public function getRequiredScope(): ?string
    {
        return $this->requiredScope;
    }

    /**
     * Implement the actual tool logic here.
     *
     * @param array<string, mixed> $params Validated and sanitized parameters
     */
    abstract protected function doExecute(array $params): CallToolResult;

    /**
     * Optional hook for subclasses to initialize resources before execution.
     */
    protected function initialize(): void {}

    /**
     * Validates OAuth scopes and TYPO3 permissions for the current tool.
     */
    protected function validatePermissions(): void
    {
        $this->permissionService->validateToolAccess(
            $this->getName(),
            $this->userContext->getScopes(),
        );
    }

    /**
     * Validates parameters against the tool's JSON schema and sanitizes values.
     *
     * @param array<string, mixed> $params Raw parameters from the MCP client
     *
     * @return array<string, mixed> Validated and sanitized parameters
     *
     * @throws InvalidParameterException
     */
    protected function validateAndSanitizeParams(array $params): array
    {
        $schema = $this->getSchema();
        $properties = $schema['properties'] ?? [];

        /** @var list<string> $knownKeys */
        $knownKeys = $properties instanceof \stdClass ? [] : array_keys($properties);

        // 0. Lenient parameter name matching: map snake_case/kebab-case variants to camelCase
        $params = $this->normalizeParameterNames($params, $knownKeys);

        // 1. Required fields
        foreach ($schema['required'] ?? [] as $required) {
            if (!array_key_exists($required, $params)) {
                throw new InvalidParameterException(
                    sprintf('Missing required parameter: %s', $required),
                );
            }
        }

        // 2. Type validation and casting
        foreach ($params as $key => $value) {
            $propSchema = $schema['properties'][$key] ?? null;
            if (null === $propSchema) {
                unset($params[$key]);

                continue;
            }

            $params[$key] = match ($propSchema['type'] ?? null) {
                'integer' => $this->validateInteger($key, $value, $propSchema),
                'boolean' => (bool) $value,
                'string' => $this->validateString($key, $value, $propSchema),
                'array' => $this->validateArray($key, $value, $propSchema),
                default => $value,
            };
        }

        // 3. Defaults for missing optional parameters
        foreach ($schema['properties'] ?? [] as $key => $propSchema) {
            if (!array_key_exists($key, $params) && isset($propSchema['default'])) {
                $params[$key] = $propSchema['default'];
            }
        }

        return $params;
    }

    /**
     * Translate a locallang key from the MCP language file.
     *
     * @param list<int|string> $arguments
     */
    protected function translate(string $key, array $arguments = []): string
    {
        return $this->localizationService->translate('mcp:'.$key, $arguments);
    }

    // ── Table & Field Helpers ──

    protected function validateTableReadAccess(string $table): void
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

    protected function validateTableWriteAccess(string $table): void
    {
        $this->validateTableReadAccess($table);
        $beUser = $this->getBackendUser();
        if (null !== $beUser && !$beUser->isAdmin() && !$beUser->check('tables_modify', $table)) {
            throw new \RuntimeException(sprintf('No write access to table "%s".', $table));
        }
    }

    protected function hasTableReadAccess(string $table): bool
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

    protected function hasTableWriteAccess(string $table): bool
    {
        if (!$this->hasTableReadAccess($table)) {
            return false;
        }
        $beUser = $this->getBackendUser();

        return null === $beUser || $beUser->isAdmin() || $beUser->check('tables_modify', $table);
    }

    protected function canAccessField(string $table, string $field): bool
    {
        try {
            if (!$this->tcaCompatibilityService->hasField($table, $field)) {
                return false;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('AbstractTool::canAccessField: TCA lookup failed, denying access', [
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

        // Check exclude flag from raw TCA (field configuration)
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
     * @throws \InvalidArgumentException when fields do not exist in TCA schema
     */
    protected function filterAccessibleFields(string $table, array $fields): array
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
            $validFields = $this->getSchemaFieldNames($table);

            throw new \InvalidArgumentException(sprintf(
                'Unknown field(s) for table "%s": %s. Use getRecordSchema to look up valid field names. Available fields: %s',
                $table,
                implode(', ', $unknownFields),
                implode(', ', $validFields),
            ));
        }

        if ([] !== $deniedFields) {
            throw new \InvalidArgumentException(sprintf(
                'Access denied to field(s) for table "%s": %s.',
                $table,
                implode(', ', $deniedFields),
            ));
        }

        return $filtered;
    }

    protected function fieldExistsInSchema(string $table, string $field): bool
    {
        try {
            return $this->tcaCompatibilityService->hasField($table, $field);
        } catch (\Throwable $e) {
            $this->logger->warning('AbstractTool::fieldExistsInSchema: TCA lookup failed, treating field as missing', [
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
    protected function getSchemaFieldNames(string $table): array
    {
        try {
            return $this->tcaCompatibilityService->getFieldNames($table);
        } catch (\Throwable $e) {
            $this->logger->warning('AbstractTool::getSchemaFieldNames: TCA lookup failed, returning empty list', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function resolveLabel(string $label): string
    {
        if ('' === $label) {
            return '';
        }
        if (str_starts_with($label, 'LLL:')) {
            return $this->localizationService->getLanguageService()->sL($label) ?: $label;
        }

        return $label;
    }

    protected function getTableLabel(string $table): string
    {
        try {
            return $this->resolveLabel($this->tcaCompatibilityService->getTitle($table));
        } catch (\Throwable $e) {
            $this->logger->warning('AbstractTool::getTableLabel: TCA title lookup failed, falling back to raw table name', [
                'table' => $table,
                'error' => $e->getMessage(),
            ]);

            return $table;
        }
    }

    protected function getFieldLabel(string $table, string $field): string
    {
        try {
            return $this->resolveLabel($this->tcaCompatibilityService->getFieldLabel($table, $field));
        } catch (\Throwable $e) {
            $this->logger->warning('AbstractTool::getFieldLabel: TCA field-label lookup failed, falling back to raw field name', [
                'table' => $table,
                'field' => $field,
                'error' => $e->getMessage(),
            ]);
        }

        return $field;
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $this->backendUserService->getBackendUser();
    }

    protected function getContainerRegistry(): ?Registry
    {
        if (!ExtensionManagementUtility::isLoaded('container')) {
            return null;
        }
        if (!class_exists(Registry::class)) {
            return null;
        }

        return GeneralUtility::makeInstance(Registry::class);
    }

    // ── Permission Helpers ──

    /**
     * Verify the BE user has the requested page permission and return the (WSOL-resolved) page row.
     *
     * @param int $perm one of Permission::PAGE_SHOW | PAGE_EDIT | PAGE_NEW | PAGE_DELETE | CONTENT_EDIT
     *
     * @return array<string, mixed>
     *
     * @throws InsufficientPermissionException
     * @throws \RuntimeException               when the page does not exist
     */
    protected function assertPagePerm(int $pageId, int $perm): array
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

        // Root-subpage creation (pid=0) is admin-only.
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
     * Verify the BE user may read the given record. Returns the WSOL-resolved row.
     *
     * @return array<string, mixed>
     *
     * @throws InsufficientPermissionException
     * @throws \RuntimeException               when the record does not exist
     */
    protected function assertRecordReadAccess(string $table, int $uid): array
    {
        return $this->assertRecordAccess($table, $uid, 'read');
    }

    /**
     * Verify the BE user may edit the given record. Returns the WSOL-resolved row.
     *
     * @return array<string, mixed>
     *
     * @throws InsufficientPermissionException
     * @throws \RuntimeException               when the record does not exist
     */
    protected function assertRecordEditAccess(string $table, int $uid): array
    {
        return $this->assertRecordAccess($table, $uid, 'edit');
    }

    /**
     * Verify the BE user may create a record of the given table under the given parent pid.
     *
     * @throws InsufficientPermissionException
     */
    protected function assertRecordCreateAccess(string $table, int $pid): void
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
            // rootLevel tables: tables_modify above is sufficient.
            try {
                if ($this->tcaCompatibilityService->isRootLevel($table)) {
                    return;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AbstractTool::assertRecordCreateAccess: TCA rootLevel check failed, falling through to admin-only', [
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

    /**
     * Verify the BE user may read the given file.
     *
     * @throws InsufficientPermissionException permission denied
     * @throws \RuntimeException               file does not exist or cannot be loaded by ResourceFactory
     */
    protected function assertFileReadAccess(int $fileUid): File
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
     * Verify the BE user may write to the given file.
     *
     * @throws InsufficientPermissionException permission denied
     * @throws \RuntimeException               file does not exist or cannot be loaded by ResourceFactory
     */
    protected function assertFileWriteAccess(int $fileUid): File
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
     * Verify the BE user may read the given folder. Admin bypasses the check but still receives the resolved Folder.
     *
     * @throws InsufficientPermissionException
     */
    protected function assertFolderReadAccess(string $combinedIdentifier): Folder
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
     * Verify the BE user may write to the given folder.
     *
     * @throws InsufficientPermissionException
     */
    protected function assertFolderWriteAccess(string $combinedIdentifier): Folder
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
     * Verify the BE user may use the given language UID.
     *
     * @throws InsufficientPermissionException
     */
    protected function assertLanguageAccess(int $languageUid): void
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
     * Returns the list of page UIDs the BE user may read, scoped to a sub-tree if $rootId > 0.
     * Cached per-request — see class docblock.
     *
     * @return list<int>
     *
     * @throws InsufficientPermissionException when the resulting set exceeds {@see MAX_FILTERABLE_PAGES}
     */
    protected function getReadablePageIds(int $rootId = 0, int $depth = 99): array
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

    /**
     * Resolve an ISO language code (e.g. "de", "en") to a sys_language_uid via Site Configuration.
     * Empty or null input returns 0 (= site default language). Matching is case-insensitive.
     */
    protected function resolveLanguageUid(?string $isoCode, int $pageId): int
    {
        if (null === $isoCode || '' === $isoCode) {
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
            $this->logger->warning('AbstractTool::resolveLanguageUid: site lookup failed, falling back to default language (0)', [
                'pageId' => $pageId,
                'isoCode' => $isoCode,
                'error' => $e->getMessage(),
            ]);
        }

        return 0;
    }

    /**
     * @param list<int|string> $arguments
     */
    protected function translateOrFallback(string $key, array $arguments, string $fallback): string
    {
        $translated = $this->translate($key, $arguments);

        return '' !== $translated ? $translated : $fallback;
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
        // pid === 0 → root-level record: tables_select/tables_modify above is sufficient.

        if ('edit' === $op) {
            $result = $beUser->checkRecordEditAccess($table, $record, false, true);
            if (!$result->isAllowed) {
                $message = '' !== $result->errorMessage
                    ? $result->errorMessage
                    : $this->translateOrFallback('hint.no_record_access', [$table, $uid], sprintf('No edit permission on record %s:%d.', $table, $uid));

                throw new InsufficientPermissionException($message);
            }
        }

        // File-bound tables: also enforce filemount on the referenced sys_file.
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
     * Lenient parameter name matching: maps snake_case and kebab-case variants
     * to the canonical camelCase names defined in the schema.
     * E.g. "page_id" → "pageId", "file-uid" → "fileUid".
     *
     * @param array<string, mixed> $params    Raw parameters from the client
     * @param list<string>         $knownKeys Schema property names (camelCase)
     *
     * @return array<string, mixed> Parameters with normalized keys
     */
    private function normalizeParameterNames(array $params, array $knownKeys): array
    {
        // Build a lookup: lowercase-no-separators → canonical key
        $lookup = [];
        foreach ($knownKeys as $canonical) {
            $lookup[strtolower($canonical)] = $canonical;
        }

        $normalized = [];
        foreach ($params as $key => $value) {
            // Already a known key — use as-is
            if (isset($lookup[strtolower($key)])) {
                $normalized[$lookup[strtolower($key)]] = $value;

                continue;
            }

            // Try stripping underscores/hyphens: "page_id" → "pageid" → match "pageId"
            $stripped = strtolower(str_replace(['-', '_'], '', $key));
            if (isset($lookup[$stripped])) {
                $normalized[$lookup[$stripped]] = $value;

                continue;
            }

            // No match — pass through (will be stripped in validation step)
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    // ── Parameter Validation ──

    /**
     * @param array<string, mixed> $schema
     */
    private function validateInteger(string $key, mixed $value, array $schema): int
    {
        if (!is_numeric($value)) {
            throw new InvalidParameterException(
                sprintf('%s must be an integer, got: %s', $key, get_debug_type($value)),
            );
        }

        $value = (int) $value;

        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            throw new InvalidParameterException(
                sprintf('%s must be >= %d, got: %d', $key, $schema['minimum'], $value),
            );
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            throw new InvalidParameterException(
                sprintf('%s must be <= %d, got: %d', $key, $schema['maximum'], $value),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function validateString(string $key, mixed $value, array $schema): string
    {
        $value = (string) $value;

        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new InvalidParameterException(
                sprintf('%s must be one of: %s', $key, implode(', ', $schema['enum'])),
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function validateArray(string $key, mixed $value, array $schema): array
    {
        if (!is_array($value)) {
            throw new InvalidParameterException(
                sprintf('%s must be an array', $key),
            );
        }

        if (isset($schema['items']['enum'])) {
            foreach ($value as $item) {
                if (!in_array($item, $schema['items']['enum'], true)) {
                    throw new InvalidParameterException(
                        sprintf('%s items must be one of: %s', $key, implode(', ', $schema['items']['enum'])),
                    );
                }
            }
        }

        return $value;
    }
}
