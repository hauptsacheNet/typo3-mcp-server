<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Mcp\Types\TextContent;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test TSconfig field visibility support for GetTableSchemaTool
 *
 * This test class sets TSconfig via configurationToUseInTestInstance
 * to disable the bodytext field globally.
 */
class GetTableSchemaTSconfigTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
        '../Tests/Functional/Fixtures/Extensions/test_tsconfig',
    ];

    /**
     * Set TSconfig before TYPO3 bootstraps.
     * TYPO3 13 uses BE.defaultPageTSconfig, while TYPO3 14 uses
     * Configuration/page.tsconfig in the test_tsconfig fixture extension.
     */
    protected array $configurationToUseInTestInstance = [
        'BE' => [
            'defaultPageTSconfig' => '
                TCEFORM.tt_content.bodytext.disabled = 1
                TCEFORM.tt_content.date.disabled = 1
                TCEFORM.pages.abstract.disabled = 1
            ',
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Import base fixtures
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        // Set up backend user for DataHandler and TableAccessService
        $this->setUpBackendUser(1);
    }

    /**
     * Test that globally disabled fields are hidden from schema
     */
    public function testHidesGloballyDisabledFields(): void
    {
        $tool = new GetTableSchemaTool();
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia'
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        // Extract just the FIELDS section for field presence checks
        $fieldsSection = substr($content, strpos($content, 'FIELDS:') ?: 0);

        // bodytext field definition should NOT appear in FIELDS section
        $this->assertStringNotContainsString('- bodytext (', $fieldsSection);
        // date field definition should NOT appear in FIELDS section
        $this->assertStringNotContainsString('- date (', $fieldsSection);
        $this->assertStringNotContainsString('├─ date (', $fieldsSection);

        // Other fields should still appear
        $this->assertStringContainsString('header', $content);
        $this->assertStringContainsString('CType', $content);
    }

    /**
     * Test that non-disabled fields still appear normally
     */
    public function testNonDisabledFieldsAppearNormally(): void
    {
        $tool = new GetTableSchemaTool();
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia'
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Essential fields should still appear
        $this->assertStringContainsString('header', $content);
        $this->assertStringContainsString('CType', $content);
        $this->assertStringContainsString('hidden', $content);
        $this->assertStringContainsString('header_layout', $content);
    }

    /**
     * Test that TSconfig disabled applies to all users including admins
     */
    public function testDisabledAppliesToAdminUsers(): void
    {
        // Verify admin user is set up
        $this->assertTrue($GLOBALS['BE_USER']->isAdmin());

        $tool = new GetTableSchemaTool();
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'textmedia'
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Extract just the FIELDS section
        $fieldsSection = substr($content, strpos($content, 'FIELDS:') ?: 0);

        // Even for admin, bodytext field definition should be hidden
        $this->assertStringNotContainsString('- bodytext (', $fieldsSection);
    }

    /**
     * Test disabled field in pages table
     */
    public function testDisabledFieldInPagesTable(): void
    {
        $tool = new GetTableSchemaTool();
        $result = $tool->execute([
            'table' => 'pages'
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Extract just the FIELDS section
        $fieldsSection = substr($content, strpos($content, 'FIELDS:') ?: 0);

        // abstract field definition should be hidden
        $this->assertStringNotContainsString('- abstract (', $fieldsSection);
        $this->assertStringNotContainsString('├─ abstract (', $fieldsSection);
        $this->assertStringNotContainsString('└─ abstract (', $fieldsSection);

        // Other fields should appear
        $this->assertStringContainsString('title', $content);
        $this->assertStringContainsString('slug', $content);
    }

    /**
     * Test that different content types work correctly
     */
    public function testDifferentContentTypes(): void
    {
        $tool = new GetTableSchemaTool();

        // Check text type - bodytext should be hidden here too
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'text'
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $content = $result->content[0]->text;

        // Extract just the FIELDS section
        $fieldsSection = substr($content, strpos($content, 'FIELDS:') ?: 0);

        // bodytext field definition should be hidden
        $this->assertStringNotContainsString('- bodytext (', $fieldsSection);
        $this->assertStringContainsString('header', $content);
    }
}
