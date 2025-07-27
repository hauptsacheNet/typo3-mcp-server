<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

/**
 * Exception for validation errors
 * 
 * Thrown when input data fails validation rules.
 * Maps to HTTP 400 Bad Request status.
 */
class ValidationException extends McpException
{
    private array $errors;
    
    /**
     * @param array $errors Array of validation error messages
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(array $errors, ?\Throwable $previous = null)
    {
        $this->errors = $errors;
        $errorList = implode(', ', $errors);
        
        parent::__construct(
            "Validation failed: {$errorList}",
            "Invalid input: {$errorList}",
            400,
            $previous,
            ['errors' => $errors]
        );
    }
    
    /**
     * Get the validation errors
     * 
     * @return array Array of validation error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}