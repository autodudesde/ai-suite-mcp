<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\DataHandlerException;

class DataHandlerErrorFormatter
{
    /**
     * @param array<int|string, mixed> $errorLog the DataHandler::$errorLog array
     */
    public function toException(string $operation, string $table, ?int $uid, array $errorLog): DataHandlerException
    {
        $joined = $this->joinLog($errorLog);
        $subject = null !== $uid ? sprintf('%s:%d', $table, $uid) : $table;
        $message = sprintf('%s failed for %s: %s', ucfirst($operation), $subject, $joined);

        $context = ['table' => $table];
        if (null !== $uid) {
            $context['uid'] = $uid;
        }

        return (new DataHandlerException($message))
            ->withErrorType($this->classify($joined))
            ->withErrorContext($context)
        ;
    }

    /**
     * @param array<int|string, mixed> $errorLog
     */
    public function joinLog(array $errorLog): string
    {
        $messages = [];
        foreach ($errorLog as $entry) {
            $text = trim((string) $entry);
            if ('' !== $text) {
                $messages[] = $text;
            }
        }

        return [] !== $messages ? implode(' | ', $messages) : 'unknown DataHandler error';
    }

    private function classify(string $joined): McpErrorType
    {
        if (1 === preg_match('/attempt to (modify|insert|delete|move|copy)|no permission|not allowed|access denied|recordEditAccessInternals/i', $joined)) {
            return McpErrorType::InsufficientPermission;
        }

        return McpErrorType::DataHandlerError;
    }
}
