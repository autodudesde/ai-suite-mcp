<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Repository for OAuth authorization codes, access tokens, and consents.
 */
class TokenRepository
{
    private const CODE_TABLE = 'tx_aisuite_oauth_codes';
    private const TOKEN_TABLE = 'tx_aisuite_oauth_tokens';
    private const CONSENT_TABLE = 'tx_aisuite_oauth_consents';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    // ── Authorization Codes ──

    /**
     * Store an authorization code (hashed).
     *
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
     * Find a code record by its hash.
     *
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

    /**
     * Atomically mark a code as used. Returns true if successful (code was unused).
     * If another request already used the code, this returns false (race condition safe).
     */
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

    /**
     * Delete expired authorization codes.
     */
    public function deleteExpiredCodes(): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CODE_TABLE);

        return $qb
            ->delete(self::CODE_TABLE)
            ->where($qb->expr()->lt('expires_at', $qb->createNamedParameter(time(), Connection::PARAM_INT)))
            ->executeStatement()
        ;
    }

    // ── Access Tokens ──

    /**
     * Create an access token record.
     *
     * @param array<string, mixed> $data
     */
    public function createToken(array $data): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TOKEN_TABLE);
        $connection->insert(self::TOKEN_TABLE, $data);

        return (int) $connection->lastInsertId();
    }

    /**
     * Find a token record by its hash.
     *
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
     * Find a token record by its refresh token hash.
     *
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

    /**
     * Update last_used_at and last_used_ip for a token.
     */
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

    /**
     * Soft-delete a token by UID.
     */
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

    /**
     * Soft-delete a token by its hash.
     */
    public function markDeletedByHash(string $tokenHash): void
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $qb->update(self::TOKEN_TABLE)
            ->set('deleted', 1)
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($tokenHash)))
            ->executeStatement()
        ;
    }

    /**
     * Revoke ALL active tokens for a user.
     * Used for password change hook (S15).
     */
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

    /**
     * Revoke ALL active tokens for a user+client combination.
     * Used for theft detection (S24).
     */
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
     * Find active (non-deleted, non-expired) tokens for a user, newest first.
     *
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

    /**
     * Count active (non-deleted, non-expired) tokens for a user.
     */
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

    /**
     * Revoke the oldest active token for a user (FIFO eviction when limit reached).
     */
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
     * Atomically increment session credits used for a token.
     *
     * Replaces the historical read-modify-write pattern (load value into PHP, add delta,
     * UPDATE absolute value), which lost concurrent increments when two requests for the
     * same token executed in parallel. The arithmetic now happens at the DB layer, so
     * concurrent calls accumulate correctly.
     *
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

    /**
     * Get session credits used for a token.
     */
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

    /**
     * Hard-delete soft-deleted (revoked) tokens older than the given retention window.
     *
     * Revocation soft-deletes (`deleted = 1`) so that refresh-token theft detection (S24)
     * can still recognise a reused refresh token after rotation. After the retention
     * window the theft-detection signal is no longer useful (legitimate refresh windows
     * are minutes to hours, not weeks), and GDPR right-to-erasure expects revoked tokens
     * to actually leave the database.
     *
     * Soft-deleted tokens whose natural `expires_at` is already in the past are picked
     * up by {@see self::deleteExpiredTokens()}; this method covers tokens revoked before
     * their natural expiry.
     */
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

    /**
     * Delete expired tokens (older than given days past expiry).
     */
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

    // ── Consents ──

    /**
     * Find existing consent for a user+client.
     *
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
     * Store or update a consent record.
     *
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
