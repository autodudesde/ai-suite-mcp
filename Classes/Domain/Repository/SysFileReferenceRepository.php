<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class SysFileReferenceRepository
{
    private const TABLE = 'sys_file_reference';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<int> $uidsForeign
     *
     * @return array<int, string>
     */
    public function findFirstMediaLabelPerParent(string $tablenames, array $uidsForeign): array
    {
        if ([] === $uidsForeign) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $qb
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

        $labels = [];
        foreach ($rows as $row) {
            $parent = (int) $row['uid_foreign'];
            if (isset($labels[$parent])) {
                continue;
            }
            foreach (['alternative', 'title', 'description', 'name'] as $candidate) {
                $value = trim((string) ($row[$candidate] ?? ''));
                if ('' !== $value) {
                    $labels[$parent] = $value;

                    break;
                }
            }
        }

        return $labels;
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
