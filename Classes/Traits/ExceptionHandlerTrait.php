<?php

declare(strict_types=1);

namespace Hn\McpServer\Traits;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Exception\McpException;
use Hn\McpServer\Exception\ValidationException;
use Mcp\Types\CallToolResult;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Trait for standardized exception handling in MCP tools
 * 
 * Provides consistent error handling, logging, and user-friendly
 * error message generation across all MCP tools.
 */
trait ExceptionHandlerTrait
{
    private ?LoggerInterface $logger = null;
    
    /**
     * Get logger instance
     * 
     * @return LoggerInterface
     */
    protected function getLogger(): LoggerInterface
    {
        if ($this->logger === null) {
            $this->logger = GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(static::class);
        }
        return $this->logger;
    }
    
    /**
     * Handle exception and return appropriate error result
     * 
     * @param \Throwable $e The exception to handle
     * @param string $operation The operation being performed when the exception occurred
     * @return CallToolResult Error result with user-friendly message
     */
    protected function handleException(\Throwable $e, string $operation = ''): CallToolResult
    {
        // Log the exception
        $this->logException($e, $operation);
        
        // Determine user-friendly message
        $userMessage = $this->getUserFriendlyMessage($e, $operation);
        
        return $this->createErrorResult($userMessage);
    }
    
    /**
     * Log exception with context
     * 
     * @param \Throwable $e The exception to log
     * @param string $operation The operation being performed
     */
    protected function logException(\Throwable $e, string $operation = ''): void
    {
        $context = [
            'exception' => $e,
            'operation' => $operation,
            'tool' => static::class,
            'trace' => $e->getTraceAsString()
        ];
        
        if ($e instanceof McpException) {
            $context = array_merge($context, $e->getContext());
        }
        
        if ($this->isExpectedException($e)) {
            $this->getLogger()->warning($e->getMessage(), $context);
        } else {
            $this->getLogger()->error($e->getMessage(), $context);
        }
    }
    
    /**
     * Get user-friendly error message
     * 
     * @param \Throwable $e The exception
     * @param string $operation The operation context
     * @return string User-friendly error message
     */
    protected function getUserFriendlyMessage(\Throwable $e, string $operation = ''): string
    {
        // MCP exceptions have user-friendly messages
        if ($e instanceof McpException) {
            return $e->getUserMessage();
        }
        
        // For expected exceptions with messages, use the original message
        if ($this->isExpectedException($e) && !empty($e->getMessage())) {
            return $e->getMessage();
        }
        
        // Map common exceptions to user-friendly messages only for unexpected errors
        return match (true) {
            $e instanceof \InvalidArgumentException => 'Invalid input provided' . ($operation ? ' for ' . $operation : ''),
            $e instanceof \RuntimeException => 'Operation failed' . ($operation ? ': ' . $operation : ''),
            $e instanceof \DomainException => 'Invalid operation requested',
            $e instanceof \Doctrine\DBAL\Exception => 'Database operation failed',
            default => 'An unexpected error occurred' . ($operation ? ' during ' . $operation : '')
        };
    }
    
    /**
     * Check if exception is expected (for logging level)
     * 
     * @param \Throwable $e The exception to check
     * @return bool True if this is an expected exception (client error)
     */
    protected function isExpectedException(\Throwable $e): bool
    {
        return $e instanceof ValidationException ||
               $e instanceof AccessDeniedException ||
               $e instanceof \InvalidArgumentException ||
               ($e instanceof McpException && $e->getCode() < 500);
    }
    
    /**
     * Abstract method that must be implemented by the class using this trait
     * 
     * @param string $message Error message
     * @return CallToolResult
     */
    abstract protected function createErrorResult(string $message): CallToolResult;
}