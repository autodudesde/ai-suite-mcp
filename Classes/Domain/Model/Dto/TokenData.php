<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Domain\Model\Dto;

/**
 * DTO representing validated token data.
 * Returned by OAuthService::validateToken().
 */
final class TokenData
{
    public function __construct(
        public readonly int $beUserUid,
        /** @var list<string> */
        public readonly array $scopes,
        public readonly string $clientId,
        public readonly string $tokenId,
        /**
         * Workspace UID this token is bound to. null = use the user's default workspace
         * (legacy behaviour for tokens issued before Phase 6). int >= 0 = explicit workspace,
         * including 0 for live.
         */
        public readonly ?int $workspaceUid = null,
    ) {}
}
