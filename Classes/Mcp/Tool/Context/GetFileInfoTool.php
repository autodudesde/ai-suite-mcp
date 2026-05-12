<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Context;

use AutoDudes\AiSuite\Domain\Repository\SysFileMetadataRepository;
use AutoDudes\AiSuite\Domain\Repository\SysFileReferenceRepository;
use AutoDudes\AiSuiteMcp\Mcp\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\McpToolContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\FilePreviewService;
use Mcp\Types\CallToolResult;
use Mcp\Types\Content;
use Mcp\Types\TextContent;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Retrieve file info: metadata (all languages), file references, or both.
 * Consolidates the former getFileMetadata and getFileReferences tools.
 */
#[AutoconfigureTag('aisuite.mcp.tool')]
class GetFileInfoTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:read';

    public function __construct(
        McpToolContext $mcpToolContext,
        private readonly SysFileMetadataRepository $sysFileMetadataRepository,
        private readonly SysFileReferenceRepository $sysFileReferenceRepository,
        private readonly FilePreviewService $filePreviewService,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'getFileInfo';
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
            $file = $this->assertFileReadAccess($fileUid);
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
            $result['references'] = $this->buildReferences($fileUid, null);
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
    private function buildReferences(?int $fileUid, ?int $pageId): array
    {
        $rows = $this->sysFileReferenceRepository->findByFileOrPage($fileUid, $pageId);

        return array_map(fn ($r) => [
            'referenceUid' => (int) $r['uid'],
            'fileUid' => (int) $r['uid_local'],
            'fileName' => $r['file_name'],
            'extension' => $r['extension'],
            'identifier' => $r['identifier'],
            'usedIn' => [
                'table' => $r['tablenames'],
                'field' => $r['fieldname'],
                'recordUid' => (int) $r['uid_foreign'],
            ],
            'title' => (string) $r['title'],
            'alternative' => (string) $r['alternative'],
            'hasAltText' => '' !== trim((string) $r['alternative']),
        ], $rows);
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
