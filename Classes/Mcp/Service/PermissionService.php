<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientScopeException;

class PermissionService
{
    /**
     * @var array<string, string>
     */
    private const TOOL_SCOPE_MAP = [
        'readServerInfo' => 'mcp:read',
        'readPageTree' => 'mcp:read',
        'readRenderedPage' => 'mcp:read',
        'readEditorialGuidelines' => 'mcp:read',
        'readPageContent' => 'mcp:read',
        'searchContent' => 'mcp:read',
        'readFileInfo' => 'mcp:read',
        'listFiles' => 'mcp:read',
        'listStaleContent' => 'mcp:read',

        'listTables' => 'mcp:read',
        'readRecordSchema' => 'mcp:read',
        'listPageTypes' => 'mcp:read',
        'listContentTypes' => 'mcp:read',
        'readChildren' => 'mcp:read',
        'readFlexFormSchema' => 'mcp:read',
        'readContentTree' => 'mcp:read',
        'previewRecords' => 'mcp:write',
        'writeRecords' => 'mcp:write',
        'patchText' => 'mcp:write',
        'bulkReplaceText' => 'mcp:write',
        'readRecords' => 'mcp:read',
        'compareWithLive' => 'mcp:read',
        'deleteRecords' => 'mcp:write',
        'copyRecords' => 'mcp:write',
        'moveRecords' => 'mcp:write',
        'localizeRecord' => 'mcp:write',
        'savePageTree' => 'mcp:write',

        'generateFileMetadata' => 'mcp:generate',

        'translatePage' => 'mcp:translate',
        'translateRecord' => 'mcp:translate',
        'translateFileMetadata' => 'mcp:translate',

        'generateImage' => 'mcp:image',

        'uploadMedia' => 'mcp:media',
        'moveFile' => 'mcp:media',
        'copyMediaReference' => 'mcp:write',
        'replaceMediaReference' => 'mcp:write',

        'auditSeo' => 'mcp:read',
        'auditAccessibility' => 'mcp:read',
        'auditContent' => 'mcp:read',
        'readAuditResults' => 'mcp:read',
        'batchGenerateMetadata' => 'mcp:workflow',
        'batchGenerateFileMetadata' => 'mcp:workflow',
        'batchTranslatePage' => 'mcp:workflow',
        'batchTranslateFileMetadata' => 'mcp:workflow',
        'readTaskStatus' => 'mcp:read',
        'readTaskResults' => 'mcp:read',
        'applyTaskResults' => 'mcp:write',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const SCOPE_PERMISSION_MAP = [
        'mcp:read' => [],
        'mcp:write' => [],
        'mcp:generate' => [
            'tx_aisuite_features:enable_metadata_generation',
            'tx_aisuite_features:enable_content_element_generation',
            'tx_aisuite_features:enable_pages_generation',
        ],
        'mcp:translate' => [
            'tx_aisuite_features:enable_translation',
        ],
        'mcp:image' => [
            'tx_aisuite_features:enable_image_generation',
        ],
        'mcp:media' => [
            'tx_aisuite_features:enable_mcp_media_upload',
        ],
        'mcp:workflow' => [
            'tx_aisuite_features:enable_massaction_generation',
        ],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const TOOL_PERMISSION_MAP = [
        'readRenderedPage' => [
            'tx_aisuite_features:enable_mcp_rendered_page_read',
        ],
        'auditSeo' => [
            'tx_aisuite_features:enable_audit',
        ],
        'auditAccessibility' => [
            'tx_aisuite_features:enable_audit',
        ],
        'auditContent' => [
            'tx_aisuite_features:enable_audit',
        ],
        'generateFileMetadata' => [
            'tx_aisuite_features:enable_metadata_generation',
        ],
        'batchGenerateMetadata' => [
            'tx_aisuite_features:enable_metadata_generation',
        ],
        'batchGenerateFileMetadata' => [
            'tx_aisuite_features:enable_metadata_generation',
        ],
        'translatePage' => [
            'tx_aisuite_features:enable_translation_whole_page',
        ],
        'batchTranslatePage' => [
            'tx_aisuite_features:enable_translation_whole_page',
        ],
        'translateFileMetadata' => [
            'tx_aisuite_features:enable_translation_sys_file_metadata',
        ],
        'batchTranslateFileMetadata' => [
            'tx_aisuite_features:enable_translation_sys_file_metadata',
        ],
    ];

    /**
     * @var list<string>
     */
    private const TRANSLATION_TOOLS = [
        'translatePage',
        'translateRecord',
        'translateFileMetadata',
        'localizeRecord',
        'batchTranslatePage',
        'batchTranslateFileMetadata',
    ];

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly LocalizationService $localizationService,
        private readonly SiteLanguageService $siteLanguages,
    ) {}

    /**
     * @return list<string>
     */
    public function supportedScopes(): array
    {
        return array_keys(self::SCOPE_PERMISSION_MAP);
    }

    /**
     * @param list<string> $tokenScopes
     *
     * @throws InsufficientScopeException
     * @throws InsufficientPermissionException
     */
    public function validateToolAccess(string $toolName, array $tokenScopes, ?ToolPermissionPolicy $policy = null): void
    {
        $requiredScope = $this->getRequiredScope($toolName);

        if (!in_array($requiredScope, $tokenScopes, true)) {
            throw $this->scopeRequired($requiredScope);
        }

        $this->validatePermissionForScope($requiredScope, $policy);
        $this->validatePermissionForTool($toolName, $policy);
    }

    /**
     * @param list<string> $tokenScopes
     */
    public function isToolAvailable(string $toolName, array $tokenScopes, ?ToolPermissionPolicy $policy = null): bool
    {
        if ($this->isPointlessOnThisInstallation($toolName)) {
            return false;
        }

        try {
            $this->validateToolAccess($toolName, $tokenScopes, $policy);

            return true;
        } catch (InsufficientPermissionException|InsufficientScopeException|\LogicException) {
            return false;
        }
    }

    /**
     * @throws \LogicException
     */
    public function getRequiredScope(string $toolName): string
    {
        return self::TOOL_SCOPE_MAP[$toolName]
            ?? throw new \LogicException(sprintf('Tool "%s" has no entry in TOOL_SCOPE_MAP.', $toolName));
    }

    public function validateModelAccess(string $modelIdentifier): void
    {
        $permission = 'tx_aisuite_models:'.$modelIdentifier;
        if (!$this->backendUserService->checkPermissions($permission)) {
            throw new InsufficientPermissionException(
                $this->translateOrFallback(
                    'hint.model_not_available',
                    [$modelIdentifier, ''],
                    sprintf('The AI model "%s" is not available for your user group.', $modelIdentifier),
                ),
            );
        }
    }

    /**
     * @return list<string>
     */
    public function getAvailableScopes(?ToolPermissionPolicy $policy = null): array
    {
        $available = [];

        foreach ($this->supportedScopes() as $scope) {
            $permissions = $this->scopePermissions($scope, $policy);
            if (null === $permissions) {
                continue;
            }
            if (empty($permissions)) {
                $available[] = $scope;

                continue;
            }

            foreach ($permissions as $permission) {
                if ($this->backendUserService->checkPermissions($permission)) {
                    $available[] = $scope;

                    break;
                }
            }
        }

        return $available;
    }

    /**
     * @param list<string> $tokenScopes
     */
    public function isScopeGranted(string $scope, array $tokenScopes): bool
    {
        if (!in_array($scope, $tokenScopes, true)) {
            return false;
        }

        $requiredPermissions = self::SCOPE_PERMISSION_MAP[$scope] ?? [];
        if (empty($requiredPermissions)) {
            return true;
        }

        foreach ($requiredPermissions as $permission) {
            if ($this->backendUserService->checkPermissions($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InsufficientPermissionException
     */
    public function validateFeatureScope(string $scope): void
    {
        $this->validatePermissionForScope($scope);
    }

    private function isPointlessOnThisInstallation(string $toolName): bool
    {
        if (!in_array($toolName, self::TRANSLATION_TOOLS, true)) {
            return false;
        }

        // Fails open — see SiteLanguageService::isSingleLanguageInstallation().
        return $this->siteLanguages->isSingleLanguageInstallation();
    }

    /**
     * @return null|list<string>
     */
    private function scopePermissions(string $scope, ?ToolPermissionPolicy $policy): ?array
    {
        return null !== $policy ? $policy->scopePermissions($scope) : (self::SCOPE_PERMISSION_MAP[$scope] ?? []);
    }

    private function validatePermissionForScope(string $scope, ?ToolPermissionPolicy $policy = null): void
    {
        $permissions = $this->scopePermissions($scope, $policy);
        if (null === $permissions) {
            throw $this->scopeRequired($scope);
        }

        $this->assertAnyPermission($permissions);
    }

    private function validatePermissionForTool(string $toolName, ?ToolPermissionPolicy $policy): void
    {
        $this->assertAnyPermission(null !== $policy ? $policy->toolPermissions($toolName) : (self::TOOL_PERMISSION_MAP[$toolName] ?? []));
    }

    private function scopeRequired(string $scope): InsufficientScopeException
    {
        return new InsufficientScopeException(
            $this->translateOrFallback(
                'hint.scope_required',
                [$scope],
                sprintf('To use this feature, your API token needs the "%s" scope.', $scope),
            ),
        );
    }

    /**
     * @param list<string> $requiredPermissions
     *
     * @throws InsufficientPermissionException
     */
    private function assertAnyPermission(array $requiredPermissions): void
    {
        if (empty($requiredPermissions)) {
            return;
        }

        foreach ($requiredPermissions as $permission) {
            if ($this->backendUserService->checkPermissions($permission)) {
                return;
            }
        }

        throw new InsufficientPermissionException(
            $this->translateOrFallback(
                'hint.permission_required',
                [implode(', ', $requiredPermissions)],
                sprintf(
                    'Your user group needs the permission "%s" to use this tool.',
                    implode('" or "', $requiredPermissions),
                ),
            ),
        );
    }

    /**
     * @param list<mixed> $arguments
     */
    private function translate(string $key, array $arguments = []): string
    {
        return $this->localizationService->translate('mcp:'.$key, $arguments);
    }

    /**
     * @param list<mixed> $arguments
     */
    private function translateOrFallback(string $key, array $arguments, string $fallback): string
    {
        $translated = $this->translate($key, $arguments);

        return '' !== $translated ? $translated : $fallback;
    }
}
