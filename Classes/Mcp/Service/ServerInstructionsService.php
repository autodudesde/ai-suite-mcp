<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Service;

use AutoDudes\AiSuiteMcp\Mcp\Utility\OperatingGuidelines;

class ServerInstructionsService
{
    public function __construct(
        private readonly SessionOrientationService $sessionOrientation,
    ) {}

    public function build(): string
    {
        $instructions = 'You are connected to a TYPO3 CMS via AI Suite MCP.'
            ."\n\n"
            .OperatingGuidelines::getForInstructions();

        $orientation = $this->sessionOrientation->buildInstructionBlock();
        if ('' !== $orientation) {
            $instructions .= "\n\n".$orientation;
        }

        return $instructions;
    }
}
