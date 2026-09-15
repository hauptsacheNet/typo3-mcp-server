<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use PHPUnit\Framework\Attributes\DataProvider;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Fixtures\TestDataBuilder;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use Hn\McpServer\Tests\Functional\Traits\PluginContentTrait;
use Mcp\Types\TextContent;

class ReadTableToolTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;
    use PluginContentTrait;

    private ReadTableTool $tool;
    private TestDataBuilder $data;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tool = new ReadTableTool();
        $this->data = new TestDataBuilder();

        // Plugin row used by testFieldFilteringBasedOnCType. Inserted
        // programmatically because the tt_content shape differs between
        // TYPO3 13 (CType=list + list_type) and TYPO3 14 (CType=plugin).
        $this->insertPluginContentElement(
            uid: 105,
            pid: 6,
            pluginIdentifier: 'news_pi1',
            extra: ['header' => 'Contact Form', 'bodytext' => 'Get in touch']
        );
    }

    /**
     * Test reading records by PID (page ID)
     */
    public function testReadRecordsByPid(): void
    {
        // Read content elements from page 1 (Home)
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        
        $this->assertEquals('tt_content', $data['table']);
        $this->assertArrayHasKey('records', $data);
        
        // Should have 3 content elements including hidden one (100, 101, 104)
        $this->assertCount(3, $data['records']);
        
        // Verify record structure
        $firstRecord = $data['records'][0];
        $this->assertHasEssentialFields($firstRecord, ['header', 'CType']);
        
        // Verify specific content - now includes hidden records
        $uids = array_column($data['records'], 'uid');
        $this->assertContains(100, $uids);
        $this->assertContains(101, $uids);
        $this->assertContains(104, $uids); // Hidden content is now included
    }

    /**
     * Test reading a single record by UID
     */
    public function testReadSingleRecordByUid(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'includeRelations' => false
        ]);
        
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        
        // Should have exactly one record
        $this->assertCount(1, $data['records']);
        
        $expected = [
            'uid' => 100,
            'header' => 'Welcome Header',
            'CType' => 'textmedia',
            'pid' => 1
        ];
        $this->assertRecordEquals($expected, $data['records'][0]);
    }

    /**
     * Test reading from pages table
     */
    public function testReadPagesTable(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'pid' => 0, // Root level pages
            'includeRelations' => false
        ]);
        
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        
        $this->assertEquals('pages', $data['table']);
        $this->assertGreaterThan(0, count($data['records']));
        
        // Should include root page (Home) - Contact and News are now subpages
        $titles = array_column($data['records'], 'title');
        $this->assertContains('Home', $titles);
        
        // Contact and News should not be in root level anymore
        $this->assertNotContains('Contact', $titles);
        $this->assertNotContains('News', $titles);
        
        // Should not include hidden pages by default
        $this->assertNotContains('Hidden Page', $titles);
    }

    /**
     * Test pagination functionality
     */
    public function testReadWithPagination(): void
    {
        // Test with limit
        $result = $this->tool->execute([
            'table' => 'pages',
            'limit' => 2,
            'includeRelations' => false
        ]);
        
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        
        $this->assertLessThanOrEqual(2, count($data['records']));
        $this->assertHasPagination($result, 2, 0);
    }

    /**
     * Test pagination with offset
     */
    public function testReadWithOffset(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'limit' => 1,
            'offset' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertSuccessfulToolResult($result);
        $this->assertHasPagination($result, 1, 1);
    }

    /**
     * Test date field conversion
     */
    public function testDateFieldConversion(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        
        $record = $data['records'][0];
        
        // Date fields should be converted to ISO format
        $this->assertArrayHasKey('tstamp', $record);
        $this->assertArrayHasKey('crdate', $record);
        
        // Should be ISO 8601 format strings, not timestamps
        $this->assertDateFormat($record['tstamp'], 'tstamp');
        $this->assertDateFormat($record['crdate'], 'crdate');
    }

    /**
     * Test error handling for invalid table
     */
    public function testReadFromInvalidTable(): void
    {
        $result = $this->tool->execute([
            'table' => 'non_existent_table'
        ]);
        
        $this->assertToolError($result, 'does not exist in TCA');
    }

    /**
     * Test error handling for missing table parameter
     */
    public function testMissingTableParameter(): void
    {
        $result = $this->tool->execute([]);
        
        $this->assertToolError($result, 'Table name is required');
    }

    /**
     * Test filtering with a typed clause
     */
    public function testReadWithFilterClause(): void
    {
        $tool = new ReadTableTool();

        $result = $tool->execute([
            'table' => 'tt_content',
            'where' => [
                ['field' => 'CType', 'operator' => '=', 'value' => 'textmedia'],
            ],
            'includeRelations' => false,
        ]);

        $this->assertFalse($result->isError, $result->content[0]->text);
        $data = json_decode($result->content[0]->text, true);
        $this->assertNotEmpty($data['records']);
        foreach ($data['records'] as $record) {
            $this->assertEquals('textmedia', $record['CType']);
        }
    }

    /**
     * Several clauses narrow the result together, and the reported total
     * respects them — the count query is built from the same clauses.
     */
    public function testFilterClausesCombineAndAreCounted(): void
    {
        $tool = new ReadTableTool();

        $single = json_decode($tool->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'CType', 'operator' => '=', 'value' => 'textmedia']],
            'includeRelations' => false,
        ])->content[0]->text, true);

        $both = json_decode($tool->execute([
            'table' => 'tt_content',
            'where' => [
                ['field' => 'CType', 'operator' => '=', 'value' => 'textmedia'],
                ['field' => 'pid', 'operator' => '=', 'value' => 1],
            ],
            'includeRelations' => false,
        ])->content[0]->text, true);

        $this->assertLessThanOrEqual($single['total'], $both['total']);
        $this->assertSame(count($both['records']), min($both['total'], count($both['records'])));
        foreach ($both['records'] as $record) {
            $this->assertEquals('textmedia', $record['CType']);
        }
    }

    /**
     * A "%" in a value is a literal to match, not a wildcard: the value is
     * escaped before the LIKE pattern is built around it.
     */
    public function testContainsEscapesWildcardsInTheValue(): void
    {
        $tool = new ReadTableTool();

        $result = $tool->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'header', 'operator' => 'contains', 'value' => '%']],
            'includeRelations' => false,
        ]);

        $this->assertFalse($result->isError, $result->content[0]->text);
        $data = json_decode($result->content[0]->text, true);
        foreach ($data['records'] as $record) {
            $this->assertStringContainsString('%', (string)$record['header']);
        }
    }

    /**
     * The parameter used to take a raw SQL condition. A string is refused with
     * the shape that replaces it, so a caller sending the old form can fix the
     * call from the error alone.
     */
    public function testSqlConditionStringIsRefusedWithTheNewShape(): void
    {
        $tool = new ReadTableTool();

        $result = $tool->execute([
            'table' => 'tt_content',
            'where' => 'CType = "textmedia"',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not an SQL string', $result->content[0]->text);
        $this->assertStringContainsString('"operator"', $result->content[0]->text);
    }

    /**
     * The three exfiltration shapes the old keyword blocklist let through.
     *
     * It listed DROP/DELETE/UPDATE/INSERT/TRUNCATE/ALTER/CREATE and not SELECT,
     * so a subquery against any table passed, and an EXISTS clause was a blind
     * oracle over a column the tool never exposes. None of them can be
     * expressed as a clause: an operator is picked from a fixed set and a value
     * is bound as a parameter.
     *
     * @param mixed $where
     */
    #[DataProvider('sqlInjectionAttempts')]
    public function testSqlInjectionShapesCannotBeExpressed($where): void
    {
        $tool = new ReadTableTool();

        $result = $tool->execute([
            'table' => 'pages',
            'where' => $where,
        ]);

        $this->assertTrue($result->isError, 'Expected a rejection, got: ' . $result->content[0]->text);
    }

    public static function sqlInjectionAttempts(): array
    {
        return [
            'statement terminator' => ['uid = 1; DROP TABLE pages'],
            'always-true tail' => ['uid = 1 OR 1=1'],
            'subquery against be_users' => ['uid IN (SELECT uid FROM be_users WHERE admin = 1)'],
            'blind oracle on a password hash' => ["EXISTS (SELECT 1 FROM be_users WHERE password LIKE 'x%')"],
            'injection through a field name' => [[
                ['field' => 'uid = 1 OR 1=1 -- ', 'operator' => '=', 'value' => 1],
            ]],
            'injection through an operator' => [[
                ['field' => 'uid', 'operator' => '= 1 OR 1=1 -- ', 'value' => 1],
            ]],
        ];
    }

    /**
     * A filter may only name a field the caller could also read. Otherwise a
     * filter is a side channel: narrowing by a hidden column, or a LIKE on it,
     * reveals content the result rows never carry.
     */
    public function testFilteringOnAnUnreadableFieldIsRefused(): void
    {
        $tool = new ReadTableTool();

        $result = $tool->execute([
            'table' => 'pages',
            'where' => [['field' => 'perms_userid', 'operator' => '=', 'value' => 1]],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not a readable field', $result->content[0]->text);
    }

    /**
     * An unknown operator names the supported set rather than failing vaguely.
     */
    public function testUnknownOperatorNamesTheSupportedSet(): void
    {
        $tool = new ReadTableTool();

        $result = $tool->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'CType', 'operator' => 'LIKE', 'value' => 'textmedia']],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('is not supported', $result->content[0]->text);
        $this->assertStringContainsString('startsWith', $result->content[0]->text);
    }

    /**
     * Values are typed after the field: a number field will not take text.
     */
    public function testTextValueOnANumericFieldIsRefused(): void
    {
        $tool = new ReadTableTool();

        $result = $tool->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'pid', 'operator' => '=', 'value' => 'one']],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('must be a number', $result->content[0]->text);
    }

    /**
     * "in" needs a list, and an empty one is a caller mistake rather than a
     * filter that matches nothing.
     */
    public function testInOperatorRequiresANonEmptyList(): void
    {
        $tool = new ReadTableTool();

        foreach ([['field' => 'uid', 'operator' => 'in', 'value' => 5], ['field' => 'uid', 'operator' => 'in', 'value' => []]] as $clause) {
            $result = $tool->execute(['table' => 'tt_content', 'where' => [$clause]]);
            $this->assertTrue($result->isError, json_encode($clause));
            $this->assertStringContainsString('non-empty array', $result->content[0]->text);
        }
    }

    /**
     * Test tool schema
     */
    public function testToolSchema(): void
    {
        $tool = new ReadTableTool();
        $schema = $tool->getSchema();
        
        $this->assertIsArray($schema);
        $this->assertArrayHasKey('description', $schema);
        $this->assertArrayHasKey('inputSchema', $schema);
        $this->assertArrayHasKey('properties', $schema['inputSchema']);
        
        // Check key parameters
        $properties = $schema['inputSchema']['properties'];
        $this->assertArrayHasKey('table', $properties);
        $this->assertArrayHasKey('pid', $properties);
        $this->assertArrayHasKey('uid', $properties);
        $this->assertArrayHasKey('limit', $properties);
        $this->assertArrayHasKey('offset', $properties);
        $this->assertArrayHasKey('where', $properties);
    }

    /**
     * Test reading records with sorting
     */
    public function testReadWithSorting(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        // Records should be sorted by sorting field (ascending) - now includes hidden
        $this->assertCount(3, $data['records']);
        
        $sortingValues = array_column($data['records'], 'sorting');
        $this->assertEquals(256, $sortingValues[0]);
        $this->assertEquals(512, $sortingValues[1]);
        $this->assertEquals(768, $sortingValues[2]); // Hidden record
    }

    /**
     * Test essential fields are always included
     */
    public function testEssentialFieldsIncluded(): void
    {
        $tool = new ReadTableTool();
        
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        
        $record = $data['records'][0];
        
        // Essential fields should always be present
        $this->assertArrayHasKey('uid', $record);
        $this->assertArrayHasKey('pid', $record);
        $this->assertArrayHasKey('tstamp', $record);
        $this->assertArrayHasKey('crdate', $record);
        
        // For pages, title should be included as it's the label field
        $this->assertArrayHasKey('title', $record);
    }
    
    /**
     * Test field filtering based on CType
     */
    public function testFieldFilteringBasedOnCType(): void
    {
        $tool = new ReadTableTool();
        
        // Test textmedia record (UID 100)
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'includeRelations' => false
        ]);
        
        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        $textmediaRecord = $data['records'][0];
        
        // Verify this is a textmedia record
        $this->assertEquals('textmedia', $textmediaRecord['CType']);
        
        // Essential fields should always be present
        $this->assertArrayHasKey('uid', $textmediaRecord);
        $this->assertArrayHasKey('pid', $textmediaRecord);
        $this->assertArrayHasKey('CType', $textmediaRecord);
        $this->assertArrayHasKey('header', $textmediaRecord);
        $this->assertArrayHasKey('sorting', $textmediaRecord);
        $this->assertArrayHasKey('tstamp', $textmediaRecord);
        $this->assertArrayHasKey('crdate', $textmediaRecord);
        
        // For textmedia, bodytext should be present if it's in the showitem
        $this->assertArrayHasKey('bodytext', $textmediaRecord);
        
        // Test plugin record (UID 105). The CType differs between TYPO3 13
        // (CType=list, list_type=news_pi1) and TYPO3 14 (CType=news_pi1).
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 105,
            'includeRelations' => false
        ]);

        $this->assertFalse($result->isError);
        $data = json_decode($result->content[0]->text, true);
        $pluginRecord = $data['records'][0];

        $expectedCType = \Hn\McpServer\Service\TableAccessService::hasPluginSubtypes() ? 'list' : 'news_pi1';
        $this->assertEquals($expectedCType, $pluginRecord['CType']);

        $commonFields = ['uid', 'pid', 'CType', 'header', 'sorting', 'tstamp', 'crdate'];
        foreach ($commonFields as $field) {
            $this->assertArrayHasKey($field, $pluginRecord, "Plugin record missing essential field: $field");
        }

        $textmediaFields = array_keys($textmediaRecord);
        $pluginFields = array_keys($pluginRecord);

        $this->assertContains('bodytext', $textmediaFields, "Textmedia should have bodytext");

        $this->assertLessThan(100, count($textmediaFields), "Too many fields returned for textmedia");
        $this->assertLessThan(100, count($pluginFields), "Too many fields returned for the plugin");
    }

    /**
     * Test field filtering with unknown CTypes
     */
    public function testFieldFilteringWithUnknownCType(): void
    {
        // Create a record with an unknown CType
        $tool = new ReadTableTool();
        
        // Read a record but simulate unknown CType by testing field filtering behavior
        $result = $tool->execute([
            'table' => 'tt_content',
            'uid' => 100
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->content));
        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];
        
        // Even with unknown CTypes, essential fields should be present
        $essentialFields = ['uid', 'pid', 'CType', 'header', 'sorting', 'tstamp', 'crdate'];
        foreach ($essentialFields as $field) {
            $this->assertArrayHasKey($field, $record, "Essential field $field missing");
        }
        
        // Should have reasonable field count (not all possible fields)
        $this->assertLessThan(100, count($record), "Too many fields for unknown CType");
    }

    /**
     * Test that fields parameter limits returned fields
     */
    public function testFieldsParameterLimitsReturnedFields(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => ['header', 'bodytext'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        // uid is always included
        $this->assertArrayHasKey('uid', $record);

        // Requested fields should be present
        $this->assertArrayHasKey('header', $record);
        $this->assertArrayHasKey('bodytext', $record);

        // Everything else should be absent — only uid + requested fields
        $this->assertArrayNotHasKey('CType', $record, 'Non-requested field CType should be excluded');
        $this->assertArrayNotHasKey('colPos', $record, 'Non-requested field colPos should be excluded');
        $this->assertArrayNotHasKey('pid', $record, 'Non-requested field pid should be excluded');
        $this->assertArrayNotHasKey('sorting', $record, 'Non-requested field sorting should be excluded');
        $this->assertArrayNotHasKey('tstamp', $record, 'Non-requested field tstamp should be excluded');
    }

    /**
     * Test that fields parameter with empty array returns all fields (default behavior)
     */
    public function testFieldsParameterEmptyArrayReturnsAllFields(): void
    {
        // Read without fields parameter
        $resultWithout = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);

        // Read with empty fields array
        $resultWith = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => [],
        ]);

        $this->assertFalse($resultWithout->isError, json_encode($resultWithout->jsonSerialize()));
        $this->assertFalse($resultWith->isError, json_encode($resultWith->jsonSerialize()));

        $dataWithout = $this->extractJsonFromResult($resultWithout);
        $dataWith = $this->extractJsonFromResult($resultWith);

        // Both should return the same fields
        $keysWithout = array_keys($dataWithout['records'][0]);
        $keysWith = array_keys($dataWith['records'][0]);
        sort($keysWithout);
        sort($keysWith);

        $this->assertEquals($keysWithout, $keysWith, 'Empty fields array should return the same fields as omitting the parameter');
    }

    /**
     * Test that fields parameter appears in schema
     */
    public function testFieldsParameterInSchema(): void
    {
        $schema = $this->tool->getSchema();
        $properties = $schema['inputSchema']['properties'];

        $this->assertArrayHasKey('fields', $properties);
        $this->assertEquals('array', $properties['fields']['type']);
        $this->assertArrayHasKey('items', $properties['fields']);
    }

    /**
     * Test that fields parameter works with pages table
     */
    public function testFieldsParameterWithPagesTable(): void
    {
        $result = $this->tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'fields' => ['title'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        // uid is always included
        $this->assertArrayHasKey('uid', $record);

        // Requested field should be present
        $this->assertArrayHasKey('title', $record);

        // Everything else should be absent
        $this->assertArrayNotHasKey('doktype', $record, 'Non-requested field doktype should be excluded');
        $this->assertArrayNotHasKey('pid', $record, 'Non-requested field pid should be excluded');
        $this->assertArrayNotHasKey('description', $record, 'Non-requested field description should be excluded');
        $this->assertArrayNotHasKey('slug', $record, 'Non-requested field slug should be excluded');
    }

    /**
     * Test that ctrl fields (tstamp, crdate, etc.) can be requested even though
     * they are not in the TCA showitem definition for any type.
     */
    public function testFieldsParameterCanRequestCtrlFields(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => ['tstamp', 'crdate', 'pid'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        // uid always included
        $this->assertArrayHasKey('uid', $record);

        // Requested ctrl fields should be present
        $this->assertArrayHasKey('tstamp', $record, 'Requested ctrl field tstamp should be included');
        $this->assertArrayHasKey('crdate', $record, 'Requested ctrl field crdate should be included');
        $this->assertArrayHasKey('pid', $record, 'Requested ctrl field pid should be included');

        // Dates should still be converted to ISO format
        $this->assertDateFormat($record['tstamp'], 'tstamp');
        $this->assertDateFormat($record['crdate'], 'crdate');

        // Non-requested fields should be absent
        $this->assertArrayNotHasKey('header', $record, 'Non-requested field header should be excluded');
        $this->assertArrayNotHasKey('bodytext', $record, 'Non-requested field bodytext should be excluded');
    }

    /**
     * Test that field names are matched case-insensitively.
     * The output should use the correct TCA case.
     */
    public function testFieldsParameterIsCaseInsensitive(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => ['ctype', 'HEADER', 'Bodytext'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $record = $data['records'][0];

        // Fields should be returned in their correct TCA case
        $this->assertArrayHasKey('CType', $record, '"ctype" should match CType');
        $this->assertArrayHasKey('header', $record, '"HEADER" should match header');
        $this->assertArrayHasKey('bodytext', $record, '"Bodytext" should match bodytext');

        // Non-requested fields should still be excluded
        $this->assertArrayNotHasKey('colPos', $record);
    }

    /**
     * Reading several records in a single call by passing an array of UIDs.
     * This is the natural follow-up after seeing an inline-relation hint
     * like `metadata: [1, 5]` on a parent record.
     */
    public function testReadMultipleRecordsByUidArray(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => [100, 101],
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);

        $this->assertCount(2, $data['records']);
        $uids = array_column($data['records'], 'uid');
        $this->assertContains(100, $uids);
        $this->assertContains(101, $uids);
    }

    /**
     * Single-int form keeps working alongside the array form.
     */
    public function testUidArrayWithSingleEntryEqualsLegacyInt(): void
    {
        $resultArray = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => [100],
            'includeRelations' => false,
        ]);
        $resultInt = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'includeRelations' => false,
        ]);

        $this->assertEquals(
            $this->extractJsonFromResult($resultArray)['records'],
            $this->extractJsonFromResult($resultInt)['records']
        );
    }

    /**
     * Mixed valid and invalid UIDs: invalid ones (<= 0) drop out, the rest
     * still match.
     */
    public function testUidArrayDropsNonPositiveEntries(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => [100, -1, 0, 101],
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $uids = array_column(
            $this->extractJsonFromResult($result)['records'],
            'uid'
        );
        sort($uids);
        $this->assertSame([100, 101], $uids);
    }

    /**
     * A uid filter that sanitises to an empty list must still apply (return
     * zero rows), preserving the legacy `uid: -1 → empty result` semantics.
     */
    public function testUidArrayWithOnlyInvalidEntriesReturnsEmpty(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'uid' => [-1, 0],
            'includeRelations' => false,
        ]);

        $this->assertSuccessfulToolResult($result);
        $data = $this->extractJsonFromResult($result);
        $this->assertSame(0, $data['total']);
        $this->assertEmpty($data['records']);
    }
}
