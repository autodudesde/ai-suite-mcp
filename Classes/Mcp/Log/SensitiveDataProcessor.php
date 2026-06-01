<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Log;

use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Processor\AbstractProcessor;

class SensitiveDataProcessor extends AbstractProcessor
{
    /**
     * @var array<string, string> regex pattern (with delimiters) => replacement
     */
    private array $patterns = [
        '/Bearer\s+[A-Za-z0-9._\-]+/' => 'Bearer [REDACTED]',
        '/\b[0-9a-f]{64}\b/' => '[REDACTED-HASH64]',
        '/\b[\w.+\-]+@[\w\-]+\.[\w\-.]+\b/' => '[REDACTED-EMAIL]',
    ];

    /**
     * @param list<string> $additionalPatterns
     */
    public function setAdditionalPatterns(array $additionalPatterns): void
    {
        foreach ($additionalPatterns as $rawPattern) {
            $rawPattern = is_string($rawPattern) ? trim($rawPattern) : '';
            if ('' === $rawPattern) {
                continue;
            }
            $delimited = '/'.$rawPattern.'/';
            if (false === @preg_match($delimited, '')) {
                continue;
            }
            $this->patterns[$delimited] = '[REDACTED]';
        }
    }

    public function processLogRecord(LogRecord $logRecord): LogRecord
    {
        $logRecord->setMessage($this->redact($logRecord->getMessage()));
        $logRecord->setData($this->redactData($logRecord->getData()));

        return $logRecord;
    }

    private function redact(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        $result = preg_replace(array_keys($this->patterns), array_values($this->patterns), $value);

        return is_string($result) ? $result : $value;
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    private function redactData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_array($value)) {
                // @var array<int|string, mixed> $value
                $data[$key] = $this->redactData($value);
            }
        }

        return $data;
    }
}
