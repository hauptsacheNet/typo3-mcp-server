<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
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
    ];

    /**
     * Set TSconfig before TYPO3 bootstraps
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

        // TYPO3 14 removed `$GLOBALS['TYPO3_CONF_VARS']['BE']['defaultPageTSconfig']`
        // (#101799). Default page TSconfig must now be supplied via an
        // extension's `Configuration/page.tsconfig`. This test class still
        // depends on the legacy mechanism; skip on v14 until the schema tool
        // is reworked to source TSconfig from a page in the rootline.
        if (GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() >= 14) {
            $this->markTestSkipped(
                'TSconfig-based field filtering uses defaultPageTSconfig which was '
                . 'removed in TYPO3 14. Test relies on a v13-only mechanism.'
            );
        }

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
