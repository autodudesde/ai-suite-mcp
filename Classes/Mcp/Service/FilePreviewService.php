<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use Mcp\Types\ImageContent;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Generates MCP ImageContent previews (base64 PNG/JPEG) from FAL files.
 * Returns null on any failure — previews are a nice-to-have and must never
 * break the surrounding tool response.
 */
class FilePreviewService
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Build an MCP ImageContent block with a scaled-down preview of the given file.
     * Width/height use TYPO3's "maximum" semantic ("m" suffix) so the aspect ratio is preserved.
     */
    public function generate(File $file, int $width = 128, int $height = 128): ?ImageContent
    {
        if (!str_starts_with($file->getMimeType(), 'image/')) {
            return null;
        }

        try {
            $processedFile = $file->process(
                ProcessedFile::CONTEXT_IMAGECROPSCALEMASK,
                ['width' => $width.'m', 'height' => $height.'m'],
            );

            $publicUrl = $processedFile->getPublicUrl();
            if (null === $publicUrl) {
                return null;
            }

            $absolutePath = Environment::getPublicPath().'/'.$publicUrl;
            if (!file_exists($absolutePath)) {
                return null;
            }

            $data = file_get_contents($absolutePath);
            if (false === $data || '' === $data) {
                return null;
            }

            return new ImageContent(
                data: base64_encode($data),
                mimeType: $processedFile->getMimeType() ?: 'image/jpeg',
                annotations: null,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('MCP file preview generation failed', [
                'fileUid' => $file->getUid(),
                'name' => $file->getName(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Build an absolute public URL for the given file so MCP clients can link to it.
     * Returns null for files without a public URL (e.g. private storages) or when the
     * request host is unavailable. The URL is not guaranteed to be publicly reachable —
     * it reflects whatever the FAL storage exposes.
     */
    public function getAbsolutePublicUrl(File $file): ?string
    {
        try {
            $publicUrl = $file->getPublicUrl();
        } catch (\Throwable $e) {
            $this->logger->warning('FilePreviewService: could not resolve public URL for file', [
                'fileUid' => $file->getUid(),
                'name' => $file->getName(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if (null === $publicUrl || '' === $publicUrl) {
            return null;
        }

        // Already absolute (e.g. CDN-backed storage) — pass through.
        if (1 === preg_match('#^https?://#i', $publicUrl)) {
            return $publicUrl;
        }

        $host = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        if (!\is_string($host) || '' === $host) {
            return null;
        }

        return rtrim($host, '/').'/'.ltrim($publicUrl, '/');
    }
}
