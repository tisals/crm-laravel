<?php

namespace App\Empresas\Infrastructure\Mcp\Exceptions;

use RuntimeException;

/**
 * Thrown when the production `McpEmpresaClientHttp` adapter cannot
 * reach the configured FastMCP server (network error or 5xx).
 */
class McpServerUnavailable extends RuntimeException
{
}