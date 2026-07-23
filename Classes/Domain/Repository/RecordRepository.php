<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class RecordRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, null|scalar> $fieldFilters
     * @param null|list<int>             $allowedPids  null = no PID-IN restriction (admin, rootlevel table, or $pid mode)
     * @param null|string                $extraWhere   raw SQL andWhere() — used for the pages page-perm-clause
     *
     * @return list<int>
     */
    public function findUidsByCriteria(
        string $table,
        ?int $pid,
        array $fieldFilters,
        ?array $allowedPids,
        ?string $extraWhere,
        string $sortField,
        int $limit,
        int $offset,
    ): array {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $query = $qb->select('uid')->from($table);

        if (null !== $pid) {
            $query->where($qb->expr()->eq('pid', $qb->createNamedParameter($pid, Connection::PARAM_INT)));
        } elseif (null !== $extraWhere) {
            $query->andWhere($extraWhere);
        } elseif (null !== $allowedPids && [] !== $allowedPids) {
            $query->andWhere($qb->expr()->in('pid', $qb->createNamedParameter($allowedPids, Connection::PARAM_INT_ARRAY)));
        }

        foreach ($fieldFilters as $field => $value) {
            if ('' === $value || null === $value) {
                $query->andWhere($qb->expr()->or(
                    $qb->expr()->eq($field, $qb->createNamedParameter('')),
                    $qb->expr()->isNull($field),
                ));
            } else {
                $query->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
            }
        }

        $query
            ->orderBy($sortField, 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
        ;

        return array_map(static fn ($v): int => (int) $v, $query->executeQuery()->fetchFirstColumn());
    }

    public function countLiveRecords(string $table, int $pid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);

        return (int) $qb
            ->count('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pid, Connection::PARAM_INT)),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne()
        ;
    }

    /**
     * Count records of any table on a page, tolerant of tables that are not workspace-aware.
     *
     * Unlike countLiveRecords() this adds NO t3ver_wsid condition (which would fail on a table that
     * has no such column) and swallows any table-shape surprise, so it is safe to run across the whole
     * TCA. The query builder's default restrictions still hide deleted/disabled rows. Intended for the
     * "what else lives on this page" overview, where an approximate visible count is enough.
     */
    public function countRecordsOnPage(string $table, int $pid): int
    {
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);

            return (int) $qb
                ->count('uid')
                ->from($table)
                ->where($qb->expr()->eq('pid', $qb->createNamedParameter($pid, Connection::PARAM_INT)))
                ->executeQuery()
                ->fetchOne()
            ;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, scalar> $fieldFilters
     */
    public function countByCriteria(string $table, array $fieldFilters): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $query = $qb->count('uid')->from($table);

        foreach ($fieldFilters as $field => $value) {
            $query->andWhere($qb->expr()->eq($field, $qb->createNamedParameter($value)));
        }

        return (int) $query->executeQuery()->fetchOne();
    }

    /**
     * @return null|array{value: string, count: int}
     */
    public function mostCommonValue(string $table, string $field, ?string $typeField, ?string $typeValue): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $query = $qb
            ->select($field)
            ->addSelectLiteral($qb->expr()->count('uid', 'cnt'))
            ->from($table)
            ->where($qb->expr()->neq($field, $qb->createNamedParameter('')))
            ->groupBy($field)
            ->orderBy('cnt', 'DESC')
            ->setMaxResults(1)
        ;

        if (null !== $typeField && null !== $typeValue && '' !== $typeValue) {
            $query->andWhere($qb->expr()->eq($typeField, $qb->createNamedParameter($typeValue)));
        }

        $row = $query->executeQuery()->fetchAssociative();
        if (false === $row || null === ($row[$field] ?? null)) {
            return null;
        }

        return ['value' => (string) $row[$field], 'count' => (int) $row['cnt']];
    }

    public function findLastUidOnPage(
        string $table,
        int $pageId,
        string $sortByField,
        ?int $colPos = null,
    ): ?int {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $query = $qb
            ->select('uid')
            ->from($table)
            ->where($qb->expr()->eq('pid', $qb->createNamedParameter($pageId, Connection::PARAM_INT)))
            ->orderBy($sortByField, 'DESC')
            ->setMaxResults(1)
        ;

        if (null !== $colPos) {
            $query->andWhere($qb->expr()->eq('colPos', $qb->createNamedParameter($colPos, Connection::PARAM_INT)));
        }

        $uid = $query->executeQuery()->fetchOne();

        return false !== $uid ? (int) $uid : null;
    }
}
