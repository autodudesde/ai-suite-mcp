<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Exception\ResponseTooLargeException;
use AutoDudes\AiSuite\Exception\UnsafeUrlException;
use AutoDudes\AiSuite\Service\FileNameSanitizerService;
use AutoDudes\AiSuite\Service\Http\PublicUrlGuard;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\FetchedMedia;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\StoredMedia;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RefusedRequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteMediaService
{
    private const MAX_REDIRECTS = 5;
    private const CHUNK_SIZE = 8192;

    public function __construct(
        private readonly OnlineMediaHelperRegistry $onlineMediaHelperRegistry,
        private readonly LoggerInterface $logger,
        private readonly PublicUrlGuard $publicUrlGuard = new PublicUrlGuard(),
    ) {}

    /**
     * @param list<string> $extraDenyList
     *
     * @throws \RuntimeException
     */
    public function fetch(string $url, int $maxBytes = 0, array $extraDenyList = [], int $timeoutSeconds = 30): FetchedMedia
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $target = $this->checkedTarget($current, $extraDenyList);

            try {
                $response = $this->publicUrlGuard->fetch($current, $target, [
                    'allow_redirects' => false,
                    'stream' => true,
                    'timeout' => $timeoutSeconds,
                    'headers' => [
                        'Accept' => '*/*',
                        'User-Agent' => 'AiSuiteMcp-MediaFetch',
                    ],
                ], $maxBytes);
            } catch (ResponseTooLargeException $e) {
                throw RefusedRequestException::invalidParameter(sprintf('Remote file exceeds the maximum size of %d bytes.', $maxBytes), $e);
            }

            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                $current = $this->redirectTarget($current, $response->getHeaderLine('Location'));

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw RefusedRequestException::invalidParameter(sprintf('Remote server returned HTTP %d for the media URL.', $status));
            }

            return $this->streamToTempFile($response, $current, $maxBytes);
        }

        throw RefusedRequestException::invalidParameter('Too many redirects while fetching the media URL.');
    }

    /**
     * @throws \RuntimeException
     */
    public function decodeBase64ToTempFile(string $content, int $maxBytes = 0): FetchedMedia
    {
        $declaredMime = '';
        if (preg_match('#^data:([^;]+);base64,#i', $content, $matches)) {
            $declaredMime = strtolower($matches[1]);
            $content = substr($content, strlen($matches[0]));
        }

        $encoded = (string) preg_replace('/\s+/', '', $content);
        if ($maxBytes > 0 && intdiv(strlen(rtrim($encoded, '=')) * 3, 4) > $maxBytes) {
            throw RefusedRequestException::invalidParameter(sprintf('Uploaded content exceeds the maximum size of %d bytes.', $maxBytes));
        }

        $binary = base64_decode($encoded, true);
        if (false === $binary || '' === $binary) {
            throw RefusedRequestException::invalidParameter('Invalid base64 content.');
        }

        if ($maxBytes > 0 && strlen($binary) > $maxBytes) {
            throw RefusedRequestException::invalidParameter(sprintf('Uploaded content exceeds the maximum size of %d bytes.', $maxBytes));
        }

        $tempFile = GeneralUtility::tempnam('ai_media_');
        if (false === file_put_contents($tempFile, $binary)) {
            @unlink($tempFile);

            throw new \RuntimeException('Could not write the uploaded content to a temporary file.');
        }

        $mimeType = mime_content_type($tempFile);
        if (false === $mimeType || '' === $mimeType) {
            $mimeType = $declaredMime;
        }

        return new FetchedMedia($tempFile, $mimeType, strlen($binary), '');
    }

    public function transformOnlineMediaUrl(string $url, Folder $folder): ?File
    {
        $file = $this->onlineMediaHelperRegistry->transformUrlToFile($url, $folder, $this->onlineMediaHelperRegistry->getSupportedFileExtensions());

        return $file instanceof File ? $file : null;
    }

    public function storeTempFile(Folder $folder, string $tempFilePath, string $baseName, string $extension, bool $replace = false): StoredMedia
    {
        $targetFileName = $baseName.'.'.$extension;
        $outcome = StoredMedia::CREATED;
        if ($folder->hasFile($targetFileName)) {
            if ($replace) {
                $existing = $folder->getFile($targetFileName);
                if (!$existing instanceof File) {
                    throw RefusedRequestException::invalidParameter(sprintf('"%s" exists but is not a regular file and cannot be replaced.', $targetFileName));
                }
                $folder->getStorage()->replaceFile($existing, $tempFilePath);

                return new StoredMedia($existing, StoredMedia::REPLACED);
            }
            $targetFileName = $baseName.'-'.bin2hex(random_bytes(4)).'.'.$extension;
            $outcome = StoredMedia::RENAMED;
        }

        $file = $folder->getStorage()->addFile($tempFilePath, $folder, $targetFileName);
        if (!$file instanceof File) {
            throw new \RuntimeException('Storing the file returned an unexpected type.');
        }

        return new StoredMedia($file, $outcome);
    }

    /**
     * @param array<string, string> $metadata
     */
    public function applyMetadata(File $file, array $metadata): void
    {
        $changed = false;
        $metaData = $file->getMetaData();
        foreach ($metadata as $field => $value) {
            if ('' !== trim($value)) {
                $metaData->offsetSet($field, $value);
                $changed = true;
            }
        }

        if ($changed) {
            $metaData->save();
        }
    }

    /**
     * @throws \RuntimeException
     */
    public function resolveExtension(string $mimeType, string $url, string $fileName): string
    {
        $byMime = match (strtolower(trim(explode(';', $mimeType)[0]))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg', 'application/ogg' => 'ogg',
            default => '',
        };
        if ('' !== $byMime) {
            return $byMime;
        }

        foreach ([$fileName, $url] as $candidate) {
            $ext = strtolower((string) pathinfo(parse_url($candidate, PHP_URL_PATH) ?: $candidate, PATHINFO_EXTENSION));
            if ('' !== $ext) {
                return $ext;
            }
        }

        throw RefusedRequestException::invalidParameter('Could not determine the file type. Provide a fileName with an extension.');
    }

    public function resolveBaseName(string $fileName, string $url, string $fallbackTitle): string
    {
        $fileName = trim($fileName);
        if ('' === $fileName && '' !== $url) {
            $fileName = (string) pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_FILENAME);
        }
        if ('' === $fileName) {
            $fileName = '' !== trim($fallbackTitle) ? trim($fallbackTitle) : 'uploaded-media-'.bin2hex(random_bytes(4));
        }

        $fileName = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $fileName);

        return FileNameSanitizerService::sanitize($fileName);
    }

    public function normalizeFolderIdentifier(string $targetFolder): string
    {
        $combined = $targetFolder;
        if (!preg_match('/^\d+:/', $combined)) {
            $combined = '1:'.$combined;
        }
        [$storagePrefix, $folderPath] = explode(':', $combined, 2);
        $folderPath = '/'.trim($folderPath, '/').'/';
        $folderPath = (string) preg_replace('#/+#', '/', $folderPath);

        return $storagePrefix.':'.$folderPath;
    }

    /**
     * @param list<string> $extraDenyList
     *
     * @throws \RuntimeException
     */
    public function assertUrlIsSafe(string $url, array $extraDenyList = []): void
    {
        $this->checkedTarget($url, $extraDenyList);
    }

    /**
     * @param list<string> $extraDenyList
     *
     * @return array{host: string, port: int, ips: list<string>}
     *
     * @throws \RuntimeException
     */
    private function checkedTarget(string $url, array $extraDenyList): array
    {
        try {
            return $this->publicUrlGuard->check($url, $extraDenyList);
        } catch (UnsafeUrlException $e) {
            $this->reject($e->getMessage(), ['url' => $url]);
        } catch (\InvalidArgumentException $e) {
            throw RefusedRequestException::invalidParameter($e->getMessage());
        }
    }

    private function redirectTarget(string $base, string $location): string
    {
        try {
            return $this->publicUrlGuard->resolveRedirectTarget($base, $location);
        } catch (\InvalidArgumentException $e) {
            throw RefusedRequestException::invalidParameter($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reject(string $message, array $context): never
    {
        $this->logger->warning('RemoteMediaService blocked a media URL: '.$message, $context);

        throw new InsufficientPermissionException($message);
    }

    private function streamToTempFile(ResponseInterface $response, string $url, int $maxBytes): FetchedMedia
    {
        $declaredLength = (int) $response->getHeaderLine('Content-Length');
        if ($maxBytes > 0 && $declaredLength > $maxBytes) {
            throw RefusedRequestException::invalidParameter(sprintf('Remote file is too large: %d bytes (max %d).', $declaredLength, $maxBytes));
        }

        $tempFile = GeneralUtility::tempnam('ai_media_');
        $handle = fopen($tempFile, 'wb');
        if (false === $handle) {
            @unlink($tempFile);

            throw new \RuntimeException('Could not open a temporary file for the download.');
        }

        $written = 0;
        $body = $response->getBody();

        try {
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_SIZE);
                if ('' === $chunk) {
                    break;
                }
                $written += strlen($chunk);
                if ($maxBytes > 0 && $written > $maxBytes) {
                    throw RefusedRequestException::invalidParameter(sprintf('Remote file exceeds the maximum size of %d bytes.', $maxBytes));
                }
                fwrite($handle, $chunk);
            }
        } catch (\RuntimeException $e) {
            fclose($handle);
            @unlink($tempFile);

            throw $e;
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tempFile);

            throw RefusedRequestException::invalidParameter('Failed while downloading the media file.', $e);
        }
        fclose($handle);

        if (0 === $written) {
            @unlink($tempFile);

            throw RefusedRequestException::invalidParameter('Downloaded file is empty.');
        }

        $mimeType = mime_content_type($tempFile);
        if (false === $mimeType || '' === $mimeType) {
            $mimeType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0]);
        }

        return new FetchedMedia($tempFile, $mimeType, $written, $url);
    }
}
