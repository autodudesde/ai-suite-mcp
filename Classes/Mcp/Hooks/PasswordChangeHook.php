<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Hooks;

use AutoDudes\AiSuiteMcp\Domain\Repository\TokenRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

class PasswordChangeHook
{
    public function __construct(
        private readonly TokenRepository $tokenRepository,
        private readonly LoggerInterface $logger,
    ) {}

    /**
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

        if (!isset($fieldArray['password'])) {
            return;
        }

        $uid = (int) $id;
        if (0 === $uid) {
            return;
        }

        $revokedCount = $this->tokenRepository->revokeAllTokensForUser($uid);

        if ($revokedCount > 0) {
            $this->logger->notice('MCP tokens revoked due to password change', [
                'be_user_uid' => $uid,
                'revoked_tokens' => $revokedCount,
            ]);
        }
    }
}
