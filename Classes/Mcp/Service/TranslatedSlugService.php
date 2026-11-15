<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TranslationService;
use Psr\Log\LoggerInterface;

class TranslatedSlugService
{
    public function __construct(
        private readonly TranslationService $translationService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<array-key, mixed> $writtenFields
     */
    public function refreshInheritedSlugs(string $table, int $uid, array $writtenFields): bool
    {
        try {
            return $this->translationService->regenerateInheritedSlugs($table, $uid, $writtenFields);
        } catch (\Throwable $e) {
            $this->logger->warning('TranslatedSlug: the inherited slug could not be rebuilt', [
                'table' => $table,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
