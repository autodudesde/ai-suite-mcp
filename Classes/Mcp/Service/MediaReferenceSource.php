<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\TcaCompatibilityService;

class MediaReferenceSource
{
    public const NO_REFERENCE = 'No file reference found on %s:%d field "%s".';

    public function __construct(
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly ChildRecordTemplate $childRecordTemplate,
    ) {}

    public function fileAsSourceRefusal(string $sourceTable, int $sourceUid, string $targetTable, int $targetUid, string $targetField, int $targetPid): ?string
    {
        if ('sys_file' !== $sourceTable) {
            return null;
        }

        return sprintf(
            'sys_file:%d is the file itself, not a record that holds a reference to it, and copyMediaReference only copies references a record already has. '
            .'To attach the file to %s:%d.%s, write a record of its own with writeRecords, "uid_local": %d: %s',
            $sourceUid,
            $targetTable,
            $targetUid,
            $targetField,
            $sourceUid,
            $this->childRecordTemplate->render(
                $targetTable,
                $targetUid,
                $targetPid,
                $this->tcaCompatibilityService->getFieldConfiguration($targetTable, $targetField),
                $targetField,
            ),
        );
    }
}
