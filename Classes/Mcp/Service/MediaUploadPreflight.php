<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\MediaUploadPlan;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\TargetFolderPlan;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RefusedRequestException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;

final class MediaUploadPreflight
{
    public function __construct(
        private readonly RemoteMediaService $remoteMediaService,
        private readonly RecordAccessService $recordAccess,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * @throws InvalidParameterException
     * @throws \RuntimeException
     */
    public function plan(mixed $item, string $batchFolder, string $batchConflictMode, bool $batchCreateFolder): MediaUploadPlan
    {
        if (!is_array($item)) {
            throw new InvalidParameterException('Skipped (not an object).');
        }

        $url = trim((string) ($item['url'] ?? ''));
        $content = (string) ($item['content'] ?? '');
        if ('' !== $url && '' !== $content) {
            throw RefusedRequestException::invalidParameter('Provide either url or content, not both.');
        }
        if ('' === $url && '' === $content) {
            throw RefusedRequestException::invalidParameter('Provide a url or base64 content.');
        }

        $replace = 'replace' === (isset($item['onConflict']) ? $this->resolveConflictMode($item['onConflict']) : $batchConflictMode);
        $createFolder = isset($item['createFolder']) ? (bool) $item['createFolder'] : $batchCreateFolder;
        $targetFolder = '' !== (string) ($item['targetFolder'] ?? '') ? (string) $item['targetFolder'] : $batchFolder;
        $folder = $this->resolveTargetFolder($targetFolder, $createFolder);

        return new MediaUploadPlan(
            $url,
            $content,
            trim((string) ($item['fileName'] ?? '')),
            $folder->identifier,
            $folder->writableFolder,
            $folder->missingFolders,
            $replace,
        );
    }

    /**
     * @throws \RuntimeException
     */
    public function resolveTargetFolder(string $targetFolder, bool $createFolder): TargetFolderPlan
    {
        $identifier = $this->remoteMediaService->normalizeFolderIdentifier($targetFolder);

        [$storageUid, $path] = explode(':', $identifier, 2);
        $storage = $this->storageOf($storageUid);
        $existing = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment));
        $missing = [];
        while ([] !== $existing && !$storage->hasFolder('/'.implode('/', $existing).'/')) {
            array_unshift($missing, (string) array_pop($existing));
        }
        if ([] !== $missing && !$createFolder) {
            throw RefusedRequestException::invalidParameter(sprintf('Target folder %s does not exist. Set createFolder: true to create it.', $identifier));
        }

        $writableFolder = $this->recordAccess->assertFolderWriteAccess(
            $storageUid.':/'.([] === $existing ? '' : implode('/', $existing).'/'),
        );

        return new TargetFolderPlan($identifier, $writableFolder, $missing);
    }

    /**
     * @param list<string> $missingFolders
     */
    public function createMissingFolders(Folder $writableFolder, array $missingFolders): Folder
    {
        $folder = $writableFolder;
        foreach ($missingFolders as $name) {
            $folder = $folder->createFolder($name);
        }

        return $folder;
    }

    /**
     * @throws InvalidParameterException
     */
    public function resolveConflictMode(mixed $value): string
    {
        $mode = strtolower(trim((string) $value));
        if (!in_array($mode, ['rename', 'replace'], true)) {
            throw new InvalidParameterException(sprintf('onConflict must be "rename" or "replace", got "%s".', $mode));
        }

        return $mode;
    }

    private function storageOf(string $storageUid): ResourceStorage
    {
        return $this->resourceFactory->getFolderObjectFromCombinedIdentifier($storageUid.':/')->getStorage();
    }
}
