<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientScopeException;

/**
 * Scope → TYPO3 Permission mapping:
 *   mcp:read       → (base permission, always granted if scope present)
 *   mcp:write      → (no TYPO3 permission required; preview+confirm enforced per tool)
 *   mcp:generate   → enable_metadata_generation, enable_content_element_generation,
 *                     enable_pages_generation, enable_news_generation
 *   mcp:translate  → enable_translation, enable_translation_whole_page
 *   mcp:image      → enable_image_generation
 *   mcp:workflow   → enable_massaction_generation, enable_background_task_handling
 *
 * On top of the scope layer, a single tool can require its own flag (TOOL_PERMISSION_MAP):
 *
 *   readRenderedPage → enable_mcp_rendered_page_read
 */
class PermissionService
{
    /**
     * @var array<string, string>
     */
    private const TOOL_SCOPE_MAP = [
        // Context tools
        'readServerInfo' => 'mcp:read',
        'readPageTree' => 'mcp:read',
        'readRenderedPage' => 'mcp:read',
        'readEditorialGuidelines' => 'mcp:read',
        'readPageContent' => 'mcp:read',
        'searchContent' => 'mcp:read',
        'readFileInfo' => 'mcp:read',
        'listFiles' => 'mcp:read',
        'listStaleContent' => 'mcp:read',

        // Record tools
        'listTables' => 'mcp:read',
        'readRecordSchema' => 'mcp:read',
        'listPageTypes' => 'mcp:read',
        'listContentTypes' => 'mcp:read',
        'readChildren' => 'mcp:read',
        'readFlexFormSchema' => 'mcp:read',
        'readContentTree' => 'mcp:read',
        'previewRecords' => 'mcp:write',
        'writeRecords' => 'mcp:write',
        'replaceText' => 'mcp:write',
        'patchText' => 'mcp:write',
        'bulkReplaceText' => 'mcp:write',
        'readRecords' => 'mcp:read',
        'compareWithLive' => 'mcp:read',
        'deleteRecords' => 'mcp:write',
        'copyRecords' => 'mcp:write',
        'moveRecords' => 'mcp:write',
        'localizeRecord' => 'mcp:write',
        'savePageTree' => 'mcp:write',

        // Generate tools
        'generateFileMetadata' => 'mcp:generate',

        // Translation tools
        'translatePage' => 'mcp:translate',
        'translateRecord' => 'mcp:translate',
        'translateFileMetadata' => 'mcp:translate',

        // Image tools
        'generateImage' => 'mcp:image',

        // Media tools (upload existing media to FAL)
        'uploadMedia' => 'mcp:media',
        'copyMediaReference' => 'mcp:write',
        'replaceMediaReference' => 'mcp:write',

        // Batch tools (page/folder-wide async operations)
        'batchGenerateMetadata' => 'mcp:workflow',
        'batchGenerateFileMetadata' => 'mcp:workflow',
        'batchGenerateFolderMetadata' => 'mcp:workflow',
        'batchTranslatePage' => 'mcp:workflow',
        'batchTranslateFileMetadata' => 'mcp:workflow',
        'batchTranslateFolderMetadata' => 'mcp:workflow',
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
     * Backend-group flags a single tool needs on top of its scope.
     *
     * An opt-in allowlist, not a completeness map: a tool that is absent needs no extra flag.
     * `readRenderedPage` sits in the flag-free `mcp:read` scope but is far more powerful than the
     * other read tools, because it renders through a backend preview session of the MCP user and so
     * also returns hidden pages, unpublished pages and workspace drafts. Gating the whole `mcp:read`
     * scope instead would revoke every read tool and change which scopes OAuth grants.
     *
     * @var array<string, list<string>>
     */
    private const TOOL_PERMISSION_MAP = [
        'readRenderedPage' => [
            'tx_aisuite_features:enable_mcp_rendered_page_read',
        ],
    ];

    /**
     * The tools whose only purpose is moving content between languages.
     *
     * @var list<string>
     */
    private const TRANSLATION_TOOLS = [
        'translatePage',
        'translateRecord',
        'translateFileMetadata',
        'localizeRecord',
        'batchTranslatePage',
        'batchTranslateFileMetadata',
        'batchTranslateFolderMetadata',
    ];

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly LocalizationService $localizationService,
        private readonly SiteLanguageService $siteLanguages,
    ) {}

