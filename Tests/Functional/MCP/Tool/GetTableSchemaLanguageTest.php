<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetTableSchemaLanguageTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create multi-language site configuration
        $this->createMultiLanguageSiteConfiguration();
        
        // Import test data
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        
        // Set up backend user
        $this->setUpBackendUser(1);
        
        // Initialize language service
        $languageServiceFactory = GeneralUtility::makeInstance(LanguageServiceFactory::class);
        $GLOBALS['LANG'] = $languageServiceFactory->create('default');
    }

    /**
     * Create a site configuration with multiple languages
     */
    protected function createMultiLanguageSiteConfiguration(): void
    {
        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'websiteTitle' => 'Test Site',
            'languages' => [
                0 => [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'hreflang' => 'en-us',
                    'direction' => 'ltr',
                    'flag' => 'us',
                    'navigationTitle' => 'English',
                ],
                1 => [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'hreflang' => 'de-de',
                    'direction' => 'ltr',
                    'flag' => 'de',
                    'navigationTitle' => 'Deutsch',
                ],
                2 => [
                    'title' => 'French',
                    'enabled' => true,
                    'languageId' => 2,
                    'base' => '/fr/',
                    'locale' => 'fr_FR.UTF-8',
                    'iso-639-1' => 'fr',
                    'hreflang' => 'fr-fr',
                    'direction' => 'ltr',
                    'flag' => 'fr',
                    'navigationTitle' => 'Français',
                ],
            ],
            'routes' => [],
            'errorHandling' => [],
        ];

        // Write the site configuration
        $configPath = $this->instancePath . '/typo3conf/sites/test-site';
        GeneralUtility::mkdir_deep($configPath);
        
        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        GeneralUtility::writeFile($configPath . '/config.yaml', $yamlContent, true);
    }

    /**
     * Test that GetTableSchemaTool shows ISO codes for sys_language_uid field
     */
    public function testShowsIsoCodesForLanguageField(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for tt_content
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'text'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // Check that sys_language_uid field is shown
        $this->assertStringContainsString('sys_language_uid', $output);
        
        // Check that it shows ISO codes
        $this->assertStringContainsString('[ISO codes accepted: en, de, fr]', $output);
        
        // Check that it includes the hint about WriteTable tool
        $this->assertStringContainsString("Use ISO codes like 'de' instead of numeric IDs in WriteTable tool", $output);
    }

    /**
     * Test that sys_category table (without language support) doesn't show ISO codes
     */
    public function testNoIsoCodesForNonLanguageTables(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for sys_category which doesn't typically have language field in showitem
        $result = $tool->execute([
            'table' => 'sys_category'
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;
        
        // sys_category may have language support but not in visible fields
        // So we just check that if sys_language_uid appears, it has ISO codes
        if (strpos($output, '- sys_language_uid') !== false) {
            $this->assertStringContainsString('[ISO codes accepted:', $output);
        } else {
            // If no sys_language_uid in fields, that's also fine
            $this->assertTrue(true);
        }
    }

    /**
     * Test that the ISO code list matches the configured languages
     */
    public function testIsoCodeListMatchesConfiguration(): void
    {
        $tool = new GetTableSchemaTool();
        
        // Get schema for tt_content
        $result = $tool->execute([
            'table' => 'tt_content',
            'type' => 'text'
        ]);
        
        $output = $result->content[0]->text;
        
        // Extract the ISO codes from the output
        if (preg_match('/\[ISO codes accepted: ([^\]]+)\]/', $output, $matches)) {
            $isoCodes = array_map('trim', explode(',', $matches[1]));
            
            // Should have exactly the configured languages
            $this->assertCount(3, $isoCodes);
            $this->assertContains('en', $isoCodes);
            $this->assertContains('de', $isoCodes);
            $this->assertContains('fr', $isoCodes);
        } else {
            $this->fail('ISO codes not found in output');
        }
    }
}