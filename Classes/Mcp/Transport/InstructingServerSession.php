<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Transport;

use Mcp\Server\InitializationOptions;
use Mcp\Server\ServerSession;
use Mcp\Server\Transport\Transport;
use Mcp\Types\InitializeResult;
use Mcp\Types\RequestWrapperInterface;
use Psr\Log\LoggerInterface;

class InstructingServerSession extends ServerSession
{
    public function __construct(
        Transport $transport,
        InitializationOptions $initOptions,
        private readonly string $instructions,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($transport, $initOptions, $logger);
    }

    protected function handleInitialize(RequestWrapperInterface $request, callable $respond): void
    {
        parent::handleInitialize($request, function (mixed $result) use ($respond): void {
            if ($result instanceof InitializeResult && '' !== $this->instructions) {
                $result->instructions = $this->instructions;
            }

            $respond($result);
        });
    }
}
