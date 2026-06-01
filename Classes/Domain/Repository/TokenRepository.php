<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class TokenRepository
{
    private const CODE_TABLE = 'tx_aisuite_oauth_codes';
    private const TOKEN_TABLE = 'tx_aisuite_oauth_tokens';
    private const CONSENT_TABLE = 'tx_aisuite_oauth_consents';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function createCode(array $data): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::CODE_TABLE)
            ->insert(self::CODE_TABLE, $data)
        ;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function findCodeByHash(string $codeHash): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CODE_TABLE);
        $result = $qb
            ->select('*')
            ->from(self::CODE_TABLE)
            ->where($qb->expr()->eq('code', $qb->createNamedParameter($codeHash)))
            ->executeQuery()
            ->fetchAssociative()
        ;

        return $result ?: null;
    }

    public function markCodeUsed(string $codeHash): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CODE_TABLE);
        $affectedRows = $qb
            ->update(self::CODE_TABLE)
            ->set('used', 1)
            ->where(
                $qb->expr()->eq('code', $qb->createNamedParameter($codeHash)),
                $qb->expr()->eq('used', 0),
            )
            ->executeStatement()
        ;

        return $affectedRows > 0;
    }

    public function deleteExpiredCodes(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CODE_TABLE);

        return $qb
            ->delete(self::CODE_TABLE)
            ->where($qb->expr()->lt('expires_at', $qb->createNamedParameter(time(), Connection::PARAM_INT)))
            ->executeStatement()
        ;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createToken(array $data): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TOKEN_TABLE);
        $connection->insert(self::TOKEN_TABLE, $data);

        return (int) $connection->lastInsertId();
    }

    /**
     * @return null|array<string, mixed>
     */
    public function findByTokenHash(string $tokenHash): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $result = $qb
            ->select('*')
            ->from(self::TOKEN_TABLE)
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($tokenHash)))
            ->executeQuery()
            ->fetchAssociative()
        ;

        return $result ?: null;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function findByRefreshTokenHash(string $refreshTokenHash): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $result = $qb
            ->select('*')
            ->from(self::TOKEN_TABLE)
            ->where($qb->expr()->eq('refresh_token', $qb->createNamedParameter($refreshTokenHash)))
            ->executeQuery()
            ->fetchAssociative()
        ;

        return $result ?: null;
    }

    public function updateLastUsed(int $uid, string $ip = ''): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TOKEN_TABLE)
            ->update(
                self::TOKEN_TABLE,
                ['last_used_at' => time(), 'last_used_ip' => $ip],
                ['uid' => $uid],
            )
        ;
    }

    public function markDeleted(int $uid): void
    {
        $this->connectionPool
            ->getConnectionForTable(self::TOKEN_TABLE)
            ->update(
                self::TOKEN_TABLE,
                ['deleted' => 1],
                ['uid' => $uid],
            )
        ;
    }

    public function markDeletedByHash(string $tokenHash): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $qb->update(self::TOKEN_TABLE)
            ->set('deleted', 1)
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($tokenHash)))
            ->executeStatement()
        ;
    }

    public function revokeAllTokensForUser(int $beUserUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);

        return $qb
            ->update(self::TOKEN_TABLE)
            ->set('deleted', 1)
            ->where(
                $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeStatement()
        ;
    }

    public function revokeAllTokensForUserAndClient(int $beUserUid, string $clientId): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);

        return $qb
            ->update(self::TOKEN_TABLE)
            ->set('deleted', 1)
            ->where(
                $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)),
                $qb->expr()->eq('deleted', 0),
            )
            ->executeStatement()
        ;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActiveTokensForUser(int $beUserUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $rows = $qb
            ->select('*')
            ->from(self::TOKEN_TABLE)
            ->where(
                $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter(time(), Connection::PARAM_INT)),
            )
            ->orderBy('created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        return array_values($rows);
    }

    public function countActiveTokensForUser(int $beUserUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $result = $qb
            ->count('uid')
            ->from(self::TOKEN_TABLE)
            ->where(
                $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->gt('expires_at', $qb->createNamedParameter(time(), Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne()
        ;

        return (int) $result;
    }

    public function revokeOldestTokenForUser(int $beUserUid): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $oldest = $qb
            ->select('uid')
            ->from(self::TOKEN_TABLE)
            ->where(
                $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
            )
            ->orderBy('created_at', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        if (false !== $oldest) {
            $this->markDeleted((int) $oldest);
        }
    }

    /**
     * @return int the new total credits used after this increment
     */
    public function incrementSessionCreditsUsed(int $uid, int $delta): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TOKEN_TABLE);
        $connection->executeStatement(
            sprintf(
                'UPDATE %s SET session_credits_used = session_credits_used + ? WHERE uid = ?',
                self::TOKEN_TABLE,
            ),
            [$delta, $uid],
        );

        return $this->getSessionCreditsUsed($uid);
    }

    public function getSessionCreditsUsed(int $uid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $result = $qb
            ->select('session_credits_used')
            ->from(self::TOKEN_TABLE)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne()
        ;

        return (int) ($result ?: 0);
    }

    public function deleteRevokedTokensOlderThan(int $days = 30): int
    {
        $cutoff = time() - ($days * 86400);
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);

        return $qb
            ->delete(self::TOKEN_TABLE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(1, Connection::PARAM_INT)),
                $qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff, Connection::PARAM_INT)),
            )
            ->executeStatement()
        ;
    }

    public function deleteExpiredTokens(int $days = 37): int
    {
        $cutoff = time() - ($days * 86400);
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);

        return $qb
            ->delete(self::TOKEN_TABLE)
            ->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($cutoff, Connection::PARAM_INT)))
            ->executeStatement()
        ;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function findConsent(int $beUserUid, string $clientId): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CONSENT_TABLE);
        $result = $qb
            ->select('*')
            ->from(self::CONSENT_TABLE)
            ->where(
                $qb->expr()->eq('be_user_uid', $qb->createNamedParameter($beUserUid, Connection::PARAM_INT)),
                $qb->expr()->eq('client_id', $qb->createNamedParameter($clientId)),
            )
            ->executeQuery()
            ->fetchAssociative()
        ;

        return $result ?: null;
    }

    /**
     * @param list<string> $scopes
     */
    public function saveConsent(int $beUserUid, string $clientId, array $scopes): void
    {
        $existing = $this->findConsent($beUserUid, $clientId);
        $connection = $this->connectionPool->getConnectionForTable(self::CONSENT_TABLE);

        if (null !== $existing) {
            $connection->update(
                self::CONSENT_TABLE,
                ['scopes' => implode(' ', $scopes), 'granted_at' => time()],
                ['uid' => (int) $existing['uid']],
            );
        } else {
            $connection->insert(self::CONSENT_TABLE, [
                'be_user_uid' => $beUserUid,
                'client_id' => $clientId,
                'scopes' => implode(' ', $scopes),
                'granted_at' => time(),
            ]);
        }
    }
}
