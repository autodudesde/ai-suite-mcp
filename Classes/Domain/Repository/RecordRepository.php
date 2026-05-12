<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Generic, table-agnostic record lookups used by ReadRecordTool and WriteRecordTool.
 *
 * Caller is responsible for permission checks and TCA-validation of field names.
 * This repository only assembles the SQL — it trusts its inputs.
 */
class RecordRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Find UIDs matching the given criteria.
     *
     * Empty-string and null filter values both match "field is empty or NULL".
     * DeletedRestriction is applied automatically via the default restriction container.
     *
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

    /**
     * UID of the last record on the page (greatest sortBy value).
     * If $colPos is non-null, restrict to that column position — used for tt_content
     * stacking inside a colPos slot.
     */
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
