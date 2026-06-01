<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

final class TokenData
{
    public function __construct(
        public readonly int $beUserUid,
        /** @var list<string> */
        public readonly array $scopes,
        public readonly string $clientId,
        public readonly string $tokenId,
        public readonly ?int $workspaceUid = null,
        public readonly string $issuedVersion = '',
    ) {}
}
