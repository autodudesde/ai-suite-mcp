<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Enum;

use TYPO3\CMS\Backend\Routing\UriBuilder;

enum LinkStyle: string
{
    case Session = 'session';
    case Shareable = 'shareable';

    public function referenceType(): string
    {
        return match ($this) {
            self::Session => UriBuilder::ABSOLUTE_PATH,
            self::Shareable => UriBuilder::SHAREABLE_URL,
        };
    }
}
