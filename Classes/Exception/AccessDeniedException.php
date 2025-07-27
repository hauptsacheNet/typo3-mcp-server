<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

/**
 * Exception for access-related errors
 * 
 * Thrown when a user attempts to access a resource without
 * proper permissions. Maps to HTTP 403 Forbidden status.
 */
class AccessDeniedException extends McpException
{
    /**
     * @param string $resource The resource being accessed (e.g., table name, record identifier)
     * @param string $operation The operation being attempted (e.g., read, write, delete)
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(string $resource, string $operation, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Access denied to {$resource} for operation {$operation}",
            "You don't have permission to {$operation} this resource",
            403,
            $previous,
            ['resource' => $resource, 'operation' => $operation]
        );
    }
}