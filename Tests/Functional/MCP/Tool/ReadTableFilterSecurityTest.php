<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;

/**
 * Tests for structured filter parameter replacing the raw WHERE condition.
 *
 * The raw "where" parameter accepted arbitrary SQL fragments with only a trivially-
 * bypassable keyword blacklist. These tests verify the new "filters" parameter
 * provides equivalent functionality through parameterized queries only.
 */
class ReadTableFilterSecurityTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private ReadTableTool $tool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tool = new ReadTableTool();
    }

    // ─── Schema tests ────────────────────────────────────────────────

    public function testSchemaHasFiltersParameter(): void
    {
        $schema = $this->tool->getSchema();
        $properties = $schema['inputSchema']['properties'];

        $this->assertArrayHasKey('filters', $properties, 'Schema must expose a "filters" parameter');
        $this->assertEquals('array', $properties['filters']['type']);
        $this->assertArrayHasKey('items', $properties['filters']);
    }

    public function testSchemaDoesNotExposeWhereParameter(): void
    {
        $schema = $this->tool->getSchema();
        $properties = $schema['inputSchema']['properties'];

        $this->assertArrayNotHasKey('where', $properties, 'Raw "where" parameter must be removed from schema');
    }

    // ─── Basic filter functionality ──────────────────────────────────

    public function testFilterByEquality(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'CType', 'operator' => 'eq', 'value' => 'textmedia'],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        $this->assertGreaterThan(0, count($data['records']));
        foreach ($data['records'] as $record) {
            $this->assertEquals('textmedia', $record['CType']);
        }
    }

    public function testFilterByNotEqual(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'CType', 'operator' => 'neq', 'value' => 'textmedia'],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        foreach ($data['records'] as $record) {
            $this->assertNotEquals('textmedia', $record['CType']);
        }
    }

    public function testFilterByLike(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'header', 'operator' => 'like', 'value' => '%Welcome%'],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        $this->assertGreaterThan(0, count($data['records']));
        foreach ($data['records'] as $record) {
            $this->assertStringContainsString('Welcome', $record['header']);
        }
    }

    public function testFilterByGreaterThan(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'gt', 'value' => 100],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        foreach ($data['records'] as $record) {
            $this->assertGreaterThan(100, $record['uid']);
        }
    }

    public function testFilterByLessThan(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'lt', 'value' => 105],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        foreach ($data['records'] as $record) {
            $this->assertLessThan(105, $record['uid']);
        }
    }

    public function testFilterByIn(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'in', 'value' => [100, 101]],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        $uids = array_column($data['records'], 'uid');
        $this->assertCount(2, $uids);
        $this->assertContains(100, $uids);
        $this->assertContains(101, $uids);
    }

    public function testFilterByLessThanOrEqual(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'lte', 'value' => 100],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        foreach ($data['records'] as $record) {
            $this->assertLessThanOrEqual(100, $record['uid']);
        }
    }

    public function testFilterByGreaterThanOrEqual(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'gte', 'value' => 105],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        foreach ($data['records'] as $record) {
            $this->assertGreaterThanOrEqual(105, $record['uid']);
        }
    }

    public function testFilterByNotLike(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'header', 'operator' => 'notLike', 'value' => '%Welcome%'],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        foreach ($data['records'] as $record) {
            $this->assertStringNotContainsString('Welcome', $record['header'] ?? '');
        }
    }

    public function testFilterByNotIn(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'notIn', 'value' => [100, 101]],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        $uids = array_column($data['records'], 'uid');
        $this->assertNotContains(100, $uids);
        $this->assertNotContains(101, $uids);
    }

    public function testFilterByIsNull(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'header', 'operator' => 'isNull'],
            ],
        ]);

        // Should succeed (even if zero results)
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testFilterByIsNotNull(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'header', 'operator' => 'isNotNull'],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);
        $this->assertGreaterThan(0, count($data['records']));
    }

    // ─── Multiple filters (AND combination) ──────────────────────────

    public function testMultipleFiltersAreCombinedWithAnd(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'CType', 'operator' => 'eq', 'value' => 'textmedia'],
                ['field' => 'pid', 'operator' => 'eq', 'value' => 1],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        foreach ($data['records'] as $record) {
            $this->assertEquals('textmedia', $record['CType']);
            $this->assertEquals(1, $record['pid']);
        }
    }

    // ─── Validation: reject invalid filters ──────────────────────────

    public function testRejectsInvalidOperator(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'UNION SELECT', 'value' => '1'],
            ],
        ]);

        $this->assertTrue($result->isError, 'Invalid operator must be rejected');
    }

    public function testRejectsFilterWithoutField(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['operator' => 'eq', 'value' => '1'],
            ],
        ]);

        $this->assertTrue($result->isError, 'Filter without field must be rejected');
    }

    public function testRejectsFilterWithoutOperator(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'value' => '1'],
            ],
        ]);

        $this->assertTrue($result->isError, 'Filter without operator must be rejected');
    }

    public function testRejectsFieldNotInTable(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'nonexistent_field', 'operator' => 'eq', 'value' => '1'],
            ],
        ]);

        $this->assertTrue($result->isError, 'Field not in table must be rejected');
    }

    public function testRejectsFieldWithSqlInjectionAttempt(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid; DROP TABLE pages', 'operator' => 'eq', 'value' => '1'],
            ],
        ]);

        $this->assertTrue($result->isError, 'SQL injection in field name must be rejected');
    }

    public function testRejectsComparisonOperatorWithoutValue(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'eq'],
            ],
        ]);

        $this->assertTrue($result->isError, 'Comparison operator without value must be rejected');
    }

    public function testNotInOperatorRequiresArrayValue(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'notIn', 'value' => 'not-an-array'],
            ],
        ]);

        $this->assertTrue($result->isError, 'notIn operator with non-array value must be rejected');
    }

    // ─── Security: raw WHERE must be rejected ────────────────────────

    public function testRawWhereParameterIsRejectedOrIgnored(): void
    {
        // Even if someone passes "where", it must have no effect
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'where' => 'uid > 0 UNION SELECT username,password FROM be_users',
        ]);

        // Either: error (rejected) or success with normal results (ignored)
        if (!$result->isError) {
            $data = $this->extractJsonFromResult($result);
            // If not an error, the WHERE must have been completely ignored
            // — verify no be_users data leaked
            foreach ($data['records'] as $record) {
                $this->assertArrayNotHasKey('username', $record);
                $this->assertArrayNotHasKey('password', $record);
            }
        }
    }

    // ─── Count query uses same filters ───────────────────────────────

    public function testFiltersApplyToTotalCount(): void
    {
        // Get filtered result with a high limit to avoid pagination mismatch
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'limit' => 1000,
            'filters' => [
                ['field' => 'CType', 'operator' => 'eq', 'value' => 'textmedia'],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        // Total count must match the number of actual textmedia records, not all records
        $this->assertEquals(count($data['records']), $data['total']);
    }

    // ─── Backwards compatibility: filters + pid/uid coexist ──────────

    public function testFiltersCombineWithPidParameter(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'pid' => 1,
            'filters' => [
                ['field' => 'CType', 'operator' => 'eq', 'value' => 'textmedia'],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = $this->extractJsonFromResult($result);

        foreach ($data['records'] as $record) {
            $this->assertEquals('textmedia', $record['CType']);
            $this->assertEquals(1, $record['pid']);
        }
    }

    public function testInOperatorRequiresArrayValue(): void
    {
        $result = $this->tool->execute([
            'table' => 'tt_content',
            'filters' => [
                ['field' => 'uid', 'operator' => 'in', 'value' => 'not-an-array'],
            ],
        ]);

        $this->assertTrue($result->isError, 'in operator with non-array value must be rejected');
    }
}
