<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\SysFileMetadataRepository;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Service\FilePreviewService;
use AutoDudes\AiSuiteMcp\Mcp\Service\WorkspaceRecordService;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\Content;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\File;

class ReadFileInfoTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';
    protected bool $readOnlyHint = true;
    protected bool $idempotentHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly SysFileMetadataRepository $sysFileMetadataRepository,
        private readonly RecordRepository $recordRepository,
        private readonly WorkspaceRecordService $workspaceRecords,
        private readonly FilePreviewService $filePreviewService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'readFileInfo';
    }

    public function getDescription(): string
    {
        return 'Get detailed file information: metadata (title, alt text, description) with all language overlays, '
            .'and where the file is referenced across the site. For image files a 128px preview thumbnail is included by default.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileUid' => ['type' => 'integer', 'description' => 'sys_file UID to inspect.'],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'enum' => ['metadata', 'references']],
                    'default' => ['metadata', 'references'],
                    'description' => 'What to include. Default: both metadata and references.',
                ],
                'includeThumbnail' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'For image files, include a 128px base64 preview. Default: true.',
                ],
            ],
            'required' => ['fileUid'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $fileUid = (int) $params['fileUid'];
        $include = $params['include'] ?? ['metadata', 'references'];
        $includeThumbnail = (bool) ($params['includeThumbnail'] ?? true);

        try {
            $file = $this->recordAccess->assertFileReadAccess($fileUid);
        } catch (\RuntimeException $e) {
            $this->logger->warning('GetFileInfo: file not found', [
                'fileUid' => $fileUid,
                'reason' => $e->getMessage(),
            ]);

            return new CallToolResult(
                [new TextContent(sprintf('File UID %d not found.', $fileUid))],
                isError: true,
            );
        }

        $result = [
            'file' => [
                'uid' => $file->getUid(),
                'name' => $file->getName(),
                'extension' => $file->getExtension(),
                'mimeType' => $file->getMimeType(),
                'size' => $file->getSize(),
                'identifier' => $file->getIdentifier(),
                'publicUrl' => $this->filePreviewService->getAbsolutePublicUrl($file),
            ],
        ];

        if (\in_array('metadata', $include, true)) {
            $result['metadata'] = $this->buildMetadata($fileUid);
        }

        if (\in_array('references', $include, true)) {
            $result['references'] = $this->buildReferences($file);
        }

        /** @var list<Content> $content */
        $content = [new TextContent((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))];

        if ($includeThumbnail) {
            $preview = $this->filePreviewService->generate($file, 128, 128);
            if (null !== $preview) {
                $content[] = $preview;
            }
        }

        return new CallToolResult($content);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildMetadata(int $fileUid): array
    {
        $rows = $this->sysFileMetadataRepository->findAllByFileUid($fileUid);

        return array_map([$this, 'formatMetadataRow'], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildReferences(File $file): array
    {
        if (!$this->recordAccess->hasTableReadAccess('sys_file_reference')) {
            return [];
        }

        $uids = $this->recordRepository->findUidsByRelation('sys_file_reference', 'uid_local', [(int) $file->getUid()], 200);

        $references = [];
        foreach ($this->workspaceRecords->overlay('sys_file_reference', $uids) as $row) {
            if ((int) ($row['uid_local'] ?? 0) !== (int) $file->getUid()
                || !$this->recordAccess->canReadRecordTitle((string) $row['tablenames'], (int) $row['uid_foreign'])
            ) {
                continue;
            }

            $reference = [
                'referenceUid' => (int) $row['uid'],
                'fileUid' => (int) $file->getUid(),
                'fileName' => $file->getName(),
                'extension' => $file->getExtension(),
                'identifier' => $file->getIdentifier(),
                'usedIn' => [
                    'table' => (string) $row['tablenames'],
                    'field' => (string) $row['fieldname'],
                    'recordUid' => (int) $row['uid_foreign'],
                ],
                'title' => (string) ($row['title'] ?? ''),
                'alternative' => (string) ($row['alternative'] ?? ''),
                'hasAltText' => '' !== trim((string) ($row['alternative'] ?? '')),
            ];
            $state = $this->workspaceRecords->stateLabel($row);
            if ('' !== $state) {
                $reference['state'] = $state;
            }
            $references[] = $reference;
        }

        usort($references, static fn (array $a, array $b): int => [$a['usedIn']['table'], $a['usedIn']['recordUid'], $a['referenceUid']] <=> [$b['usedIn']['table'], $b['usedIn']['recordUid'], $b['referenceUid']]);

        return $references;
    }

    /**
     * @param array<string, mixed> $m
     *
     * @return array<string, mixed>
     */
    private function formatMetadataRow(array $m): array
    {
        return [
            'uid' => (int) $m['uid'],
            'title' => (string) ($m['title'] ?? ''),
            'alternative' => (string) ($m['alternative'] ?? ''),
            'description' => (string) ($m['description'] ?? ''),
            'sys_language_uid' => (int) ($m['sys_language_uid'] ?? 0),
            'hasAltText' => '' !== trim((string) ($m['alternative'] ?? '')),
            'hasTitle' => '' !== trim((string) ($m['title'] ?? '')),
        ];
    }
}
