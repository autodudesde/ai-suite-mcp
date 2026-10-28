<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\BackendUserService;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

class McpCliAccessService
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly OAuthService $oauthService,
        private readonly BackendUserService $backendUserService,
        private readonly PermissionService $permissionService,
    ) {}

    public function refusal(int $beUserUid): ?string
    {
        if (!$this->isMcpEnabled()) {
            return 'MCP is switched off (ext_conf enableMcp).';
        }
        if (!$this->oauthService->isBackendUserActive($beUserUid)) {
            return sprintf('Backend user %d is disabled, deleted or outside its start/end time.', $beUserUid);
        }
        if (!$this->backendUserService->checkPermissions('tx_aisuite_features:enable_mcp_access')) {
            return sprintf('MCP access is not enabled for backend user %d (tx_aisuite_features:enable_mcp_access).', $beUserUid);
        }

        return null;
    }

    /**
     * @return array{granted: list<string>, dropped: list<string>}
     */
    public function scopes(?string $requested): array
    {
        $available = $this->permissionService->getAvailableScopes();
        if (null === $requested || '' === $requested) {
            return ['granted' => $available, 'dropped' => []];
        }

        $asked = array_values(array_unique(array_filter(explode(' ', $requested))));

        return [
            'granted' => array_values(array_intersect($asked, $available)),
            'dropped' => array_values(array_diff($asked, $available)),
        ];
    }

    private function isMcpEnabled(): bool
    {
        try {
            return (bool) ($this->extensionConfiguration->get('ai_suite_mcp')['enableMcp'] ?? false);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return false;
        }
    }
}
