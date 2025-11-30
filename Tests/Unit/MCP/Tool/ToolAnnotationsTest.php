<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Unit\MCP\Tool;

use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\MCP\Tool\Record\ListTablesTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\GetFlexFormSchemaTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use PHPUnit\Framework\TestCase;

/**
 * Test that tools properly implement getAnnotations()
 */
class ToolAnnotationsTest extends TestCase
{
    /**
     * Test that read-only tools return correct annotations
     */
    public function testReadOnlyToolsReturnCorrectAnnotations(): void
    {
        // GetPageTreeTool
        $getPageTreeTool = $this->getMockBuilder(GetPageTreeTool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $annotations = $getPageTreeTool->getAnnotations();
        $this->assertIsArray($annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertContains('code_execution_20250825', $annotations['allowedCallers'] ?? []);

        // GetPageTool
        $getPageTool = $this->getMockBuilder(GetPageTool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $annotations = $getPageTool->getAnnotations();
        $this->assertIsArray($annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertContains('code_execution_20250825', $annotations['allowedCallers'] ?? []);

        // SearchTool
        $searchTool = $this->getMockBuilder(SearchTool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $annotations = $searchTool->getAnnotations();
        $this->assertIsArray($annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertContains('code_execution_20250825', $annotations['allowedCallers'] ?? []);

        // ListTablesTool
        $listTablesTool = $this->getMockBuilder(ListTablesTool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $annotations = $listTablesTool->getAnnotations();
        $this->assertIsArray($annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertContains('code_execution_20250825', $annotations['allowedCallers'] ?? []);

        // ReadTableTool
        $readTableTool = $this->getMockBuilder(ReadTableTool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $annotations = $readTableTool->getAnnotations();
        $this->assertIsArray($annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertContains('code_execution_20250825', $annotations['allowedCallers'] ?? []);

        // GetTableSchemaTool
        $getTableSchemaTool = $this->getMockBuilder(GetTableSchemaTool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $annotations = $getTableSchemaTool->getAnnotations();
        $this->assertIsArray($annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertContains('code_execution_20250825', $annotations['allowedCallers'] ?? []);

        // GetFlexFormSchemaTool
        $getFlexFormSchemaTool = $this->getMockBuilder(GetFlexFormSchemaTool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $annotations = $getFlexFormSchemaTool->getAnnotations();
        $this->assertIsArray($annotations);
        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertTrue($annotations['idempotentHint']);
        $this->assertContains('code_execution_20250825', $annotations['allowedCallers'] ?? []);
    }

    /**
     * Test that write tools return correct annotations
     */
    public function testWriteToolsReturnCorrectAnnotations(): void
    {
        $writeTableTool = $this->getMockBuilder(WriteTableTool::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $annotations = $writeTableTool->getAnnotations();
        $this->assertIsArray($annotations);
        $this->assertFalse($annotations['readOnlyHint']);
        $this->assertFalse($annotations['idempotentHint']);
        $this->assertContains('code_execution_20250825', $annotations['allowedCallers'] ?? []);
    }
}