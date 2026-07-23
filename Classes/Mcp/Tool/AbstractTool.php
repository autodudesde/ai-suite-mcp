<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Tool;

use AutoDudes\AiSuite\Service\BackendUserService;
use AutoDudes\AiSuite\Service\LocalizationService;
use AutoDudes\AiSuite\Service\TcaCompatibilityService;
use AutoDudes\AiSuiteMcp\Mcp\Enum\McpErrorType;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientPermissionException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InsufficientScopeException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\InvalidParameterException;
use AutoDudes\AiSuiteMcp\Mcp\Exception\McpException;
use AutoDudes\AiSuiteMcp\Mcp\McpUserContext;
use AutoDudes\AiSuiteMcp\Mcp\Service\DataHandlerErrorFormatter;
use AutoDudes\AiSuiteMcp\Mcp\Service\McpExcludedTablesService;
use AutoDudes\AiSuiteMcp\Mcp\Service\OutputFormatterService;
use AutoDudes\AiSuiteMcp\Mcp\Service\ParameterValidatorService;
use AutoDudes\AiSuiteMcp\Mcp\Service\PermissionService;
use AutoDudes\AiSuiteMcp\Mcp\Service\RecordAccessService;
use AutoDudes\AiSuiteMcp\Mcp\Service\SiteLanguageService;
use AutoDudes\AiSuiteMcp\Mcp\Service\TcaLabelService;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Abstract base class for all MCP tools.
 */
abstract class AbstractTool implements ToolInterface
{
    protected ?string $requiredScope = null;
    protected bool $readOnlyHint = false;
    protected bool $destructiveHint = false;
    protected bool $idempotentHint = false;
    protected bool $openWorldHint = false;

    protected readonly McpUserContext $userContext;
    protected readonly PermissionService $permissionService;
    protected readonly LoggerInterface $logger;
    protected readonly TcaCompatibilityService $tcaCompatibilityService;
    protected readonly SiteFinder $siteFinder;
    protected readonly LocalizationService $localizationService;
    protected readonly BackendUserService $backendUserService;
    protected readonly ResourceFactory $resourceFactory;
    protected readonly McpExcludedTablesService $excludedTablesService;
    protected readonly RecordAccessService $recordAccess;
    protected readonly TcaLabelService $tcaLabel;
    protected readonly OutputFormatterService $outputFormatter;
    protected readonly ParameterValidatorService $parameterValidator;
    protected readonly DataHandlerErrorFormatter $dataHandlerError;
    protected readonly SiteLanguageService $siteLanguages;

    public function __construct(
        protected readonly ToolContext $mcpToolContext,
    ) {
        $this->userContext = $mcpToolContext->userContext;
        $this->permissionService = $mcpToolContext->permissionService;
        $this->logger = $mcpToolContext->logger;
        $this->tcaCompatibilityService = $mcpToolContext->tcaCompatibilityService;
        $this->siteFinder = $mcpToolContext->siteFinder;
        $this->localizationService = $mcpToolContext->localizationService;
        $this->backendUserService = $mcpToolContext->backendUserService;
        $this->resourceFactory = $mcpToolContext->resourceFactory;
        $this->excludedTablesService = $mcpToolContext->excludedTablesService;
        $this->recordAccess = $mcpToolContext->recordAccess;
        $this->tcaLabel = $mcpToolContext->tcaLabel;
        $this->outputFormatter = $mcpToolContext->outputFormatter;
        $this->parameterValidator = $mcpToolContext->parameterValidator;
        $this->dataHandlerError = $mcpToolContext->dataHandlerError;
        $this->siteLanguages = $mcpToolContext->siteLanguages;
    }

