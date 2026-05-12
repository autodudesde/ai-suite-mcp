<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientScopeException;

/**
 * Validates MCP tool access by checking both OAuth scopes and TYPO3 permissions.
 *
 * Scope → TYPO3 Permission mapping:
 *   mcp:read       → (base permission, always granted if scope present)
 *   mcp:write      → (no TYPO3 permission required; preview+confirm enforced per tool)
 *   mcp:generate   → enable_metadata_generation, enable_content_element_generation,
 *                     enable_pages_generation, enable_news_generation
 *   mcp:translate  → enable_translation, enable_translation_whole_page
 *   mcp:image      → enable_image_generation
 *   mcp:workflow   → enable_massaction_generation, enable_background_task_handling
 *   mcp:glossary   → enable_translation, enable_translation_deepl_sync
 *   mcp:easy-language → enable_rte_aieasylanguageplugin
 *   mcp:manage     → enable_mcp_access
 */
class McpPermissionService
{
    /**
     * Maps tool names to their required OAuth scope.
     * Tools not listed here default to 'mcp:read'.
     *
     * @var array<string, string>
     */
    private const TOOL_SCOPE_MAP = [
        // Context tools
        'getPageTree' => 'mcp:read',
        'getPageContent' => 'mcp:read',
        'searchContent' => 'mcp:read',
        'getFileInfo' => 'mcp:read',
        'listFiles' => 'mcp:read',
        'findStaleContent' => 'mcp:read',
        'auditContent' => 'mcp:read',

        // Record tools
        'getTables' => 'mcp:read',
        'getRecordSchema' => 'mcp:read',
        'getPageTypes' => 'mcp:read',
        'getContentTypes' => 'mcp:read',
        'getColumnPositions' => 'mcp:read',

        // Record tools — CRUD (no AI, just DataHandler)
        'previewRecords' => 'mcp:write',
        'writeRecords' => 'mcp:write',
        'readRecords' => 'mcp:read',
        'deleteRecords' => 'mcp:write',
        'savePageTree' => 'mcp:write',

        // Generate tools
        'generateMetadata' => 'mcp:generate',
        'generateFileMetadata' => 'mcp:generate',
        'generateContent' => 'mcp:generate',
        'generatePageTree' => 'mcp:generate',
        'generateLandingPage' => 'mcp:generate',

        // Translation tools
        'translatePage' => 'mcp:translate',
        'translateRecord' => 'mcp:translate',
        'translateFileMetadata' => 'mcp:translate',

        // Image tools
        'generateImage' => 'mcp:image',

        // Content optimization
        'optimizeContent' => 'mcp:generate',

        // Batch tools (page/folder-wide async operations — gated by enable_massaction_generation)
        'batchGenerateMetadata' => 'mcp:workflow',
        'batchGenerateFileMetadata' => 'mcp:workflow',
        'batchGenerateFolderMetadata' => 'mcp:workflow',
        'batchTranslatePage' => 'mcp:workflow',
        'batchTranslateFileMetadata' => 'mcp:workflow',
        'batchTranslateFolderMetadata' => 'mcp:workflow',
        'getTaskStatus' => 'mcp:read',

        // Glossary
        'syncGlossary' => 'mcp:glossary',
        'listGlossary' => 'mcp:read',

        // Management
        'manageGlobalInstructions' => 'mcp:manage',
        'managePromptTemplates' => 'mcp:manage',
        'manageBackgroundTasks' => 'mcp:workflow',
    ];

    /**
     * Maps scopes to TYPO3 permission keys. A scope may require ANY of the listed permissions.
     *
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
        'mcp:workflow' => [
            'tx_aisuite_features:enable_massaction_generation',
        ],
        'mcp:glossary' => [
            'tx_aisuite_features:enable_translation',
            'tx_aisuite_features:enable_translation_deepl_sync',
        ],
        'mcp:easy-language' => [
            'tx_aisuite_features:enable_rte_aieasylanguageplugin',
        ],
        'mcp:manage' => [
            'tx_aisuite_features:enable_mcp_access',
        ],
    ];

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly LocalizationService $localizationService,
    ) {}

    /**
     * Validate that the current user has both the required OAuth scope
     * and the matching TYPO3 permission to use a tool.
     *
     * @param string       $toolName    Tool name to check
     * @param list<string> $tokenScopes Scopes from the OAuth token
     *
     * @throws InsufficientScopeException      If the token lacks the required scope
     * @throws InsufficientPermissionException If the user lacks the TYPO3 permission
     */
    public function validateToolAccess(string $toolName, array $tokenScopes): void
    {
        $requiredScope = $this->getRequiredScope($toolName);

        if (null !== $requiredScope && !in_array($requiredScope, $tokenScopes, true)) {
            throw new InsufficientScopeException(
                $this->translate('hint.scope_required', [$requiredScope])
                    ?? sprintf('To use this feature, your API token needs the "%s" scope.', $requiredScope),
            );
        }

        if (null !== $requiredScope) {
            $this->validatePermissionForScope($requiredScope);
        }
    }

    /**
     * Get the required scope for a tool.
     */
    public function getRequiredScope(string $toolName): ?string
    {
        return self::TOOL_SCOPE_MAP[$toolName] ?? 'mcp:read';
    }

    /**
     * Validate that the user has permission to use a specific AI model.
     */
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
     * Get all scopes the current backend user is eligible for.
     *
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
     * Validate that the user has a TYPO3 permission matching the scope.
     */
    private function validatePermissionForScope(string $scope): void
    {
        $requiredPermissions = self::SCOPE_PERMISSION_MAP[$scope] ?? [];

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
