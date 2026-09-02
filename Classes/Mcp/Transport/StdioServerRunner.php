<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Transport;

use Mcp\Server\InitializationOptions;
use Mcp\Server\Server;
use Mcp\Server\Transport\StdioServerTransport;
use Psr\Log\LoggerInterface;

class StdioServerRunner
{
    public function __construct(
        private readonly Server $server,
        private readonly InitializationOptions $initOptions,
        private readonly string $instructions,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(): void
    {
        $transport = null;
        $session = null;

        try {
            $transport = StdioServerTransport::create();
            $session = new InstructingServerSession($transport, $this->initOptions, $this->instructions, $this->logger);

            $this->server->setSession($session);
            $session->registerHandlers($this->server->getHandlers());
            $session->registerNotificationHandlers($this->server->getNotificationHandlers());
            $session->start();
        } finally {
            $session?->stop();
            $transport?->stop();
        }
    }
}
