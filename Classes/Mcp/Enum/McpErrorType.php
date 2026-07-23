<?php

declare(strict_types=1);

namespace AutoDudes\AiSuiteMcp\Mcp\Enum;

enum McpErrorType: string
{
    case InvalidParameter = 'invalid_parameter';
    case InsufficientScope = 'insufficient_scope';
    case InsufficientPermission = 'insufficient_permission';
    case NotFound = 'not_found';
    case ReadOnlyField = 'read_only_field';
    case UnsupportedHtml = 'unsupported_html';
    case DataHandlerError = 'datahandler_error';
    case InternalError = 'internal_error';
}
