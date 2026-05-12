<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Hooks;

use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * DataHandler hook: When a backend user's password changes,
 * revoke all their MCP OAuth tokens.
 *
 * This ensures that if an account is compromised and the password
 * is changed, all existing MCP sessions are immediately terminated.
 */
class PasswordChangeHook
{
    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Called after database operations on be_users.
     *
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        int|string $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        if ('be_users' !== $table) {
            return;
        }

        // Check if password was changed
        if (!isset($fieldArray['password'])) {
            return;
        }

        $uid = (int) $id;
        if (0 === $uid) {
            return;
        }

        // Revoke all MCP tokens for this user
        $revokedCount = $this->tokenRepository->revokeAllTokensForUser($uid);

        if ($revokedCount > 0) {
            $this->logger->notice('MCP tokens revoked due to password change', [
                'be_user_uid' => $uid,
                'revoked_tokens' => $revokedCount,
            ]);
        }
    }
}
