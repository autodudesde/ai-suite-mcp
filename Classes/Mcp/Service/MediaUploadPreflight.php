<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\MediaUploadPlan;
use AutoDudes\AiSuiteMcp\Domain\Model\Dto\TargetFolderPlan;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\RefusedRequestException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

final class MediaUploadPreflight
{
    public function __construct(
        private readonly RemoteMediaService $remoteMediaService,
        private readonly RecordAccessService $recordAccess,
        private readonly StorageRepository $storageRepository,
    ) {}

    /**
     * @throws InvalidParameterException
     * @throws \RuntimeException
     */
    public function plan(mixed $item, string $batchFolder, string $batchConflictMode, bool $batchCreateFolder, bool $batchAllowSameNameFolder = false): MediaUploadPlan
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
        $allowSameNameFolder = isset($item['allowSameNameFolder']) ? (bool) $item['allowSameNameFolder'] : $batchAllowSameNameFolder;
        $folder = $this->resolveTargetFolder($targetFolder, $createFolder, $allowSameNameFolder);

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
    public function resolveTargetFolder(string $targetFolder, bool $createFolder, bool $allowSameNameFolder = false): TargetFolderPlan
    {
        $identifier = $this->remoteMediaService->normalizeFolderIdentifier($targetFolder);

        [$storageUid, $path] = explode(':', $identifier, 2);
        $storage = $this->storageOf($storageUid);
        $existing = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => '' !== $segment));
        $missing = [];
        while ([] !== $existing && !$this->folderExists($storage, '/'.implode('/', $existing).'/')) {
            array_unshift($missing, (string) array_pop($existing));
        }
        if ([] !== $missing && !$allowSameNameFolder) {
            $existingPath = '/'.([] === $existing ? '' : implode('/', $existing).'/');
            $sameName = $this->sameNameFolder($storage, $storageUid, $existingPath, $missing);
            if (null !== $sameName) {
                throw RefusedRequestException::invalidParameter(sprintf(
                    'Target folder %s would create a new folder "%s", but a folder of that name already exists at %s. '
                    .'Use %s instead (with createFolder: true for the part still missing), or pass allowSameNameFolder: true if a second folder of that name is really wanted.',
                    $identifier,
                    $missing[0],
                    $sameName['existing'],
                    $sameName['suggested'],
                ));
            }
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

    /**
     * @param non-empty-list<string> $missing
     *
     * @return null|array{existing: string, suggested: string}
     */
    private function sameNameFolder(ResourceStorage $storage, string $storageUid, string $existingPath, array $missing): ?array
    {
        $parents = ['/'];

        try {
            $parents[] = $storage->getDefaultFolder()->getIdentifier();
        } catch (\Throwable) {
            // A storage without a usable default folder is only checked at its root.
        }

        foreach (array_unique($parents) as $parent) {
            $parent = '/'.trim($parent, '/').('/' === $parent ? '' : '/');
            $candidate = $parent.$missing[0].'/';
            if ($parent === $existingPath || !$this->folderExists($storage, $candidate)) {
                continue;
            }

            try {
                $this->recordAccess->assertFolderWriteAccess($storageUid.':'.$candidate);
            } catch (\Throwable) {
                continue;
            }

            return ['existing' => $storageUid.':'.$candidate, 'suggested' => $storageUid.':'.$parent.implode('/', $missing).'/'];
        }

        return null;
    }

    private function storageOf(string $storageUid): ResourceStorage
    {
        return $this->storageRepository->getStorageObject(max(0, (int) $storageUid));
    }

    /**
     * @throws InsufficientPermissionException
     */
    private function folderExists(ResourceStorage $storage, string $path): bool
    {
        try {
            return $storage->hasFolder($path);
        } catch (InsufficientFolderAccessPermissionsException $e) {
            throw new InsufficientPermissionException($e->getMessage(), 0, $e);
        }
    }
}
