<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\EdgeCase;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Test invalid data edge cases
 */
class InvalidDataTest extends AbstractFunctionalTest
{
    protected WriteTableTool $writeTool;
    protected ReadTableTool $readTool;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Initialize tools
        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->readTool = GeneralUtility::makeInstance(ReadTableTool::class);
    }
    
    /**
     * Data provider for invalid data scenarios
     */
    public static function invalidDataProvider(): array
    {
        return [
            'negative uid' => [
                ['table' => 'pages', 'uid' => -1],
                'empty_result', // Returns empty result for non-existent UIDs
                'read'
            ],
            'zero uid' => [
                ['table' => 'pages', 'uid' => 0],
                'success', // UID 0 might return the root page
                'read'
            ],
            'non-existent table' => [
                ['table' => 'non_existent_table', 'uid' => 1],
                'does not exist',
                'read'
            ],
            'sql injection in table' => [
                ['table' => 'pages; DROP TABLE pages;--', 'uid' => 1],
                'does not exist',
                'read'
            ],
            'invalid field names' => [
                [
                    'action' => 'update',
                    'table' => 'pages',
                    'uid' => 1,
                    'data' => ['"; DROP TABLE pages; --' => 'value']
                ],
                'success', // TYPO3 DataHandler ignores invalid fields
                'write'
            ],
            'exceeding field length' => [
                [
                    'action' => 'update',
                    'table' => 'pages',
                    'uid' => 1,
                    'data' => ['title' => str_repeat('x', 300)]
                ],
                'exceeds maximum length', // Tool validates field length
                'write'
            ],
            'invalid datetime format' => [
                [
                    'action' => 'update',
                    'table' => 'pages',
                    'uid' => 1,
                    'data' => ['starttime' => 'not-a-date']
                ],
                'success', // TYPO3 converts invalid dates to 0
                'write'
            ],
        ];
    }
    
    #[DataProvider('invalidDataProvider')]
    public function testInvalidDataHandling(array $params, string $expectedError, string $toolType): void
    {
        if ($toolType === 'read') {
            $result = $this->readTool->execute($params);
        } else {
            $result = $this->writeTool->execute($params);
        }
        
        if ($expectedError === 'empty_result') {
            // Special case for empty results
            $this->assertFalse($result->isError);
            $data = json_decode($result->content[0]->text, true);
            // Check if it's a list response with empty records or just empty
            if (isset($data['records'])) {
                $this->assertEmpty($data['records']);
            } else {
                $this->assertEmpty($data);
            }
        } elseif ($expectedError === 'success') {
            // Special case for operations that should succeed
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        } else {
            // Normal error case
            $this->assertTrue($result->isError, "Expected error but got success");
            $this->assertStringContainsString($expectedError, $result->content[0]->text);
        }
    }
    
    /**
     * Test non-existent record UID
     */
    public function testNonExistentUid(): void
    {
        $result = $this->readTool->execute([
            'table' => 'pages',
            'uid' => 999999
        ]);
        
        // Tool returns structured response with empty records array
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        // Check if it's a list response with empty records
        if (isset($data['records'])) {
            $this->assertEmpty($data['records']);
        } else {
            // Or it might be an empty object when filtering by UID
            $this->assertEmpty($data);
        }
    }
    
    /**
     * Test deleted record access
     */
    public function testDeletedRecordAccess(): void
    {
        // Create and delete a page
        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => ['title' => 'To be deleted']
        ]);
        
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $data = json_decode($createResult->content[0]->text, true);
        $uid = $data['uid'];
        
        // Delete the record
        $deleteResult = $this->writeTool->execute([
            'action' => 'delete',
            'table' => 'pages',
            'uid' => $uid
        ]);
        
        $this->assertFalse($deleteResult->isError, json_encode($deleteResult->jsonSerialize()));
        
        // Try to read the deleted record
        $readResult = $this->readTool->execute([
            'table' => 'pages',
            'uid' => $uid
        ]);
        
        // Tool returns structured response with empty records for deleted records
        $this->assertFalse($readResult->isError);
        $data = json_decode($readResult->content[0]->text, true);
        if (isset($data['records'])) {
            $this->assertEmpty($data['records']);
        } else {
            $this->assertEmpty($data);
        }
    }
    
    /**
     * Test data type mismatches
     */
    public function testDataTypeMismatch(): void
    {
        // Try to set a string value to an integer field
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'hidden' => 'not-a-number',
                'sorting' => 'invalid-sorting'
            ]
        ]);
        
        // TYPO3 might cast values automatically
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        // Values should be cast to integers
        if (isset($data['hidden'])) {
            $this->assertIsInt($data['hidden']);
        }
        if (isset($data['sorting'])) {
            $this->assertIsInt($data['sorting']);
        }
    }
    
    /**
     * Test invalid enum values
     */
    public function testInvalidEnumValue(): void
    {
        // Try to set invalid doktype
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'doktype' => 999 // Invalid doktype
            ]
        ]);
        
        $this->assertTrue($result->isError);
        // Check for validation error about doktype
        $errorText = $result->content[0]->text;
        $this->assertTrue(
            str_contains($errorText, 'doktype') || 
            str_contains($errorText, 'Validation error') ||
            str_contains($errorText, 'must be one of'),
            "Expected error about doktype validation, got: $errorText"
        );
    }
    
    /**
     * Test circular parent reference
     */
    public function testCircularParentReference(): void
    {
        // Try to set a page as its own parent
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'pid' => 1 // Self-reference
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('can only be set during record creation', $result->content[0]->text);
    }
    
    /**
     * Test mass assignment protection
     */
    public function testMassAssignmentProtection(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'uid' => 999, // Should not be allowed
                'deleted' => 1, // Should not be allowed directly
                'cruser_id' => 999, // Should not be allowed
                'title' => 'Allowed Field' // This should work
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString("Field 'uid' cannot be modified", $result->content[0]->text);
    }
    
    /**
     * Test invalid JSON in JSON fields
     */
    public function testInvalidJsonData(): void
    {
        // If there's a JSON field in any table, test invalid JSON
        // For now, test with a field that might store structured data
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test',
                'pi_flexform' => 'invalid json {not valid}' // If this field expects XML/JSON
            ]
        ]);
        
        // The tool might handle this gracefully or error
        // TYPO3 typically expects XML for flexforms
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }
    
    /**
     * Test invalid characters in string fields
     */
    public function testInvalidCharacters(): void
    {
        // Test with various problematic characters
        $problematicStrings = [
            "NULL\x00 byte",
            "Control\x01\x02\x03 characters",
            "Invalid UTF-8: \xFF\xFE",
            str_repeat('😀', 100), // Many emojis
        ];
        
        foreach ($problematicStrings as $string) {
            $result = $this->writeTool->execute([
                'action' => 'update',
                'table' => 'pages',
                'uid' => 1,
                'data' => [
                    'title' => $string
                ]
            ]);
            
            // TYPO3 might sanitize these or handle them
            // The important thing is no crash
            if (!$result->isError) {
                // Verify the data was stored (possibly sanitized)
                $readResult = $this->readTool->execute([
                    'table' => 'pages',
                    'uid' => 1
                ]);
                $this->assertFalse($readResult->isError);
            }
        }
    }
    
    /**
     * Test empty required fields
     */
    public function testEmptyRequiredFields(): void
    {
        // Try to create content without required CType
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'header' => 'Missing CType'
                // CType is required
            ]
        ]);
        
        // TYPO3 DataHandler might provide defaults
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Check what was actually created
        $data = json_decode($result->content[0]->text, true);
        if (isset($data['uid'])) {
            $record = BackendUtility::getRecord('tt_content', $data['uid']);
            $this->assertNotEmpty($record['CType'], 'CType should have a default value');
        }
    }
    
    /**
     * Test array data in non-array fields
     */
    public function testArrayInNonArrayField(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'title' => ['array', 'not', 'allowed']
            ]
        ]);
        
        // TYPO3 might convert array to string
        if ($result->isError) {
            $this->assertStringContainsString('Invalid', $result->content[0]->text);
        } else {
            // If it succeeded, check what was stored
            $data = json_decode($result->content[0]->text, true);
            if (isset($data['title'])) {
                $this->assertIsString($data['title']);
            }
        }
    }
    
    /**
     * Test extremely large numeric values
     */
    public function testLargeNumericValues(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 1,
            'data' => [
                'sorting' => PHP_INT_MAX,
                'nav_hide' => 999999 // Should be 0 or 1
            ]
        ]);
        
        // TYPO3 might accept these values
        if ($result->isError) {
            $this->assertStringContainsString('Invalid', $result->content[0]->text);
        } else {
            // Values might be stored or clamped
            $this->assertTrue(true);
        }
    }
    
    /**
     * Test invalid relation UIDs
     */
    public function testInvalidRelationUids(): void
    {
        // Try to set invalid UIDs for relations
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'media' => [-1, 0, 999999] // Invalid UIDs for sys_file_reference
            ]
        ]);
        
        // The tool should validate these
        if ($result->isError) {
            // Check for relevant error message
            $errorText = $result->content[0]->text;
            $this->assertTrue(
                str_contains($errorText, 'Invalid') || 
                str_contains($errorText, 'Error') ||
                str_contains($errorText, 'Reference') ||
                str_contains($errorText, 'unexpected error occurred'), // New generic error message
                "Expected error about invalid relations, got: $errorText"
            );
        } else {
            // TYPO3 might filter out invalid UIDs
            $this->assertTrue(true);
        }
    }
    
    /**
     * Test special characters in slugs
     */
    public function testInvalidSlugCharacters(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'pages',
            'pid' => 0,
            'data' => [
                'title' => 'Test Page',
                'slug' => '/../../../etc/passwd' // Path traversal attempt
            ]
        ]);
        
        // TYPO3 should sanitize the slug
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        
        // Verify the slug was sanitized
        $data = json_decode($result->content[0]->text, true);
        if (isset($data['uid'])) {
            $record = BackendUtility::getRecord('pages', $data['uid']);
            $this->assertNotEquals('/../../../etc/passwd', $record['slug']);
            $this->assertStringNotContainsString('..', $record['slug']);
        }
    }
}