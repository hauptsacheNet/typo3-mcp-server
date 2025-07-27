<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

/**
 * Exception for database-related errors
 * 
 * Thrown when database operations fail. Maps to HTTP 500
 * Internal Server Error status as these are typically
 * system-level issues.
 */
class DatabaseException extends McpException
{
    /**
     * @param string $operation The database operation that failed (e.g., select, insert, update, delete)
     * @param string $table The table name involved in the operation
     * @param \Throwable|null $previous Previous exception for chaining (often a Doctrine DBAL exception)
     */
    public function __construct(string $operation, string $table, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Database error during {$operation} on table {$table}: " . ($previous ? $previous->getMessage() : 'Unknown error'),
            "Failed to {$operation} record",
            500,
            $previous,
            ['operation' => $operation, 'table' => $table]
        );
    }
}