    /**
     * @param string       $toolName    Tool name to check
     * @param list<string> $tokenScopes Scopes from the OAuth token
     *
     * @throws InsufficientScopeException      If the token lacks the required scope
     * @throws InsufficientPermissionException If the user lacks the TYPO3 permission
     */
    public function validateToolAccess(string $toolName, array $tokenScopes): void
    {
        $requiredScope = $this->getRequiredScope($toolName);

        if (!in_array($requiredScope, $tokenScopes, true)) {
            throw new InsufficientScopeException(
                $this->translate('hint.scope_required', [$requiredScope])
                    ?? sprintf('To use this feature, your API token needs the "%s" scope.', $requiredScope),
            );
        }

        $this->validatePermissionForScope($requiredScope);
        $this->validatePermissionForTool($toolName);
    }

    /**
     * Whether the user may both see and call the tool. Single source of truth for the tools/list
     * filter and for ChEddi's catalogue, so a tool can never be listed but rejected on call.
     *
     * @param list<string> $tokenScopes Scopes from the OAuth token
     */
    public function isToolAvailable(string $toolName, array $tokenScopes): bool
    {
        if ($this->isPointlessOnThisInstallation($toolName)) {
            return false;
        }

        try {
            $this->validateToolAccess($toolName, $tokenScopes);

            return true;
        } catch (InsufficientPermissionException|InsufficientScopeException|\LogicException) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function getRequiredPermissions(string $toolName): array
    {
        return self::TOOL_PERMISSION_MAP[$toolName] ?? [];
    }

    /**
     * Fail-closed: a tool that is missing from the map is a programming error, not a read-only tool.
     *
     * The previous `?? 'mcp:read'` default silently granted an unmapped tool the weakest scope —
     * which is how `getTaskResults` (a DataHandler write behind an `apply` flag) ended up callable
     * with a read-only token. `ToolScopeMapCompletenessTest` keeps the map exhaustive so this can
     * never throw at runtime.
     *
     * @throws \LogicException If the tool has no explicit scope entry
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
                $this->translate('hint.model_not_available', [$modelIdentifier, ''])
                    ?? sprintf('The AI model "%s" is not available for your user group.', $modelIdentifier),
            );
        }
    }

    /**
     * @return list<string>
     */
    public function getAvailableScopes(): array
    {
        $available = [];

        foreach (self::SCOPE_PERMISSION_MAP as $scope => $permissions) {
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
     * Check the backend-group permissions of a scope the tool needs *in addition* to its own.
     *
     * A tool has exactly one gating scope, but the batch tools genuinely do two things: they are
     * mass actions (`mcp:workflow`) *and* they spend AI credits on generation or translation.
     * Gating them on `mcp:workflow` alone never checked `enable_translation` /
     * `enable_*_generation`, so a workflow-scoped token could run a batch translation without the
     * translation permission.
     *
     * @throws InsufficientPermissionException If the user lacks every permission of that scope
     */
    public function validateFeatureScope(string $scope): void
    {
        $this->validatePermissionForScope($scope);
    }

    /**
     * Tools that cannot do anything useful here, regardless of permissions.
     *
     * On a single-language installation the seven translation tools have no target to translate into:
     * every call ends at "Language X is not configured for this site". Listing them anyway costs
     * seven tool schemas of context on every single turn and invites the model to try.
     *
     * Not folded into validateToolAccess(): this is not a permission decision, and the per-call
     * failure is already correct and well-worded. Hiding is the whole benefit.
     */
    private function isPointlessOnThisInstallation(string $toolName): bool
    {
        if (!in_array($toolName, self::TRANSLATION_TOOLS, true)) {
            return false;
        }

        // Fails open — see SiteLanguageService::isSingleLanguageInstallation().
        return $this->siteLanguages->isSingleLanguageInstallation();
    }

    private function validatePermissionForScope(string $scope): void
    {
        $this->assertAnyPermission(self::SCOPE_PERMISSION_MAP[$scope] ?? []);
    }

    private function validatePermissionForTool(string $toolName): void
    {
        $this->assertAnyPermission(self::TOOL_PERMISSION_MAP[$toolName] ?? []);
    }

    /**
     * @param list<string> $requiredPermissions Any one of them suffices; an empty list is no gate
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
            $this->translate('hint.permission_required', [implode(', ', $requiredPermissions)])
                ?? sprintf(
                    'Your user group needs the permission "%s" to use this tool.',
                    implode('" or "', $requiredPermissions),
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
}
