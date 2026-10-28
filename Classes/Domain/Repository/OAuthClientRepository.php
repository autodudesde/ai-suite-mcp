<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Repository;

use AutoDudes\AiSuite\Domain\Repository\AbstractRepository;
use AutoDudes\AiSuite\Service\WorkspaceContextService;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

class OAuthClientRepository extends AbstractRepository
{
    public const TABLE = 'tx_aisuite_oauth_clients';

    private const TOKEN_TABLE = 'tx_aisuite_oauth_tokens';

    public function __construct(
        ConnectionPool $connectionPool,
        WorkspaceContextService $workspaceContextService,
    ) {
        parent::__construct($connectionPool, $workspaceContextService, self::TABLE);
    }

    /**
     * @param list<string> $redirectUris
     */
    public function register(string $clientId, string $clientName, array $redirectUris, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
            'client_id' => $clientId,
            'client_name' => $clientName,
            'redirect_uris' => (string) json_encode(array_values($redirectUris)),
            'created_at' => $now,
            'last_used_at' => $now,
        ]);
    }

    /**
     * @return null|array{client_id: string, client_name: string, redirect_uris: list<string>}
     */
    public function find(string $clientId): ?array
    {
        if ('' === $clientId) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $row = $queryBuilder
            ->select('client_id', 'client_name', 'redirect_uris')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('client_id', $queryBuilder->createNamedParameter($clientId)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative()
        ;
        if (!\is_array($row)) {
            return null;
        }

        /** @var mixed $uris */
        $uris = json_decode((string) $row['redirect_uris'], true);

        return [
            'client_id' => (string) $row['client_id'],
            'client_name' => (string) $row['client_name'],
            'redirect_uris' => \is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [],
        ];
    }

    public function hasTokens(string $clientId): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TOKEN_TABLE)
            ->where($queryBuilder->expr()->eq('client_id', $queryBuilder->createNamedParameter($clientId)))
            ->executeQuery()
            ->fetchOne() > 0
        ;
    }

    public function touch(string $clientId, int $now): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['last_used_at' => $now],
            ['client_id' => $clientId],
        );
    }

    public function deleteUnusedBefore(int $cutoff): int
    {
        $tokens = $this->connectionPool->getQueryBuilderForTable(self::TOKEN_TABLE);
        $activeClientIds = $tokens
            ->select('client_id')
            ->from(self::TOKEN_TABLE)
            ->where($tokens->expr()->eq('deleted', $tokens->createNamedParameter(0, ParameterType::INTEGER)))
            ->groupBy('client_id')
            ->executeQuery()
            ->fetchFirstColumn()
        ;

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->lt('last_used_at', $queryBuilder->createNamedParameter($cutoff, ParameterType::INTEGER)))
        ;
        if ([] !== $activeClientIds) {
            $queryBuilder->andWhere($queryBuilder->expr()->notIn(
                'client_id',
                $queryBuilder->createNamedParameter(array_map('strval', $activeClientIds), Connection::PARAM_STR_ARRAY),
            ));
        }

        return (int) $queryBuilder->executeStatement();
    }
}
