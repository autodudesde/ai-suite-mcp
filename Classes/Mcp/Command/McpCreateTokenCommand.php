<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Command;

use AutoDudes\AiSuiteMcp\Mcp\Service\McpCliAccessService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OAuthService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class McpCreateTokenCommand extends Command
{
    public function __construct(
        private readonly OAuthService $oauthService,
        private readonly McpCliAccessService $cliAccess,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Create an MCP access token for testing (e.g. MCP Inspector)')
            ->addOption('audience', 'a', InputOption::VALUE_REQUIRED, 'Canonical resource URI to bind the token to (RFC 8707), e.g. https://your-host/aisuite-mcp. Must be passed: the CLI has no request host to derive it from.')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Backend user UID or username (required)')
            ->addOption('scopes', 's', InputOption::VALUE_REQUIRED, 'Space-separated scopes (default: all available for user)')
            ->addOption('client', 'c', InputOption::VALUE_REQUIRED, 'Client ID', 'mcp-inspector')
            ->addOption('workspace', 'w', InputOption::VALUE_REQUIRED, 'Workspace UID to bind this token to (0 = user default; ignored when ext:workspaces is not loaded)', '0')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userInput = (string) $input->getOption('user');
        $clientId = (string) $input->getOption('client');
        if ('' === $userInput) {
            $io->error('Name the backend user the token acts as: --user=<uid|username>.');

            return Command::FAILURE;
        }

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

        if (null !== ($refusal = $this->cliAccess->refusal($beUserUid))) {
            $io->error($refusal);

            return Command::FAILURE;
        }

        $workspaceUid = (int) $input->getOption('workspace');
        if ($workspaceUid > 0 && !ExtensionManagementUtility::isLoaded('workspaces')) {
            $output->writeln('<warning>Workspace flag ignored — ext:workspaces is not loaded; token created with workspace_uid=0.</warning>');
            $workspaceUid = 0;
        }
        if ($workspaceUid > 0 && false === $backendUser->checkWorkspace($workspaceUid)) {
            $io->error(sprintf('Backend user "%s" has no access to workspace %d.', $userInput, $workspaceUid));

            return Command::FAILURE;
        }

        $scopeInput = $input->getOption('scopes');
        ['granted' => $scopes, 'dropped' => $dropped] = $this->cliAccess->scopes(null !== $scopeInput ? (string) $scopeInput : null);
        if ([] !== $dropped) {
            $io->warning(sprintf('Not granted, the user lacks the permission or the scope does not exist: %s', implode(' ', $dropped)));
        }

        if (empty($scopes)) {
            $io->error('No scopes available for this user. Check user group permissions.');

            return Command::FAILURE;
        }

        $audience = (string) ($input->getOption('audience') ?? '');
        if (!preg_match('#^https?://#', $audience)) {
            $io->error(sprintf(
                'The token needs a canonical resource URI (got "%s"). Pass --audience=https://your-host/aisuite-mcp.',
                $audience,
            ));

            return Command::FAILURE;
        }

        $result = $this->oauthService->createAccessToken(
            $beUserUid,
            $clientId,
            array_values($scopes),
            $audience,
            $workspaceUid > 0 ? $workspaceUid : null,
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
