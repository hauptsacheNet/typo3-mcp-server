<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\LanguageService;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\SiteConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class LanguageServiceTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];
    
    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected LanguageService $languageService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create multi-language site configuration
        $this->createMultiLanguageSiteConfiguration();
        
        // Initialize the language service
        $this->languageService = GeneralUtility::makeInstance(LanguageService::class);
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
                    'fallbackType' => 'fallback',
                    'fallbacks' => '0',
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
                    'fallbackType' => 'fallback',
                    'fallbacks' => '0,1',
                ],
                3 => [
                    'title' => 'Spanish',
                    'enabled' => true,
                    'languageId' => 3,
                    'base' => '/es/',
                    'locale' => 'es_ES.UTF-8',
                    'iso-639-1' => 'es',
                    'hreflang' => 'es-es',
                    'direction' => 'ltr',
                    'flag' => 'es',
                    'navigationTitle' => 'Español',
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
     * Test getting UID from ISO code
     */
    public function testGetUidFromIsoCode(): void
    {
        // Test valid ISO codes
        $this->assertEquals(0, $this->languageService->getUidFromIsoCode('en'));
        $this->assertEquals(1, $this->languageService->getUidFromIsoCode('de'));
        $this->assertEquals(2, $this->languageService->getUidFromIsoCode('fr'));
        $this->assertEquals(3, $this->languageService->getUidFromIsoCode('es'));
        
        // Test case insensitivity
        $this->assertEquals(1, $this->languageService->getUidFromIsoCode('DE'));
        $this->assertEquals(2, $this->languageService->getUidFromIsoCode('Fr'));
        
        // Test invalid ISO code
        $this->assertNull($this->languageService->getUidFromIsoCode('xx'));
    }

    /**
     * Test getting ISO code from UID
     */
    public function testGetIsoCodeFromUid(): void
    {
        $this->assertEquals('en', $this->languageService->getIsoCodeFromUid(0));
        $this->assertEquals('de', $this->languageService->getIsoCodeFromUid(1));
        $this->assertEquals('fr', $this->languageService->getIsoCodeFromUid(2));
        $this->assertEquals('es', $this->languageService->getIsoCodeFromUid(3));
        
        // Test invalid UID
        $this->assertNull($this->languageService->getIsoCodeFromUid(99));
    }

    /**
     * Test getting available ISO codes
     */
    public function testGetAvailableIsoCodes(): void
    {
        $isoCodes = $this->languageService->getAvailableIsoCodes();
        
        $this->assertIsArray($isoCodes);
        $this->assertCount(4, $isoCodes);
        $this->assertContains('en', $isoCodes);
        $this->assertContains('de', $isoCodes);
        $this->assertContains('fr', $isoCodes);
        $this->assertContains('es', $isoCodes);
    }

    /**
     * Test getting default language ISO code
     */
    public function testGetDefaultIsoCode(): void
    {
        $defaultIsoCode = $this->languageService->getDefaultIsoCode();
        
        $this->assertEquals('en', $defaultIsoCode);
    }

    /**
     * Test checking if ISO code is available
     */
    public function testIsIsoCodeAvailable(): void
    {
        $this->assertTrue($this->languageService->isIsoCodeAvailable('en'));
        $this->assertTrue($this->languageService->isIsoCodeAvailable('de'));
        $this->assertTrue($this->languageService->isIsoCodeAvailable('DE')); // Case insensitive
        
        $this->assertFalse($this->languageService->isIsoCodeAvailable('xx'));
        $this->assertFalse($this->languageService->isIsoCodeAvailable('jp'));
    }

    /**
     * Test getting all language mappings
     */
    public function testGetAllMappings(): void
    {
        $mappings = $this->languageService->getAllMappings();
        
        $this->assertIsArray($mappings);
        $this->assertCount(4, $mappings);
        
        $this->assertEquals(0, $mappings['en']);
        $this->assertEquals(1, $mappings['de']);
        $this->assertEquals(2, $mappings['fr']);
        $this->assertEquals(3, $mappings['es']);
    }

    /**
     * Test getting all language information
     */
    public function testGetAllLanguageInfo(): void
    {
        $languages = $this->languageService->getAllLanguageInfo();
        
        $this->assertIsArray($languages);
        $this->assertCount(4, $languages);
        
        // Check first language (English)
        $english = $languages[0];
        $this->assertEquals(0, $english['uid']);
        $this->assertEquals('en', $english['isoCode']);
        $this->assertEquals('English', $english['title']);
        // TYPO3 Locale object toString returns format like 'en-US' not 'en_US.UTF-8'
        $this->assertEquals('en-US', $english['locale']);
        $this->assertTrue($english['enabled']);
        
        // Check sorting by UID
        $this->assertEquals(1, $languages[1]['uid']);
        $this->assertEquals(2, $languages[2]['uid']);
        $this->assertEquals(3, $languages[3]['uid']);
    }

    /**
     * Test ISO code extraction from different locale formats
     */
    public function testIsoCodeExtractionFromVariousFormats(): void
    {
        // Create a site with various locale formats
        $siteConfiguration = [
            'rootPageId' => 2,
            'base' => 'https://example2.com/',
            'websiteTitle' => 'Test Site 2',
            'languages' => [
                10 => [
                    'title' => 'Italian',
                    'enabled' => true,
                    'languageId' => 10,
                    'base' => '/it/',
                    'locale' => 'it_IT', // Without .UTF-8
                    'iso-639-1' => 'it',
                    'hreflang' => 'it-it',
                ],
                11 => [
                    'title' => 'Japanese',
                    'enabled' => true,
                    'languageId' => 11,
                    'base' => '/ja/',
                    'locale' => 'ja_JP.UTF-8',
                    'iso-639-1' => '', // Empty ISO code
                    'hreflang' => 'ja', // Single language code
                ],
                12 => [
                    'title' => 'Chinese',
                    'enabled' => true,
                    'languageId' => 12,
                    'base' => '/zh/',
                    'locale' => 'zh_CN.UTF-8',
                    // No iso-639-1 field
                    'hreflang' => 'zh-cn',
                ],
            ],
        ];

        // Write the additional site configuration
        $configPath = $this->instancePath . '/typo3conf/sites/test-site-2';
        GeneralUtility::mkdir_deep($configPath);
        
        $yamlContent = Yaml::dump($siteConfiguration, 99, 2);
        GeneralUtility::writeFile($configPath . '/config.yaml', $yamlContent, true);
        
        // Re-initialize the service to pick up new site
        $newLanguageService = GeneralUtility::makeInstance(LanguageService::class, '_', true);
        
        // Test that all formats are properly extracted
        $this->assertEquals(10, $newLanguageService->getUidFromIsoCode('it'));
        $this->assertEquals(11, $newLanguageService->getUidFromIsoCode('ja'));
        $this->assertEquals(12, $newLanguageService->getUidFromIsoCode('zh'));
    }
}