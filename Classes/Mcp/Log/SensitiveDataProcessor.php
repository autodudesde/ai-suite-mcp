<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Log;

use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Processor\AbstractProcessor;

class SensitiveDataProcessor extends AbstractProcessor
{
    public const FAILED = '[REDACTION-FAILED]';

    private const SENSITIVE_KEYS = [
        'authorization',
        'password',
        'passwd',
        'code',
        'code_verifier',
        'access_token',
        'refresh_token',
        'client_secret',
        'ticket',
        'consent_ticket',
        'token',
    ];

    /**
     * @var array<string, string>
     */
    private array $patterns = [
        '/Bearer\s+[A-Za-z0-9._\-]+/' => 'Bearer [REDACTED]',
        '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/' => '[REDACTED-JWT]',
        '/\b[0-9a-f]{64}\b/' => '[REDACTED-HASH64]',
        '/(?<![A-Za-z0-9_\-])(?=[A-Za-z0-9_\-]*[0-9])(?=[A-Za-z0-9_\-]*[A-Z])(?=[A-Za-z0-9_\-]*[a-z])[A-Za-z0-9_\-]{40,}(?![A-Za-z0-9_\-])/' => '[REDACTED-TOKEN]',
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

        foreach ($this->patterns as $pattern => $replacement) {
            $value = preg_replace($pattern, $replacement, $value);
            if (!is_string($value)) {
                return self::FAILED;
            }
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<int|string, mixed>
     */
    private function redactData(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true) && ((is_string($value) && '' !== $value) || is_array($value))) {
                $data[$key] = '[REDACTED]';
            } elseif (is_string($value)) {
                $data[$key] = $this->redact($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->redactData($value);
            } elseif ($value instanceof \Throwable) {
                $data[$key] = $this->describeThrowable($value);
            }
        }

        return $data;
    }

    private function describeThrowable(\Throwable $throwable): string
    {
        return $this->redact(sprintf('%s: %s (%s:%d)', $throwable::class, $throwable->getMessage(), $throwable->getFile(), $throwable->getLine()));
    }
}
