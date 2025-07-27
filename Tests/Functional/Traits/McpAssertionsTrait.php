<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Traits;

use Mcp\Types\CallToolResult;

/**
 * Trait providing common MCP-specific assertions
 * 
 * Reduces duplication of assertion code across test classes
 */
trait McpAssertionsTrait
{
    /**
     * Assert that a tool result is successful (not an error)
     * 
     * @param CallToolResult $result The result to check
     * @param string $message Optional message for failure
     */
    protected function assertSuccessfulToolResult($result, string $message = ''): void
    {
        $this->assertInstanceOf(CallToolResult::class, $result, $message);
        $this->assertFalse(
            $result->isError, 
            ($message ?: 'Tool returned error') . ': ' . json_encode($result->jsonSerialize())
        );
        
        if (property_exists($result, 'result')) {
            $this->assertIsArray($result->result, $message);
        }
    }
    
    /**
     * Assert that a tool result is an error
     * 
     * @param CallToolResult $result The result to check
     * @param string|null $expectedMessage Optional expected error message
     */
    protected function assertToolError($result, ?string $expectedMessage = null): void
    {
        $this->assertInstanceOf(CallToolResult::class, $result);
        $this->assertTrue($result->isError, 'Expected error but tool succeeded');
        
        if ($expectedMessage !== null) {
            $errorMessage = '';
            
            // Extract error message from content
            if (!empty($result->content) && isset($result->content[0])) {
                $errorMessage = $result->content[0]->text;
            }
            
            $this->assertStringContainsString(
                $expectedMessage, 
                $errorMessage,
                'Error message does not contain expected text'
            );
        }
    }
    
    /**
     * Assert that a result contains a workspace ID
     * 
     * @param CallToolResult $result
     */
    protected function assertHasWorkspace($result): void
    {
        $this->assertSuccessfulToolResult($result);
        $this->assertArrayHasKey('workspace_id', $result->result);
        $this->assertGreaterThan(0, $result->result['workspace_id'], 'Workspace ID should be greater than 0');
    }
    
    /**
     * Assert that a record contains expected field values
     * 
     * @param array $expected Expected field values
     * @param array $actual Actual record data
     * @param array|null $fields Fields to check (null = all fields in expected)
     */
    protected function assertRecordEquals(array $expected, array $actual, ?array $fields = null): void
    {
        $fields = $fields ?? array_keys($expected);
        
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $actual, "Field '$field' missing in actual record");
            $this->assertEquals(
                $expected[$field], 
                $actual[$field],
                "Field '$field' does not match expected value"
            );
        }
    }
    
    /**
     * Assert that a result contains valid record data
     * 
     * @param CallToolResult $result
     * @param string $key The key containing the record (default: 'record')
     */
    protected function assertHasValidRecord($result, string $key = 'record'): void
    {
        $this->assertSuccessfulToolResult($result);
        $this->assertArrayHasKey($key, $result->result);
        $this->assertIsArray($result->result[$key]);
        $this->assertArrayHasKey('uid', $result->result[$key]);
        $this->assertGreaterThan(0, $result->result[$key]['uid']);
    }
    
    /**
     * Assert that a result contains a list of records
     * 
     * @param CallToolResult $result
     * @param string $key The key containing records (default: 'records')
     * @param int|null $expectedCount Expected number of records (null = any)
     */
    protected function assertHasRecordList($result, string $key = 'records', ?int $expectedCount = null): void
    {
        $this->assertSuccessfulToolResult($result);
        $this->assertArrayHasKey($key, $result->result);
        $this->assertIsArray($result->result[$key]);
        
        if ($expectedCount !== null) {
            $this->assertCount($expectedCount, $result->result[$key]);
        }
    }
    
    /**
     * Assert pagination data in result
     * 
     * @param CallToolResult $result
     * @param int $expectedLimit
     * @param int $expectedOffset
     */
    protected function assertHasPagination($result, int $expectedLimit, int $expectedOffset): void
    {
        $this->assertSuccessfulToolResult($result);
        
        // Extract JSON data if result has content
        if (property_exists($result, 'content') && count($result->content) > 0) {
            $data = json_decode($result->content[0]->text, true);
            
            $this->assertArrayHasKey('limit', $data);
            $this->assertArrayHasKey('offset', $data);
            $this->assertArrayHasKey('total', $data);
            $this->assertArrayHasKey('hasMore', $data);
            
            $this->assertEquals($expectedLimit, $data['limit']);
            $this->assertEquals($expectedOffset, $data['offset']);
            $this->assertIsBool($data['hasMore']);
            $this->assertIsInt($data['total']);
        }
    }
    
    /**
     * Assert that essential fields are present in a record
     * 
     * @param array $record
     * @param array $additionalFields Additional fields to check beyond essentials
     */
    protected function assertHasEssentialFields(array $record, array $additionalFields = []): void
    {
        $essentialFields = ['uid', 'pid', 'tstamp', 'crdate'];
        $allFields = array_merge($essentialFields, $additionalFields);
        
        foreach ($allFields as $field) {
            $this->assertArrayHasKey($field, $record, "Essential field '$field' missing");
        }
    }
    
    /**
     * Assert date field format (ISO 8601)
     * 
     * @param string|null $dateValue
     * @param string $fieldName
     */
    protected function assertDateFormat($dateValue, string $fieldName): void
    {
        if ($dateValue === null || $dateValue === '') {
            return;
        }
        
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            (string)$dateValue,
            "Field '$fieldName' is not in ISO 8601 format"
        );
    }
    
    /**
     * Extract JSON data from MCP result
     * 
     * @param CallToolResult $result
     * @return array
     */
    protected function extractJsonFromResult(CallToolResult $result): array
    {
        $this->assertFalse($result->isError);
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(\Mcp\Types\TextContent::class, $result->content[0]);
        
        $data = json_decode($result->content[0]->text, true);
        $this->assertIsArray($data);
        
        return $data;
    }
    
    /**
     * Assert that a record exists in workspace but not in live
     * 
     * @param string $table
     * @param int $uid
     */
    protected function assertRecordInWorkspace(string $table, int $uid): void
    {
        $connection = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Database\ConnectionPool::class
        )->getConnectionForTable($table);
        
        // Check record doesn't exist in live (workspace 0)
        $liveCount = $connection->count('uid', $table, [
            'uid' => $uid,
            't3ver_wsid' => 0
        ]);
        
        $this->assertEquals(0, $liveCount, "Record $table:$uid should not exist in live workspace");
        
        // Check record exists in current workspace
        $workspaceId = $GLOBALS['BE_USER']->workspace ?? 0;
        if ($workspaceId > 0) {
            $workspaceCount = $connection->count('uid', $table, [
                't3ver_oid' => $uid,
                't3ver_wsid' => $workspaceId
            ]);
            
            $this->assertGreaterThan(0, $workspaceCount, "Record $table:$uid should exist in workspace $workspaceId");
        }
    }
}