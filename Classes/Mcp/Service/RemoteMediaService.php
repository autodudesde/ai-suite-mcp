<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Service\FileNameSanitizerService;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\FetchedMedia;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteMediaService
{
    private const MAX_REDIRECTS = 5;
    private const CHUNK_SIZE = 8192;

    /**
     * @var list<string>
     */
    private const BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::1/128', '::/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '::ffff:0:0/96', '64:ff9b::/96',
    ];

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param int          $maxBytes      hard size cap in bytes; 0 = no explicit cap
     * @param list<string> $extraDenyList additional hostnames / IPs / CIDRs to block
     *
     * @throws \RuntimeException on any unsafe or failed fetch (message is safe to surface)
     */
    public function fetch(string $url, int $maxBytes = 0, array $extraDenyList = [], int $timeoutSeconds = 30): FetchedMedia
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $this->assertUrlIsSafe($current, $extraDenyList);

            $response = $this->requestFactory->request($current, 'GET', [
                'allow_redirects' => false,
                'stream' => true,
                'timeout' => $timeoutSeconds,
                'headers' => [
                    'Accept' => '*/*',
                    'User-Agent' => 'AiSuiteMcp-MediaFetch',
                ],
            ]);

            $status = $response->getStatusCode();

            if ($status >= 300 && $status < 400 && $response->hasHeader('Location')) {
                $current = $this->resolveRedirectTarget($current, $response->getHeaderLine('Location'));

                continue;
            }

            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Remote server returned HTTP %d for the media URL.', $status));
            }

            return $this->streamToTempFile($response, $current, $maxBytes);
        }

        throw new \RuntimeException('Too many redirects while fetching the media URL.');
    }

    /**
     * @param int $maxBytes hard size cap in bytes; 0 = no explicit cap
     *
     * @throws \RuntimeException on invalid or oversized content
     */
    public function decodeBase64ToTempFile(string $content, int $maxBytes = 0): FetchedMedia
    {
        $declaredMime = '';
        if (preg_match('#^data:([^;]+);base64,#i', $content, $matches)) {
            $declaredMime = strtolower($matches[1]);
            $content = substr($content, strlen($matches[0]));
        }

        $binary = base64_decode(trim($content), true);
        if (false === $binary || '' === $binary) {
            throw new \RuntimeException('Invalid base64 content.');
        }

        if ($maxBytes > 0 && strlen($binary) > $maxBytes) {
            throw new \RuntimeException(sprintf('Uploaded content exceeds the maximum size of %d bytes.', $maxBytes));
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
        $registry = GeneralUtility::makeInstance(OnlineMediaHelperRegistry::class);
        $file = $registry->transformUrlToFile($url, $folder, $registry->getSupportedFileExtensions());

        return $file instanceof File ? $file : null;
    }

    public function storeTempFile(Folder $folder, string $tempFilePath, string $baseName, string $extension): File
    {
        $targetFileName = $baseName.'.'.$extension;
        if ($folder->hasFile($targetFileName)) {
            $targetFileName = $baseName.'-'.bin2hex(random_bytes(4)).'.'.$extension;
        }

        $file = $folder->getStorage()->addFile($tempFilePath, $folder, $targetFileName);
        if (!$file instanceof File) {
            throw new \RuntimeException('Storing the file returned an unexpected type.');
        }

        return $file;
    }

    /**
     * @param array<string, string> $metadata sys_file_metadata field => value (empty values are skipped)
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
     * @throws \RuntimeException when no extension can be determined
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

        throw new \RuntimeException('Could not determine the file type. Provide a fileName with an extension.');
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
     * @throws \RuntimeException when the URL or any resolved target is not allowed
     */
    public function assertUrlIsSafe(string $url, array $extraDenyList = []): void
    {
        $parts = parse_url($url);
        if (false === $parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('Invalid media URL.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            $this->reject(sprintf('Unsupported URL scheme "%s" — only http/https are allowed.', $scheme), ['url' => $url]);
        }

        $hostName = trim((string) $parts['host'], '[]');

        [$denyHosts, $denyCidrs] = $this->splitDenyList($extraDenyList);
        if (in_array(strtolower($hostName), $denyHosts, true)) {
            $this->reject('Target host is blocked by configuration.', ['host' => $hostName]);
        }

        $ips = $this->resolveHostToIps($hostName);
        if ([] === $ips) {
            throw new \RuntimeException(sprintf('Could not resolve host "%s".', $hostName));
        }

        foreach ($ips as $ip) {
            $this->assertIpIsPublic($ip, $hostName);
            foreach ($denyCidrs as $cidr) {
                if ($this->ipMatchesCidr($ip, $cidr)) {
                    $this->reject('Target address is blocked by configuration.', ['host' => $hostName, 'ip' => $ip, 'cidr' => $cidr]);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reject(string $message, array $context): never
    {
        $this->logger->warning('RemoteMediaService blocked a media URL: '.$message, $context);

        throw new \RuntimeException($message);
    }

    private function streamToTempFile(ResponseInterface $response, string $url, int $maxBytes): FetchedMedia
    {
        $declaredLength = (int) $response->getHeaderLine('Content-Length');
        if ($maxBytes > 0 && $declaredLength > $maxBytes) {
            throw new \RuntimeException(sprintf('Remote file is too large: %d bytes (max %d).', $declaredLength, $maxBytes));
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
                    throw new \RuntimeException(sprintf('Remote file exceeds the maximum size of %d bytes.', $maxBytes));
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

            throw new \RuntimeException('Failed while downloading the media file.', 0, $e);
        }
        fclose($handle);

        if (0 === $written) {
            @unlink($tempFile);

            throw new \RuntimeException('Downloaded file is empty.');
        }

        $mimeType = mime_content_type($tempFile);
        if (false === $mimeType || '' === $mimeType) {
            $mimeType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0]);
        }

        return new FetchedMedia($tempFile, $mimeType, $written, $url);
    }

    /**
     * @param list<string> $entries
     *
     * @return array{0: list<string>, 1: list<string>} [hostNames, ipsAndCidrs]
     */
    private function splitDenyList(array $entries): array
    {
        $hosts = [];
        $cidrs = [];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ('' === $entry) {
                continue;
            }
            if (str_contains($entry, '/') || false !== filter_var($entry, FILTER_VALIDATE_IP)) {
                $cidrs[] = $entry;
            } else {
                $hosts[] = strtolower($entry);
            }
        }

        return [$hosts, $cidrs];
    }

    /**
     * @return list<string>
     */
    private function resolveHostToIps(string $host): array
    {
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @throws \RuntimeException when the IP is not a public, routable address
     */
    private function assertIpIsPublic(string $ip, string $host): void
    {
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException(sprintf('Invalid target IP address "%s".', $ip));
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if ($this->ipMatchesCidr($ip, $cidr)) {
                $this->reject(
                    sprintf('Target address %s is not allowed (private, loopback, link-local or reserved range).', $ip),
                    ['host' => $host, 'ip' => $ip, 'cidr' => $cidr],
                );
            }
        }

        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->reject(
                sprintf('Target address %s is not allowed (private or reserved range).', $ip),
                ['host' => $host, 'ip' => $ip],
            );
        }
    }

    private function ipMatchesCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            $left = @inet_pton($ip);
            $right = @inet_pton($cidr);

            return false !== $left && false !== $right && $left === $right;
        }

        [$subnet, $maskLen] = explode('/', $cidr, 2);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if (false === $ipBin || false === $subnetBin || strlen($ipBin) !== strlen($subnetBin)) {
            // Different address families never match.
            return false;
        }

        $maskLen = (int) $maskLen;
        $fullBytes = intdiv($maskLen, 8);
        $remainderBits = $maskLen % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits > 0) {
            $mask = 0xFF << (8 - $remainderBits) & 0xFF;

            return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
        }

        return true;
    }

    private function resolveRedirectTarget(string $base, string $location): string
    {
        $location = trim($location);
        if ('' === $location) {
            throw new \RuntimeException('Redirect without a target location.');
        }

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        if (false === $parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \RuntimeException('Cannot resolve a relative redirect.');
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $basePath = isset($parts['path']) ? (string) preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';

        return $origin.$basePath.$location;
    }
}
