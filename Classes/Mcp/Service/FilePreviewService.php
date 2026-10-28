<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use Mcp\Types\ImageContent;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;

class FilePreviewService
{
    public function __construct(
        private readonly BackendBaseUrlResolver $baseUrlResolver,
        private readonly LoggerInterface $logger,
    ) {}

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

        return $this->baseUrlResolver->makeAbsolute($publicUrl);
    }
}
