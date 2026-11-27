<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Command;

use AutoDudes\AiSuiteMcp\Domain\Repository\OAuthClientRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpSessionStoreService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Registry;

class McpCleanupCommand extends Command
{
    public const REGISTRY_NAMESPACE = 'ai_suite_mcp';
    public const REGISTRY_LAST_RUN = 'cleanup.lastRun';
    public const MAX_AGE_SECONDS = 2 * 86400;

    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly McpSessionStoreService $sessionStore,
        private readonly OAuthClientRepository $clientRepository,
        private readonly Registry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Clean up expired MCP OAuth tokens, sessions, and task files');
        $this->setHelp('Removes expired authorization codes (>10 min), access tokens (>37 days), '
            .'session files past twice their configured timeout, completed MCP task files (>30 days), '
            .'hard-deletes revoked tokens older than 30 days (GDPR retention) '
            .'and removes client registrations unused for 30 days that hold no active token.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $deletedCodes = $this->tokenRepository->deleteExpiredCodes();
        $io->writeln(sprintf('Deleted %d expired authorization codes', $deletedCodes));

        $deletedTokens = $this->tokenRepository->deleteExpiredTokens(days: 37);
        $io->writeln(sprintf('Deleted %d expired access tokens', $deletedTokens));

        $deletedRevoked = $this->tokenRepository->deleteRevokedTokensOlderThan(days: 30);
        $io->writeln(sprintf('Hard-deleted %d revoked tokens older than 30 days (GDPR retention)', $deletedRevoked));

        $deletedClients = $this->clientRepository->deleteUnusedBefore(time() - 30 * 86400);
        $io->writeln(sprintf('Deleted %d unused client registrations', $deletedClients));

        $deletedSessions = $this->sessionStore->deleteExpiredSessions();
        $io->writeln(sprintf(
            'Deleted %d expired session files (older than %d seconds)',
            $deletedSessions,
            $this->sessionStore->getRetentionSeconds(),
        ));

        $deletedTasks = $this->sessionStore->deleteLeftoverTaskFiles(30 * 86400);
        $io->writeln(sprintf('Deleted %d completed task files', $deletedTasks));

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_LAST_RUN, time());
        $io->success('MCP cleanup completed');

        return Command::SUCCESS;
    }
}
