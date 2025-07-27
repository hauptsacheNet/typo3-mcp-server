<?php

declare(strict_types=1);

namespace Hn\McpServer\Exception;

/**
 * Exception for configuration errors
 * 
 * Thrown when system configuration is missing, invalid,
 * or incompatible. Maps to HTTP 500 Internal Server Error
 * status as these are system-level issues.
 */
class ConfigurationException extends McpException
{
    /**
     * @param string $config The configuration element that caused the error (e.g., "TCA", "site configuration")
     * @param string $reason Detailed reason for the configuration error
     * @param \Throwable|null $previous Previous exception for chaining
     */
    public function __construct(string $config, string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(
            "Configuration error for {$config}: {$reason}",
            "System configuration error",
            500,
            $previous,
            ['config' => $config, 'reason' => $reason]
        );
    }
}