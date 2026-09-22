<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Workflow\Trait;

use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

trait PageSubtreeSelectionTrait
{
    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function pageSelectionSchemaProperties(string $whatFor): array
    {
        return [
            'pageIds' => [
                'type' => 'array',
                'items' => ['type' => 'integer'],
                'description' => sprintf('Array of page UIDs to %s. Alternative to rootPageId; give exactly one of the two.', $whatFor),
            ],
            'rootPageId' => [
                'type' => 'integer',
                'description' => 'A whole page subtree instead of a UID list: this page and everything below it, resolved server-side. '
                    .'Capped at '.self::maxSubtreePages().' pages. Alternative to pageIds; give exactly one of the two.',
            ],
            'recursive' => [
                'type' => 'boolean',
                'default' => true,
                'description' => 'Only meaningful with rootPageId: true walks the entire subtree, false stops at the direct children.',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return CallToolResult|list<int>
     */
    protected function resolveTargetPages(array $params): array|CallToolResult
    {
        $pageIds = array_values(array_filter(
            array_map('intval', (array) ($params['pageIds'] ?? [])),
            static fn (int $uid): bool => $uid > 0,
        ));
        $rootPageId = (int) ($params['rootPageId'] ?? 0);
        $rootPageId = $rootPageId > 0 ? $rootPageId : null;

        if ([] !== $pageIds && null !== $rootPageId) {
            return $this->textError('Give either pageIds or rootPageId, not both — they are two ways to name the same thing, and which one wins would be a guess.');
        }

        if (null === $rootPageId) {
            if ([] === $pageIds) {
                return $this->textError('No pages targeted: pass pageIds (a UID list) or rootPageId (a subtree).');
            }

            return $pageIds;
        }

        $this->recordAccess->assertPagePerm($rootPageId, Permission::PAGE_SHOW);

        $recursive = (bool) ($params['recursive'] ?? true);
        $resolved = $this->pagesRepository->getSubtreePageIds(
            $rootPageId,
            $recursive ? self::fullSubtreeDepth() : self::directChildrenDepth(),
        );

        if (count($resolved) > self::maxSubtreePages()) {
            $this->logger->warning('Batch tool: subtree exceeds the page cap', [
                'rootPageId' => $rootPageId,
                'resolved' => count($resolved),
                'cap' => self::maxSubtreePages(),
            ]);

            return $this->textError(sprintf(
                'rootPageId %d expands to %d pages, above the cap of %d. This tool bills per page. '
                .'Pick a deeper root, set recursive to false, or pass an explicit pageIds list.',
                $rootPageId,
                count($resolved),
                self::maxSubtreePages(),
            ));
        }

        return array_values(array_map('intval', $resolved));
    }

    private static function maxSubtreePages(): int
    {
        return 50;
    }

    private static function directChildrenDepth(): int
    {
        return 1;
    }

    private static function fullSubtreeDepth(): int
    {
        return 20;
    }
}
