<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Command;

use AutoDudes\AiSuiteMcp\Mcp\McpBackendUserInitializer;
use AutoDudes\AiSuiteMcp\Mcp\McpServerFactory;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use Mcp\Server\ServerRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Local stdio MCP server.
 *
 *   vendor/bin/typo3 ai-suite-mcp:server --user=1
 *   vendor/bin/typo3 ai-suite-mcp:server --user=editor --scopes="mcp:read mcp:write"
 */
class McpServerCommand extends Command
{
    public function __construct(
        private readonly McpServerFactory $serverFactory,
        private readonly McpBackendUserInitializer $backendUserInitializer,
        private readonly McpUserContext $userContext,
        private readonly PermissionService $permissionService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Run a local MCP server over stdio (trusted CLI clients only — no OAuth/HTTPS).')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Backend user UID or username', '1')
            ->addOption('scopes', 's', InputOption::VALUE_OPTIONAL, 'Space-separated scopes (default: all available for the user)')
            ->addOption('workspace', 'w', InputOption::VALUE_OPTIONAL, 'Workspace UID to operate in (default: resolved from mcpWriteMode)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $userInput = (string) $input->getOption('user');
        $beUserUid = $this->resolveBackendUserUid($userInput);
        if (0 === $beUserUid) {
            $stderr->writeln(sprintf('<error>Backend user "%s" not found or disabled.</error>', $userInput));

            return Command::FAILURE;
        }

        $workspaceOption = $input->getOption('workspace');
        $workspaceUid = (null !== $workspaceOption && '' !== $workspaceOption) ? (int) $workspaceOption : null;

        try {
            $this->backendUserInitializer->initialize($beUserUid, $workspaceUid);
        } catch (\Throwable $e) {
            $stderr->writeln(sprintf('<error>Could not initialize backend user context: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $scopeInput = $input->getOption('scopes');
        if (null !== $scopeInput && '' !== $scopeInput) {
            $scopes = array_values(array_filter(explode(' ', (string) $scopeInput)));
        } else {
            $scopes = array_values($this->permissionService->getAvailableScopes());
        }

        if ([] === $scopes) {
            $stderr->writeln('<error>No scopes available for this user. Check user group permissions.</error>');

            return Command::FAILURE;
        }

        $this->userContext->initialize(
            $beUserUid,
            $scopes,
            'stdio-cli',
            '',
            ExtensionManagementUtility::getExtensionVersion('ai_suite_mcp') ?: '0.0.0',
        );

        $stderr->writeln(sprintf(
            '<info>AI Suite MCP stdio server ready (user uid %d, scopes: %s). Reading JSON-RPC on stdin…</info>',
            $beUserUid,
            implode(' ', $scopes),
        ));

        $server = $this->serverFactory->createServer();
        $initOptions = $server->createInitializationOptions();
        $runner = new ServerRunner($server, $initOptions, $this->logger);

        try {
            $runner->run();
        } catch (\Throwable $e) {
            $this->logger->error('MCP stdio server run failed', ['exception' => $e->getMessage()]);
            $stderr->writeln(sprintf('<error>MCP stdio server failed: %s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function resolveBackendUserUid(string $userInput): int
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        if (ctype_digit($userInput)) {
            $backendUser->setBeUserByUid((int) $userInput);
        } else {
            $backendUser->setBeUserByName($userInput);
        }

        if (empty($backendUser->user) || 0 !== (int) ($backendUser->user['disable'] ?? 0) || 0 !== (int) ($backendUser->user['deleted'] ?? 0)) {
            return 0;
        }

        return (int) $backendUser->user['uid'];
    }
}
