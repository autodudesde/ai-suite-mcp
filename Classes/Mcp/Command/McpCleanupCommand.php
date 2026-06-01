<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Command;

use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

/**
 *  vendor/bin/typo3 ai-suite-mcp:cleanup.
 */
class McpCleanupCommand extends Command
{
    public function __construct(
        private readonly TokenRepository $tokenRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Clean up expired MCP OAuth tokens, sessions, and task files');
        $this->setHelp('Removes expired authorization codes (>10 min), access tokens (>37 days), '
            .'old session files (>7 days), completed MCP task files (>30 days), '
            .'and hard-deletes revoked tokens older than 30 days (GDPR retention).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Expired authorization codes (>10 minutes)
        $deletedCodes = $this->tokenRepository->deleteExpiredCodes();
        $io->writeln(sprintf('Deleted %d expired authorization codes', $deletedCodes));

        // Expired access tokens (>37 days = 30 days lifetime + 7 days buffer)
        $deletedTokens = $this->tokenRepository->deleteExpiredTokens(days: 37);
        $io->writeln(sprintf('Deleted %d expired access tokens', $deletedTokens));

        // Revoked tokens past the GDPR retention window (>30 days since creation).
        $deletedRevoked = $this->tokenRepository->deleteRevokedTokensOlderThan(days: 30);
        $io->writeln(sprintf('Hard-deleted %d revoked tokens older than 30 days (GDPR retention)', $deletedRevoked));

        // Old session files (>7 days)
        $sessionPath = Environment::getVarPath().'/aisuite_mcp_sessions/';
        $deletedSessions = $this->cleanupOldFiles($sessionPath, maxAgeDays: 7);
        $io->writeln(sprintf('Deleted %d expired session files', $deletedSessions));

        // Completed MCP task files (>30 days)
        $taskPath = Environment::getVarPath().'/mcp_tasks/';
        $deletedTasks = $this->cleanupOldFiles($taskPath, maxAgeDays: 30);
        $io->writeln(sprintf('Deleted %d completed task files', $deletedTasks));

        $io->success('MCP cleanup completed');

        return Command::SUCCESS;
    }

    private function cleanupOldFiles(string $path, int $maxAgeDays): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $deleted = 0;
        $maxAge = time() - ($maxAgeDays * 86400);

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
