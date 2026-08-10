<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use AutoDudes\AiSuite\Domain\Repository\AbstractRepository;
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RecordRepository extends AbstractRepository
{
    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
    ) {
        parent::__construct($connectionPool, $workspaceContextService);
    }

    /**
     * @param array<string, null|scalar> $fieldFilters
     * @param null|list<int>             $allowedPids
     * @param 'pid'|'uid'                $pageScopeColumn
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
        string $pageScopeColumn = 'pid',
    ): array {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $this->withoutFrontendRestrictions($qb);
        $this->addWorkspaceRestriction($qb);
        $query = $qb->select('uid')->from($table);

        $scopeColumn = 'uid' === $pageScopeColumn ? 'uid' : 'pid';

        if (null !== $pid) {
            $query->where($qb->expr()->eq('pid', $qb->createNamedParameter($pid, Connection::PARAM_INT)));
        } elseif (null !== $extraWhere) {
            $query->andWhere($extraWhere);
        } elseif (null !== $allowedPids && [] !== $allowedPids) {
            $query->andWhere($qb->expr()->in($scopeColumn, $qb->createNamedParameter($allowedPids, Connection::PARAM_INT_ARRAY)));
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
     * @param list<int> $values
     *
     * @return list<int>
     */
    public function findUidsByRelation(string $table, string $field, array $values, int $limit): array
    {
        if ([] === $values) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $this->withoutFrontendRestrictions($qb);
        // The pointer differs between live row and version, so the caller reduces the duplicates.
        $qb->getRestrictions()->add(
            GeneralUtility::makeInstance(WorkspaceRestriction::class, $this->workspaceContextService->getWorkspaceId(), true),
        );

        $rows = $qb
            ->select('uid')
            ->from($table)
            ->where($qb->expr()->in($field, $qb->createNamedParameter($values, Connection::PARAM_INT_ARRAY)))
            ->orderBy('uid', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchFirstColumn()
        ;

        return array_map(static fn ($v): int => (int) $v, $rows);
    }

    // Live-only by contract: the baseline compareWithLive diffs the workspace against.
    public function countLiveRecords(string $table, int $pid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $this->withoutFrontendRestrictions($qb);

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

    public function countRecordsOnPage(string $table, int $pid): int
    {
        try {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $this->withoutFrontendRestrictions($qb);
            $this->addWorkspaceRestriction($qb);

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
        $this->withoutFrontendRestrictions($qb);
        $this->addWorkspaceRestriction($qb);
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
        $this->withoutFrontendRestrictions($qb);
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
        $this->withoutFrontendRestrictions($qb);
        $this->addWorkspaceRestriction($qb);
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

    /**
     * @param list<string>   $searchFields
     * @param null|list<int> $allowedPids
     *
     * @return list<array<string, mixed>>
     */
    public function searchByText(string $table, string $query, array $searchFields, ?array $allowedPids, int $limit, bool $workspaceAware, ?string $languageField = null): array
    {
        if ([] === $searchFields || (null !== $allowedPids && [] === $allowedPids)) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $this->withoutFrontendRestrictions($qb);
        $this->addWorkspaceRestriction($qb);
        $term = '%'.$qb->escapeLikeWildcards($query).'%';

        $likes = array_map(
            static fn (string $field): string => (string) $qb->expr()->like($field, $qb->createNamedParameter($term)),
            $searchFields,
        );

        $select = array_values(array_unique(array_merge(
            $workspaceAware ? ['uid', 'pid', 't3ver_oid', 't3ver_wsid', 't3ver_state'] : ['uid', 'pid'],
            null !== $languageField && '' !== $languageField ? [$languageField] : [],
            $searchFields,
        )));

        $qb->select(...$select)
            ->from($table)
            ->where($qb->expr()->or(...$likes))
            ->setMaxResults($limit)
        ;

        if (null !== $allowedPids) {
            $qb->andWhere($qb->expr()->in('pid', $qb->createNamedParameter($allowedPids, Connection::PARAM_INT_ARRAY)));
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function findTranslationUid(string $table, int $originUid, int $languageUid, string $pointerField, string $languageField): ?int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $this->withoutFrontendRestrictions($qb);
        $this->addWorkspaceRestriction($qb);

        $uid = $qb->select('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq($pointerField, $qb->createNamedParameter($originUid, Connection::PARAM_INT)),
                $qb->expr()->eq($languageField, $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return false !== $uid ? (int) $uid : null;
    }

    public function findPreviousSiblingUid(string $table, int $pageId, int $beforeUid, string $sortByField): ?int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $this->withoutFrontendRestrictions($qb);
        $this->addWorkspaceRestriction($qb);

        $reference = $qb->select($sortByField)
            ->from($table)
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($beforeUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne()
        ;
        if (false === $reference) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $this->withoutFrontendRestrictions($qb);
        $this->addWorkspaceRestriction($qb);

        $uid = $qb->select('uid')
            ->from($table)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageId, Connection::PARAM_INT)),
                $qb->expr()->lt($sortByField, $qb->createNamedParameter((int) $reference, Connection::PARAM_INT)),
            )
            ->orderBy($sortByField, 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne()
        ;

        return false !== $uid ? (int) $uid : null;
    }

    private function withoutFrontendRestrictions(QueryBuilder $queryBuilder): void
    {
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
    }
}
