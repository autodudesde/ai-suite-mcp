<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool\Media;

use AutoDudes\AiSuiteMcp\Domain\Model\Dto\TargetFolderPlan;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\SkippedItemException;
use AutoDudes\AiSuiteMcp\Mcp\Service\BatchResultBuilderService;
use AutoDudes\AiSuiteMcp\Mcp\Service\MediaUploadPreflight;
use AutoDudes\AiSuiteMcp\Mcp\Tool\AbstractTool;
use AutoDudes\AiSuiteMcp\Mcp\Tool\ToolContext;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Resource\Exception as ResourceException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientUserPermissionsException;
use TYPO3\CMS\Core\Resource\Folder;

class MoveFileTool extends AbstractTool
{
    protected ?string $requiredScope = 'mcp:media';
    protected bool $destructiveHint = true;

    public function __construct(
        ToolContext $mcpToolContext,
        private readonly MediaUploadPreflight $preflight,
        private readonly BatchResultBuilderService $batchResultBuilder,
    ) {
        parent::__construct($mcpToolContext);
    }

    public function getName(): string
    {
        return 'moveFile';
    }

    public function getDescription(): string
    {
        return 'Move one or more files into another folder of the same storage (writes). The file keeps its UID, '
            .'so every reference and link stays valid; a name already taken in the target folder gets a suffix.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fileUids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'sys_file UIDs to move.'],
                'targetFolder' => ['type' => 'string', 'description' => 'Target folder as combined identifier, e.g. "1:/user_upload/ai-images/".'],
                'createFolder' => ['type' => 'boolean', 'default' => false, 'description' => 'Create a missing target folder (and missing parents) instead of failing.'],
                'allowSameNameFolder' => ['type' => 'boolean', 'default' => false, 'description' => 'Only when the editor explicitly asked for a second folder of the same name: create it although one exists at the storage root or in the default upload folder.'],
            ],
            'required' => ['fileUids', 'targetFolder'],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $fileUids = array_values(array_filter(
            array_map('intval', is_array($params['fileUids'] ?? null) ? $params['fileUids'] : []),
            static fn (int $uid): bool => $uid > 0,
        ));
        if ([] === $fileUids) {
            return $this->textError('fileUids must name at least one sys_file UID.');
        }

        $plan = $this->preflight->resolveTargetFolder((string) $params['targetFolder'], (bool) ($params['createFolder'] ?? false), (bool) ($params['allowSameNameFolder'] ?? false));
        $targetFolder = null;

        $outcome = $this->batchResultBuilder->build($fileUids, 'file(s)', function (mixed $fileUid) use ($plan, &$targetFolder): array {
            $file = $this->recordAccess->assertFileReadAccess((int) $fileUid);
            $sourceFolder = $file->getParentFolder()->getCombinedIdentifier();
            if ([] === $plan->missingFolders && $sourceFolder === $plan->identifier) {
                throw new SkippedItemException(sprintf('%s (UID: %d) is already in %s.', $file->getName(), $file->getUid(), $plan->identifier));
            }
            if ($file->getStorage()->getUid() !== $plan->writableFolder->getStorage()->getUid()) {
                throw new InvalidParameterException(sprintf('%s (UID: %d) lies in another storage; a file can only be moved within its own storage.', $file->getName(), $file->getUid()));
            }

            $targetFolder ??= $this->targetFolder($plan);
            $originalName = $file->getName();

            try {
                $moved = $file->getStorage()->moveFile($file, $targetFolder);
            } catch (ResourceException $e) {
                $message = sprintf('%s (UID: %d) cannot be moved to %s: %s', $originalName, $file->getUid(), $plan->identifier, $e->getMessage());

                throw $e instanceof InsufficientFolderAccessPermissionsException || $e instanceof InsufficientUserPermissionsException
                    ? new InsufficientPermissionException($message, 0, $e)
                    : new InvalidParameterException($message, 0, $e);
            }

            $metadataUid = (int) ($file->getMetaData()->get()['uid'] ?? 0);

            return [
                'message' => sprintf(
                    '%s (UID: %d) moved from %s to %s%s',
                    $originalName,
                    $file->getUid(),
                    $sourceFolder,
                    $targetFolder->getCombinedIdentifier(),
                    $moved->getName() !== $originalName ? sprintf(', renamed to %s', $moved->getName()) : '',
                ),
                'uid' => $file->getUid(),
            ] + ($metadataUid > 0 ? ['table' => 'sys_file_metadata', 'recordUid' => $metadataUid, 'action' => 'update'] : []);
        }, workspaceContained: false);

        return new CallToolResult(
            [new TextContent($outcome->text)],
            isError: $outcome->hadError(),
            structuredContent: $this->batchResultBuilder->structuredFor($outcome),
        );
    }

    private function targetFolder(TargetFolderPlan $plan): Folder
    {
        return $this->preflight->createMissingFolders($plan->writableFolder, $plan->missingFolders);
    }
}
