<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;

class SysWorkspaceRepository
{
    private const TABLE = 'sys_workspace';

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
}
