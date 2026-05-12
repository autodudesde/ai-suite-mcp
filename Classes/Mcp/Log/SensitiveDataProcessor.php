<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Log;

use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Processor\AbstractProcessor;

/**
 * Log processor that redacts sensitive substrings from log messages and structured data
 * before they are written to the dedicated MCP log file.
 *
 * Built-in patterns cover the cases that show up routinely:
 * - `Bearer <token>` headers logged accidentally
 * - 64-character hex strings (SHA-256 hashes of tokens, raw `bin2hex(random_bytes(32))`
 *   authorization codes before hashing)
 * - email addresses (often appear in OAuth user-info / generation prompts)
 *
 * Additional patterns can be added via `mcpLogRedactionPatterns` in the extension
 * configuration (comma-separated regex bodies without delimiters; each gets the
 * generic `[REDACTED]` replacement).
 *
 * Activation lives in `ext_localconf.php` via `processorConfiguration`. The processor
 * runs at LogLevel::DEBUG so it sees every record regardless of writer level.
 */
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
     * Setter invoked by {@see AbstractProcessor::__construct} from `processorConfiguration` options.
     * Each entry is a raw regex body (no delimiters); it is wrapped with `/.../` and added
     * to the built-in patterns with a generic `[REDACTED]` replacement.
     *
     * Invalid patterns are silently skipped — we never want a misconfigured redaction
     * rule to crash logging itself.
     *
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
            // Validate the pattern by running it against an empty string. preg_match
            // returns false on syntax error and warns; we suppress the warning, the
            // false return tells us to skip.
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
