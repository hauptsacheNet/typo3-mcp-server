<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\Exception\AccessDeniedException;
use Hn\McpServer\Exception\DatabaseException;
use Hn\McpServer\Exception\ValidationException;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test the new error handling implementation
 */
class ErrorHandlingTest extends AbstractFunctionalTest
{
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];
    
    /**
     * Test ValidationException handling in SearchTool
     */
    public function testSearchToolValidationException(): void
    {
        $tool = new SearchTool();
        
        // Test missing terms
        $result = $tool->execute([]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Parameter "terms" must be an array of strings', $result->content[0]->text);
        
        // Test empty terms array
        $result = $tool->execute(['terms' => []]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('At least one search term is required', $result->content[0]->text);
        
        // Test invalid term logic
        $result = $tool->execute([
            'terms' => ['test'],
            'termLogic' => 'INVALID'
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('termLogic must be either "AND" or "OR"', $result->content[0]->text);
        
        // Test terms with invalid types
        $result = $tool->execute([
            'terms' => ['valid', 123, 'another']
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('All terms must be strings', $result->content[0]->text);
        
        // Test terms that are too short
        $result = $tool->execute([
            'terms' => ['a']
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('at least 2 characters long', $result->content[0]->text);
        
        // Test unknown language code
        $result = $tool->execute([
            'terms' => ['test'],
            'language' => 'unknown_lang'
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Unknown language code', $result->content[0]->text);
    }
    
    /**
     * Test ValidationException handling in ReadTableTool
     */
    public function testReadTableToolValidationException(): void
    {
        $tool = new ReadTableTool();
        
        // Test missing table
        $result = $tool->execute([]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Table name is required', $result->content[0]->text);
        
        // Test invalid limit
        $result = $tool->execute([
            'table' => 'pages',
            'limit' => 1001
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Limit must be between 1 and 1000', $result->content[0]->text);
        
        // Test negative offset
        $result = $tool->execute([
            'table' => 'pages',
            'offset' => -1
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Offset must be non-negative', $result->content[0]->text);
        
        // Test unknown language code
        $result = $tool->execute([
            'table' => 'pages',
            'language' => 'xyz'
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Unknown language code', $result->content[0]->text);
    }
    
    /**
     * Test table access validation using validateTableAccessWithError
     */
    public function testTableAccessValidation(): void
    {
        $tool = new ReadTableTool();
        
        // Test non-existent table
        $result = $tool->execute([
            'table' => 'non_existent_table'
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Cannot access table', $result->content[0]->text);
        
        // Test restricted table (non-workspace capable)
        $result = $tool->execute([
            'table' => 'be_users'
        ]);
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Cannot access table', $result->content[0]->text);
    }
    
    /**
     * Test error logging functionality
     */
    public function testErrorLogging(): void
    {
        // This test would verify that errors are properly logged
        // For now, we'll just ensure the error handling doesn't break the execution
        
        $tool = new SearchTool();
        
        // Force an invalid table search to trigger error handling
        $result = $tool->execute([
            'terms' => ['test'],
            'table' => 'invalid_table_name'
        ]);
        
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        // The error should be handled gracefully
        $this->assertStringContainsString('Cannot search table', $result->content[0]->text);
    }
    
    /**
     * Test consistent error messages across tools
     */
    public function testConsistentErrorMessages(): void
    {
        $readTool = new ReadTableTool();
        $writeTool = new WriteTableTool();
        
        // Test the same error in different tools
        $readResult = $readTool->execute(['table' => 'non_existent']);
        $writeResult = $writeTool->execute([
            'action' => 'update',
            'table' => 'non_existent',
            'uid' => 1,
            'data' => ['title' => 'test']
        ]);
        
        $this->assertTrue($readResult->isError, json_encode($readResult->jsonSerialize()));
        $this->assertTrue($writeResult->isError, json_encode($writeResult->jsonSerialize()));
        
        // Both should have similar error messages
        $this->assertStringContainsString('Cannot access table', $readResult->content[0]->text);
        $this->assertStringContainsString('Cannot access table', $writeResult->content[0]->text);
    }
    
    /**
     * Test that database exceptions are properly caught and converted
     */
    public function testDatabaseExceptionHandling(): void
    {
        $tool = new ReadTableTool();
        
        // Create a condition that would cause a database error
        // Using invalid SQL syntax in where clause
        $result = $tool->execute([
            'table' => 'pages',
            'where' => 'invalid SQL syntax @@@ error'
        ]);
        
        // Should handle the database error gracefully
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        // The error message should be user-friendly, not exposing SQL details
        $this->assertStringNotContainsString('@@@ error', $result->content[0]->text);
    }
    
    /**
     * Test that unexpected errors are handled with generic messages
     */
    public function testUnexpectedErrorHandling(): void
    {
        // This is harder to test directly, but we can verify the error handling
        // doesn't expose internal details
        
        $tool = new SearchTool();
        
        // Search with very complex terms that might trigger edge cases
        $result = $tool->execute([
            'terms' => [str_repeat('complex', 10)],
            'termLogic' => 'AND',
            'limit' => 1
        ]);
        
        // Should either succeed or fail gracefully
        if ($result->isError) {
            // Error message should not contain stack traces or internal paths
            $errorText = $result->content[0]->text;
            $this->assertStringNotContainsString('Stack trace:', $errorText);
            $this->assertStringNotContainsString('/var/www/', $errorText);
            $this->assertStringNotContainsString('\\Hn\\McpServer\\', $errorText);
        }
    }
    
    /**
     * Test executeWithErrorHandling method functionality
     */
    public function testExecuteWithErrorHandlingMethod(): void
    {
        $tool = new SearchTool();
        
        // Test with valid search that should succeed
        $result = $tool->execute([
            'terms' => ['test'],
            'limit' => 10
        ]);
        
        // Even if no results, it shouldn't be an error
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Test with parameters that trigger validation error
        $result = $tool->execute([
            'terms' => ['x'] // Too short
        ]);
        
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('at least 2 characters', $result->content[0]->text);
    }
    
    /**
     * Test that workspace operations handle errors correctly
     */
    public function testWorkspaceErrorHandling(): void
    {
        $tool = new WriteTableTool();
        
        // Try to write to a non-workspace capable table
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'sys_log', // Not workspace capable
            'pid' => 0,
            'data' => ['details' => 'test']
        ]);
        
        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Cannot access table', $result->content[0]->text);
    }
}