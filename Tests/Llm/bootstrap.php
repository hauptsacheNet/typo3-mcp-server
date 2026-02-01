<?php

declare(strict_types=1);

/**
 * Bootstrap file for LLM tests
 * Loads .env.local file and sets environment variables before running tests
 */

// First, include the TYPO3 testing framework bootstrap
require_once __DIR__ . '/../../vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php';

// Ensure our test classes are autoloaded
require_once __DIR__ . '/../../vendor/autoload.php';

// Load LLM test classes
require_once __DIR__ . '/Client/LlmClientInterface.php';
require_once __DIR__ . '/Client/LlmResponse.php';
require_once __DIR__ . '/Client/AnthropicClient.php';
require_once __DIR__ . '/Client/SymfonyAiClient.php';
require_once __DIR__ . '/LlmTestCase.php';

// Simple .env.local loader
(static function () {
    $envFile = __DIR__ . '/../../.env.local';
    
    if (!file_exists($envFile)) {
        return;
    }
    
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Skip comments
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        
        // Parse KEY=VALUE or KEY='VALUE' or KEY="VALUE" (allows lowercase for LLM_MODEL, LLM_PROVIDER)
        if (preg_match('/^([A-Za-z_]+)\s*=\s*(.*)$/', $line, $matches)) {
            $key = $matches[1];
            $value = $matches[2];
            
            // Remove quotes if present
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            
            // Set environment variable if not already set
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }
})();