<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Server\Server;
use Mcp\Types\CallToolResult;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

#[AutoconfigureTag('aisuite.mcp.tool')]
class GetServerInfoTool extends AbstractTool
{
    protected ?string $requiredScope = null;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly Typo3Version $typo3Version,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getServerInfo';
    }

    public function getDescription(): string
    {
        return 'Get AI Suite MCP server status: version, configuration, available sites, and diagnostics.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $beUser = $this->getBackendUser();
        $isAdmin = null !== $beUser && $beUser->isAdmin();
        $mcpVersion = ExtensionManagementUtility::getExtensionVersion('ai_suite_mcp') ?: 'unknown';
        $aiSuiteVersion = ExtensionManagementUtility::getExtensionVersion('ai_suite') ?: 'unknown';

        $lines = [];

        $lines[] = '## AI Suite MCP Server';
        $lines[] = sprintf('- **Version:** %s', $mcpVersion);
        if ($isAdmin) {
            $lines[] = sprintf('- **AI Suite core:** %s', $aiSuiteVersion);
            $lines[] = sprintf('- **PHP:** %s', PHP_VERSION);
            $lines[] = sprintf('- **TYPO3:** %s', $this->typo3Version->getVersion());
        }

        $issuedVersion = $this->userContext->getIssuedVersion();
        if ('' !== $issuedVersion && 'unknown' !== $mcpVersion && version_compare($mcpVersion, $issuedVersion, '>')) {
            $lines[] = sprintf(
                '- **⚠ Server updated:** your token was issued under v%s, the server now runs v%s. '
                .'Reconnect / obtain a new token to pick up new tools, scopes and behaviour.',
                $issuedVersion,
                $mcpVersion,
            );
        }
        $lines[] = '';

        $extConf = $isAdmin ? (array) $this->extensionConfiguration->get('ai_suite_mcp') : [];
        $mcpEnabled = (bool) ($extConf['enableMcp'] ?? false);
        $sdkInstalled = class_exists(Server::class);

        if ($isAdmin) {
            $lines[] = '## Configuration';
            $lines[] = sprintf('- **MCP enabled:** %s', $mcpEnabled ? 'yes' : 'no');
            $lines[] = sprintf('- **Write mode:** %s', (string) ($extConf['mcpWriteMode'] ?? 'auto'));
            $lines[] = sprintf('- **Session timeout:** %ds', (int) ($extConf['mcpSessionTimeoutSeconds'] ?? 1800));
            $lines[] = sprintf('- **Workspace extension:** %s', ExtensionManagementUtility::isLoaded('workspaces') ? 'installed' : 'not installed');
            $lines[] = '';

            $lines[] = '## Dependencies';
            $lines[] = sprintf('- **MCP SDK (logiscape/mcp-sdk-php):** %s', $sdkInstalled ? 'installed' : 'MISSING');
            $lines[] = '';
        }
        $lines[] = '## Sites';
        $sites = $this->getAccessibleSites($beUser, $isAdmin);
        if (empty($sites)) {
            $lines[] = '- No sites configured.';
        } else {
            foreach ($sites as $site) {
                $languages = [];
                foreach ($site->getAllLanguages() as $lang) {
                    $languages[] = sprintf('%s (%s)', $lang->getTitle(), $lang->getLocale()->getLanguageCode());
                }
                $lines[] = sprintf(
                    '- **%s** (root: %d) — Languages: %s',
                    $site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier(),
                    $site->getRootPageId(),
                    implode(', ', $languages),
                );
            }
        }

        if ($isAdmin) {
            $lines[] = '';

            $sessionPath = Environment::getVarPath().'/aisuite_mcp_sessions/';
            $sessionWritable = is_dir($sessionPath) && is_writable($sessionPath);
            $lines[] = '## Diagnostics';
            $lines[] = sprintf('- **Session storage:** %s', $sessionWritable ? 'writable' : 'NOT writable — sessions may fail');
            $lines[] = sprintf('- **Environment:** %s', (string) Environment::getContext());

            $warnings = [];
            if (!$mcpEnabled) {
                $warnings[] = 'MCP is disabled in extension configuration.';
            }
            if (!$sdkInstalled) {
                $warnings[] = 'MCP SDK is not installed. Run: composer require logiscape/mcp-sdk-php';
            }
            if (!$sessionWritable) {
                $warnings[] = sprintf('Session directory is not writable: %s', $sessionPath);
            }

            if (!empty($warnings)) {
                $lines[] = '';
                $lines[] = '## Warnings';
                foreach ($warnings as $warning) {
                    $lines[] = sprintf('- %s', $warning);
                }
            }
        }

        return $this->textResult(implode("\n", $lines));
    }

    /**
     * @return array<string, Site>
     */
    private function getAccessibleSites(?BackendUserAuthentication $beUser, bool $isAdmin): array
    {
        $sites = $this->siteFinder->getAllSites();
        if ($isAdmin || null === $beUser) {
            return $sites;
        }

        $accessible = [];
        foreach ($sites as $identifier => $site) {
            try {
                if ($beUser->isInWebMount($site->getRootPageId())) {
                    $accessible[$identifier] = $site;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('GetServerInfoTool: webmount check failed for site, skipping (root page may be deleted)', [
                    'siteIdentifier' => $identifier,
                    'rootPageId' => $site->getRootPageId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $accessible;
    }
}
