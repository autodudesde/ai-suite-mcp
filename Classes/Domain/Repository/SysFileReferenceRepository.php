<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use AutoDudes\AiSuite\Domain\Repository\AbstractRepository;
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SysFileReferenceRepository extends AbstractRepository
{
    private const TABLE = 'sys_file_reference';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
    ) {
        parent::__construct($connectionPool, $workspaceContextService, self::TABLE);
    }

    /**
     * @param list<int> $uidsForeign
     *
     * @return list<array<string, mixed>>
     */
    public function findMediaTextsByParents(string $tablenames, array $uidsForeign): array
    {
        if ([] === $uidsForeign) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $qb
            ->select('r.uid_foreign', 'r.alternative', 'r.title', 'r.description', 'f.name')
            ->from(self::TABLE, 'r')
            ->leftJoin('r', 'sys_file', 'f', $qb->expr()->eq('f.uid', $qb->quoteIdentifier('r.uid_local')))
            ->where(
                $qb->expr()->eq('r.tablenames', $qb->createNamedParameter($tablenames)),
                $qb->expr()->in('r.uid_foreign', $qb->createNamedParameter($uidsForeign, Connection::PARAM_INT_ARRAY)),
            )
            ->orderBy('r.sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;
    }

    /**
     * @return list<array{uid: int, uid_local: int, pid: int}>
     */
    public function findReferences(string $tablenames, int $uidForeign, string $fieldname): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $qb
            ->select('uid', 'uid_local', 'pid')
            ->from(self::TABLE)
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter($tablenames)),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($uidForeign, Connection::PARAM_INT)),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter($fieldname)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative()
        ;

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'uid' => (int) $row['uid'],
                'uid_local' => (int) $row['uid_local'],
                'pid' => (int) $row['pid'],
            ];
        }

        return $result;
    }
}
