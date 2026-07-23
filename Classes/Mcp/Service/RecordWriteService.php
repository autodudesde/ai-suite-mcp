<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuite\Domain\Repository\BackgroundTaskRepository;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Domain\Repository\RecordRepository;
use AutoDudes\AiSuiteMcp\Mcp\Dto\RecordWriteResult;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RecordWriteService
{
    public function __construct(
        private readonly DataHandlerSanitizerService $dataHandlerSanitizer,
        private readonly DataHandlerErrorFormatter $dataHandlerError,
        private readonly RecordRepository $recordRepository,
        private readonly TcaCompatibilityService $tcaCompatibilityService,
        private readonly BackgroundTaskRepository $backgroundTaskRepository,
        private readonly RecordAccessService $recordAccess,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $fields
     */
    public function create(string $table, int $pid, array $fields, string $position = 'end'): RecordWriteResult
    {
        $this->recordAccess->assertKnownRecordType($table, $fields);

        $report = $this->dataHandlerSanitizer->sanitizeFieldsWithReport($table, $fields, null, ['uid' => 0, 'pid' => $pid]);
        $fields = $report['data'];
        $newId = 'NEW'.substr(md5((string) time().random_int(0, 100000)), 0, 22);

        $fields['pid'] = $this->resolvePosition($table, $pid, $fields, $position);

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([$table => [$newId => $fields]], []);
        $dh->process_datamap();

        if ([] !== $dh->errorLog) {
            throw $this->dataHandlerError->toException('create', $table, null, $dh->errorLog);
        }

        return new RecordWriteResult((int) ($dh->substNEWwithIDs[$newId] ?? 0), $report['stripped']);
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(string $table, int $uid, array $fields): RecordWriteResult
    {
        $record = BackendUtility::getRecordWSOL($table, $uid);
        if (null === $record) {
            throw (new InvalidParameterException(sprintf('%s:%d not found.', $table, $uid)))
                ->withErrorType(McpErrorType::NotFound)
                ->withErrorContext(['table' => $table, 'uid' => $uid])
            ;
        }

        // An update that flips CType to a hallucinated value never reaches findMissingRequiredFields,
        // so the assert has to run here too.
        $this->recordAccess->assertKnownRecordType($table, $fields);

        $typeKey = null;

        try {
            $typeKey = $this->tcaCompatibilityService->resolveSubSchemaType($table, $fields + $record);
        } catch (\Throwable $e) {
            $this->logger->warning('RecordWrite: could not resolve record type for sanitizing, using base config', [
                'table' => $table,
                'uid' => $uid,
                'error' => $e->getMessage(),
            ]);
        }
        $report = $this->dataHandlerSanitizer->sanitizeFieldsWithReport($table, $fields, $typeKey, $record);
        $fields = $report['data'];

        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([$table => [$uid => $fields]], []);
        $dh->process_datamap();

        if ([] !== $dh->errorLog) {
            throw $this->dataHandlerError->toException('update', $table, $uid, $dh->errorLog);
        }

        $this->cleanupBackgroundTasks($table, $uid, $fields);

        return new RecordWriteResult($uid, $report['stripped']);
    }

    public function delete(string $table, int $uid): void
    {
        $dh = GeneralUtility::makeInstance(DataHandler::class);
        $dh->start([], [$table => [$uid => ['delete' => 1]]]);
        $dh->process_cmdmap();

        if ([] !== $dh->errorLog) {
            throw $this->dataHandlerError->toException('rollback delete', $table, $uid, $dh->errorLog);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function resolvePosition(string $table, int $pageId, array $fields, string $position): int
    {
        if ('start' === $position) {
            return $pageId;
        }

        if (str_starts_with($position, 'after:')) {
            $afterUid = (int) substr($position, 6);
            if ($afterUid > 0) {
                return -$afterUid;
            }
        }

        $sortByField = $this->tcaCompatibilityService->getRawConfiguration($table)['sortby'] ?? '';
        if ('end' === $position && '' !== $sortByField) {
            $colPos = ('tt_content' === $table && isset($fields['colPos'])) ? (int) $fields['colPos'] : null;
            $lastUid = $this->recordRepository->findLastUidOnPage($table, $pageId, $sortByField, $colPos);
            if (null !== $lastUid) {
                return -$lastUid;
            }
        }

        return $pageId;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function cleanupBackgroundTasks(string $table, int $uid, array $fields): void
    {
        $uuidsToDelete = [];
        foreach (array_keys($fields) as $column) {
            $tasks = $this->backgroundTaskRepository->findFinishedTasksByRecord($table, $uid, (string) $column);
            foreach ($tasks as $task) {
                $uuidsToDelete[] = (string) $task['uuid'];
            }
        }

        if (!empty($uuidsToDelete)) {
            $this->backgroundTaskRepository->deleteByUuids($uuidsToDelete);
        }
    }
}
