<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

/**
 * Base exception for all MCP Server exceptions
 * 
 * This exception provides a consistent interface for error handling
 * across the MCP Server extension, including user-friendly messages
 * and context information for logging.
 */
class McpException extends \RuntimeException
{
    protected string $userMessage;
    protected array $context = [];
    
    /**
     * @param string $message Internal message for logging
     * @param string $userMessage User-friendly message for display
     * @param int $code Error code (HTTP status code conventions)
     * @param \Throwable|null $previous Previous exception for chaining
     * @param array $context Additional context for logging
     */
    public function __construct(
        string $message,
        string $userMessage = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->userMessage = $userMessage ?: $message;
        $this->context = $context;
    }
    
    /**
     * Get the user-friendly error message
     * 
     * @return string Message safe to display to end users
     */
    public function getUserMessage(): string
    {
        return $this->userMessage;
    }
    
    /**
     * Get additional context information
     * 
     * @return array Context data for logging
     */
    public function getContext(): array
    {
        return $this->context;
    }
}