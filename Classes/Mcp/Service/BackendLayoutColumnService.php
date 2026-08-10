<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\View\BackendLayoutView;

class BackendLayoutColumnService
{
    public function __construct(
        private readonly BackendLayoutView $backendLayoutView,
        private readonly TcaLabelService $tcaLabel,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @return array<int, string>
     */
    public function getPageColumns(int $pageId): array
    {
        $columns = [];

        try {
            $backendLayout = $this->backendLayoutView->getBackendLayoutForPage($pageId);
            if (null !== $backendLayout) {
                $config = $backendLayout->getStructure();
                $rows = $config['__config']['backend_layout.']['rows.'] ?? [];
                foreach ($rows as $row) {
                    foreach ($row['columns.'] ?? [] as $col) {
                        $colPos = (int) ($col['colPos'] ?? -1);
                        if ($colPos >= 0) {
                            $columns[$colPos] = $this->tcaLabel->resolveLabel((string) ($col['name'] ?? 'Column '.$colPos));
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('BackendLayoutColumnService: backend layout resolution failed, falling back to a single main column', [
                'pageId' => $pageId,
                'error' => $e->getMessage(),
            ]);
        }

        if ([] === $columns) {
            $columns[0] = 'Main Content';
        }

        ksort($columns);

        return $columns;
    }
}
