<?php

namespace App\Empresas\Infrastructure\Mcp\Exceptions;

use RuntimeException;

/**
 * Thrown when the production `McpEmpresaClientHttp` adapter is
 * invoked from a context where the real HTTP transport is not
 * yet wired (i.e. PR3 scaffold).
 */
class NotImplementedInScaffoldException extends RuntimeException
{
}