    final public function execute(array $params): CallToolResult
    {
        try {
            $params = $this->parameterValidator->validate($params, $this->getSchema());
            $this->validatePermissions();
            $this->initialize();

            return $this->doExecute($params);
        } catch (InvalidParameterException $e) {
            $this->logger->warning('MCP tool received invalid input', [
                'tool' => $this->getName(),
                'beUserUid' => $this->getBackendUser()?->user['uid'] ?? null,
                'message' => $e->getMessage(),
                // Without this the log cannot tell two rejections of the same kind apart: every
                // `records` syntax error logged identical bytes, so the payload that caused it was
                // never recoverable after the fact.
                'context' => $e->getErrorContext(),
            ]);

            return $this->errorResult(
                $this->translate('hint.invalid_input', [$e->getMessage()])
                    ?? 'Please check the input: '.$e->getMessage(),
                $e->getErrorType(),
                $e->getErrorContext(),
            );
        } catch (InsufficientPermissionException|InsufficientScopeException $e) {
            $this->logger->info('MCP tool permission denied', [
                'tool' => $this->getName(),
                'beUserUid' => $this->getBackendUser()?->user['uid'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return $this->errorResult($e->getMessage(), $e->getErrorType(), $e->getErrorContext());
        } catch (\RuntimeException $e) {
            $this->logger->error('MCP tool execution failed', [
                'tool' => $this->getName(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $errorType = $e instanceof McpException ? $e->getErrorType() : McpErrorType::InternalError;
            $context = $e instanceof McpException ? $e->getErrorContext() : [];

            return $this->errorResult($e->getMessage(), $errorType, $context);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool execution failed', [
                'tool' => $this->getName(),
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // The reader here is a model, not a person. "Please try again" was taken literally:
            // it re-sent an identical, still-broken payload instead of correcting it.
            return $this->errorResult(
                $this->translate('hint.internal_issue', [$this->getName()])
                    ?? sprintf(
                        'Running "%s" failed unexpectedly. Repeating the identical call will fail again. Re-check the arguments against the tool schema; if they are correct, this needs an administrator.',
                        $this->getName(),
                    ),
                McpErrorType::InternalError,
            );
        }
    }

    public function getRequiredScope(): ?string
    {
        return $this->requiredScope;
    }

    /**
     * @return array<string, bool>
     */
    public function getAnnotations(): array
    {
        return [
            'readOnlyHint' => $this->readOnlyHint,
            'destructiveHint' => $this->destructiveHint,
            'idempotentHint' => $this->idempotentHint,
            'openWorldHint' => $this->openWorldHint,
        ];
    }

    /**
     * @param array<string, mixed> $params Validated and sanitized parameters
     */
    abstract protected function doExecute(array $params): CallToolResult;

    protected function initialize(): void {}

    protected function validatePermissions(): void
    {
        $this->permissionService->validateToolAccess(
            $this->getName(),
            $this->userContext->getScopes(),
        );
    }

    /**
     * @param list<int|string> $arguments
     */
    protected function translate(string $key, array $arguments = []): string
    {
        return $this->localizationService->translate('mcp:'.$key, $arguments);
    }

    /**
     * @param list<int|string> $arguments
     */
    protected function translateOrFallback(string $key, array $arguments, string $fallback): string
    {
        $translated = $this->translate($key, $arguments);

        return '' !== $translated ? $translated : $fallback;
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $this->backendUserService->getBackendUser();
    }

    protected function textResult(string $text): CallToolResult
    {
        return new CallToolResult([new TextContent($text)]);
    }

    /**
     * @param array<string, mixed> $structured
     */
    protected function structuredResult(string $text, array $structured): CallToolResult
    {
        return new CallToolResult([new TextContent($text)], structuredContent: $structured);
    }

    protected function textError(string $text, McpErrorType $errorType = McpErrorType::InvalidParameter): CallToolResult
    {
        return $this->errorResult($text, $errorType);
    }

    /**
     * @param array<string, mixed> $context    extra fields merged into the error envelope (e.g. table, field, uid)
     * @param array<string, mixed> $structured extra top-level payload kept alongside the error envelope, for
     *                                         results that still carry something worth rendering (e.g. a preview)
     */
    protected function errorResult(string $text, McpErrorType $errorType, array $context = [], array $structured = []): CallToolResult
    {
        return new CallToolResult(
            [new TextContent($text)],
            isError: true,
            structuredContent: ['error' => ['type' => $errorType->value] + $context] + $structured,
        );
    }
}
