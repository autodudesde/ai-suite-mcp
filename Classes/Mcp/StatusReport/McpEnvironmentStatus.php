<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\StatusReport;

use Mcp\Server\Server;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Reports\Status;
use TYPO3\CMS\Reports\StatusProviderInterface;

class McpEnvironmentStatus implements StatusProviderInterface
{
    public const REQUIRED_PHP_EXTENSIONS = ['curl', 'json'];

    /**
     * @return list<Status>
     */
    public function getStatus(): array
    {
        $problems = [];

        $missing = array_values(array_filter(
            self::REQUIRED_PHP_EXTENSIONS,
            fn (string $extension): bool => !$this->isPhpExtensionLoaded($extension),
        ));
        if ([] !== $missing) {
            $problems[] = sprintf(
                'The PHP extension(s) %s are missing. The MCP server cannot answer requests until they are installed.',
                implode(', ', $missing),
            );
        }

        if (!$this->isSdkLoaded()) {
            $problems[] = 'The MCP SDK (logiscape/mcp-sdk-php) cannot be loaded. Composer installations require the package, '
                .'classic mode installations ship it in Resources/Private/PHP/ComposerVendor of ai_suite_mcp, '
                .'so an incompletely uploaded extension is the usual cause.';
        }

        if ([] === $problems) {
            return [new Status(
                'MCP Environment',
                'All requirements met',
                sprintf('The PHP extensions %s are loaded and the MCP SDK is available.', implode(', ', self::REQUIRED_PHP_EXTENSIONS)),
                ContextualFeedbackSeverity::OK,
            )];
        }

        return [new Status(
            'MCP Environment',
            'Requirements missing',
            implode(' ', $problems),
            ContextualFeedbackSeverity::ERROR,
        )];
    }

    public function getLabel(): string
    {
        return 'AI Suite MCP Environment';
    }

    protected function isPhpExtensionLoaded(string $extension): bool
    {
        return extension_loaded($extension);
    }

    protected function isSdkLoaded(): bool
    {
        return class_exists(Server::class);
    }
}
