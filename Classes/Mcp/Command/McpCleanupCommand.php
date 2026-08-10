<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Command;

use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpSessionStoreService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

class McpCleanupCommand extends Command
{
    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly McpSessionStoreService $sessionStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Clean up expired MCP OAuth tokens, sessions, and task files');
        $this->setHelp('Removes expired authorization codes (>10 min), access tokens (>37 days), '
            .'session files past twice their configured timeout, completed MCP task files (>30 days), '
            .'and hard-deletes revoked tokens older than 30 days (GDPR retention).');
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

        $sessionPath = $this->sessionStore->getDirectory();
        $sessionMaxAge = $this->sessionStore->getRetentionSeconds();
        $deletedSessions = $this->cleanupOldFiles($sessionPath, $sessionMaxAge);
        $io->writeln(sprintf(
            'Deleted %d expired session files (older than %d seconds)',
            $deletedSessions,
            $sessionMaxAge,
        ));

        $taskPath = Environment::getVarPath().'/mcp_tasks/';
        $deletedTasks = $this->cleanupOldFiles($taskPath, 30 * 86400);
        $io->writeln(sprintf('Deleted %d completed task files', $deletedTasks));

        $io->success('MCP cleanup completed');

        return Command::SUCCESS;
    }

    private function cleanupOldFiles(string $path, int $maxAgeSeconds): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $deleted = 0;
        $maxAge = time() - $maxAgeSeconds;

        foreach (new \DirectoryIterator($path) as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            if ($file->getMTime() < $maxAge) {
                unlink($file->getPathname());
                ++$deleted;
            }
        }

        return $deleted;
    }
}
