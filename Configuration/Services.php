<?php

declare(strict_types=1);

use AutoDudes\AiSuiteMcp\Controller\McpController;
use AutoDudes\AiSuiteMcp\EventListener\McpButtonBarEventListener;
use AutoDudes\AiSuiteMcp\Mcp\Command\McpCleanupCommand;
use AutoDudes\AiSuiteMcp\Mcp\Command\McpCreateTokenCommand;
use AutoDudes\AiSuiteMcp\Mcp\Hooks\PasswordChangeHook;
use AutoDudes\AiSuiteMcp\Mcp\SystemClock;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder) {
    $services = $configurator->services();

    $services->defaults()
        ->private()
        ->autowire()
        ->autoconfigure()
    ;

    $services->load('AutoDudes\AiSuiteMcp\\', __DIR__.'/../Classes/')->exclude([
        __DIR__.'/../Classes/Domain/Model',
    ]);

    $services->set(McpController::class)
        ->public()
    ;

    $services->set(SystemClock::class);
    $services->alias(ClockInterface::class, SystemClock::class);

    $services->set(PasswordChangeHook::class)
        ->public()
    ;

    $services->set(McpCleanupCommand::class)
        ->tag('console.command', [
            'command' => 'ai-suite-mcp:cleanup',
            'description' => 'Clean up expired MCP OAuth tokens and sessions',
        ])
    ;

    $services->set(McpCreateTokenCommand::class)
        ->tag('console.command', [
            'command' => 'ai-suite-mcp:create-token',
            'description' => 'Create an MCP access token for testing (e.g. MCP Inspector)',
        ])
    ;

    $services->set(McpButtonBarEventListener::class)
        ->tag('event.listener', [
            'identifier' => 'ai-suite-mcp/button-bar',
        ])
    ;
};
