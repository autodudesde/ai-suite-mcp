<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

class SysWorkspaceRepository
{
    private const TABLE = 'sys_workspace';

    private const USER_WORKSPACE_TITLE = 'AI Suite MCP [#%d]';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array{uid: int, title: string}>
     */
    public function findAll(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeByType(HiddenRestriction::class);
        $rows = $qb
            ->select('uid', 'title')
            ->from(self::TABLE)
            ->orderBy('title', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $result = [];
        foreach ($rows as $row) {
            $result[] = ['uid' => (int) $row['uid'], 'title' => (string) $row['title']];
        }

        return $result;
    }

    /**
     * @return list<int>
     */
    public function findAllUids(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeByType(HiddenRestriction::class);
        $rows = $qb
            ->select('uid')
            ->from(self::TABLE)
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn()
        ;

        return array_map(static fn ($v): int => (int) $v, $rows);
    }

    /**
     * @param list<int> $uids
     *
     * @return array<int, string> map of uid => title
     */
    public function findTitlesByUids(array $uids): array
    {
        if ([] === $uids) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeByType(HiddenRestriction::class);
        $rows = $qb
            ->select('uid', 'title')
            ->from(self::TABLE)
            ->where($qb->expr()->in('uid', $qb->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $titles = [];
        foreach ($rows as $row) {
            $titles[(int) $row['uid']] = (string) $row['title'];
        }

        return $titles;
    }

    /**
     * Find the auto-provisioned per-user MCP workspace, if it exists. Matches the
     * deterministic title AND requires the user to be a listed member, so a title
     * collision cannot hand a user someone else's workspace.
     */
    public function findUserWorkspaceUid(int $beUserUid): ?int
    {
        if ($beUserUid <= 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeByType(HiddenRestriction::class);
        $uid = $qb
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('title', $qb->createNamedParameter($this->userWorkspaceTitle($beUserUid))),
                $qb->expr()->like('members', $qb->createNamedParameter('%'.$this->memberToken($beUserUid).'%')),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return false === $uid ? null : (int) $uid;
    }

    public function createForUser(int $beUserUid, string $username): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = (int) ($GLOBALS['EXEC_TIME'] ?? time());

        $connection->insert(self::TABLE, [
            'pid' => 0,
            'title' => $this->userWorkspaceTitle($beUserUid),
            'description' => sprintf('Auto-created MCP draft workspace for backend user "%s" (#%d).', $username, $beUserUid),
            'members' => $this->memberToken($beUserUid),
            'tstamp' => $now,
        ]);

        return (int) $connection->lastInsertId();
    }

    private function userWorkspaceTitle(int $beUserUid): string
    {
        return sprintf(self::USER_WORKSPACE_TITLE, $beUserUid);
    }

    private function memberToken(int $beUserUid): string
    {
        return 'be_users_'.$beUserUid;
    }
}
