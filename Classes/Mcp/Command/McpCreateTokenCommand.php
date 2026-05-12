<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Command;

use AutoDudes\AiSuiteMcp\Mcp\McpPermissionService;
use AutoDudes\AiSuiteMcp\Mcp\OAuth\CanonicalResource;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Console command: ai-suite-mcp:create-token.
 *
 * Creates an MCP access token for testing (e.g. with MCP Inspector).
 * Bypasses the full OAuth flow — for development use only.
 *
 * Usage:
 *   vendor/bin/typo3 ai-suite-mcp:create-token --user=1
 *   vendor/bin/typo3 ai-suite-mcp:create-token --user=admin --scopes="mcp:read mcp:write mcp:generate"
 */
class McpCreateTokenCommand extends Command
{
    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly McpPermissionService $permissionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create an MCP access token for testing (e.g. MCP Inspector)')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Backend user UID or username', '1')
            ->addOption('scopes', 's', InputOption::VALUE_OPTIONAL, 'Space-separated scopes (default: all available for user)')
            ->addOption('client', 'c', InputOption::VALUE_OPTIONAL, 'Client ID', 'mcp-inspector')
            ->addOption('workspace', 'w', InputOption::VALUE_OPTIONAL, 'Workspace UID to bind this token to (0 = user default; ignored when ext:workspaces is not loaded)', '0')
            ->addOption('audience', 'a', InputOption::VALUE_OPTIONAL, 'Canonical resource URI to bind the token to (RFC 8707). Default: derived from TYPO3_REQUEST_HOST. Set this explicitly when running over CLI on a host different from the public URL.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userInput = (string) $input->getOption('user');
        $clientId = (string) $input->getOption('client');

        // Resolve user (UID or username) via the BE_USER auth API — applies user
        // enable-fields (deleted/disable/starttime/endtime) natively. Initializes BE_USER
        // in the same step so the workspace check below works (workspace_perms is only
        // populated after fetchGroupData()).
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        if (ctype_digit($userInput)) {
            $backendUser->setBeUserByUid((int) $userInput);
        } else {
            $backendUser->setBeUserByName($userInput);
        }
        if (empty($backendUser->user)) {
            $io->error(sprintf('Backend user "%s" not found or disabled.', $userInput));

            return Command::FAILURE;
        }
        $beUserUid = (int) $backendUser->user['uid'];
        $backendUser->fetchGroupData();
        $GLOBALS['BE_USER'] = $backendUser;

        // Resolve --workspace flag.
        // ext:workspaces not loaded + --workspace > 0 → silent fallback with warning (consistent with
        // the consent-form behaviour where the dropdown is hidden in that case).
        $workspaceUid = (int) $input->getOption('workspace');
        if ($workspaceUid > 0 && !ExtensionManagementUtility::isLoaded('workspaces')) {
            $output->writeln('<warning>Workspace flag ignored — ext:workspaces is not loaded; token created with workspace_uid=0.</warning>');
            $workspaceUid = 0;
        }
        if ($workspaceUid > 0 && false === $backendUser->checkWorkspace($workspaceUid)) {
            $io->error(sprintf('Backend user "%s" has no access to workspace %d.', $userInput, $workspaceUid));

            return Command::FAILURE;
        }

        // Determine scopes
        $scopeInput = $input->getOption('scopes');
        if (null !== $scopeInput && '' !== $scopeInput) {
            $scopes = array_filter(explode(' ', (string) $scopeInput));
        } else {
            $scopes = $this->permissionService->getAvailableScopes();
        }

        if (empty($scopes)) {
            $io->error('No scopes available for this user. Check user group permissions.');

            return Command::FAILURE;
        }

        // Resolve audience for RFC 8707 binding. CLI may not have a real request host,
        // so we let the user override with --audience.
        $audienceInput = (string) ($input->getOption('audience') ?? '');
        $audience = '' !== $audienceInput ? $audienceInput : CanonicalResource::get();
        // CLI without TYPO3_REQUEST_HOST yields a path-only string like "/aisuite-mcp" — that
        // would never match validateToken() at request time. Reject early with a useful hint.
        if ('' === $audience || !preg_match('#^https?://#', $audience)) {
            $io->error(sprintf(
                'Could not derive a canonical resource URI from the CLI environment (got "%s"). '
                .'Pass --audience=https://your-host/aisuite-mcp explicitly.',
                $audience,
            ));

            return Command::FAILURE;
        }

        // Create token
        $result = $this->oauthService->createAccessToken(
            $beUserUid,
            $clientId,
            array_values($scopes),
            $workspaceUid > 0 ? $workspaceUid : null,
            $audience,
        );

        $io->success('MCP access token created');
        $io->table(
            ['Property', 'Value'],
            [
                ['User UID', (string) $beUserUid],
                ['Username', $backendUser->user['username'] ?? 'unknown'],
                ['Client ID', $clientId],
                ['Workspace', $workspaceUid > 0 ? (string) $workspaceUid : 'user default'],
                ['Audience', $audience],
                ['Scopes', implode(' ', $scopes)],
                ['Expires in', sprintf('%d days', (int) ($result['expires_in'] / 86400))],
            ],
        );

        $io->newLine();
        $io->writeln('<info>Access Token (use as Bearer token):</info>');
        $io->newLine();
        $io->writeln($result['access_token']);
        $io->newLine();

        $io->writeln('<comment>MCP Inspector usage:</comment>');
        $io->writeln('  1. npx @modelcontextprotocol/inspector');
        $io->writeln('  2. Transport: "Streamable HTTP"');
        $io->writeln('  3. URL: https://your-site.ddev.site/aisuite-mcp');
        $io->writeln('  4. Header: Authorization: Bearer '.substr($result['access_token'], 0, 12).'...');

        return Command::SUCCESS;
    }
}
