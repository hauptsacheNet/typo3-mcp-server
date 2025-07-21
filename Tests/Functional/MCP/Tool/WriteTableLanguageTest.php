<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class WriteTableLanguageTest extends FunctionalTestCase
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
     * Test creating content with ISO language code
     */
    public function testCreateContentWithIsoLanguageCode(): void
    {
        $tool = new WriteTableTool();
        
        // Create content in German using ISO code
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Deutscher Titel',
                'bodytext' => 'Deutscher Inhalt',
                'sys_language_uid' => 'de'  // ISO code instead of numeric ID
                /**
                 * IMPORTANT: This test demonstrates a key feature of the WriteTableTool.
                 * 
                 * Instead of using numeric language UIDs (which would require the LLM to know
                 * that German = 1, French = 2, etc.), the tool accepts ISO 639-1 language codes.
                 * 
                 * The WriteTableTool automatically converts these ISO codes to the correct
                 * numeric UIDs based on the site configuration. This makes the API much more
                 * intuitive for LLMs and reduces the need for them to maintain mappings.
                 * 
                 * Supported ISO codes are discovered from the site configuration and shown
                 * in the GetTableSchemaTool output for sys_language_uid fields.
                 */
            ]
        ]);
        
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $data = json_decode($result->content[0]->text, true);
        
        $this->assertEquals('create', $data['action']);
        $this->assertEquals('tt_content', $data['table']);
        $this->assertIsInt($data['uid']);
        
        // Verify the created record has correct language UID
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $record = $connection->select(['*'], 'tt_content', ['uid' => $data['uid']])->fetchAssociative();
        
        $this->assertNotFalse($record);
        $this->assertEquals(1, $record['sys_language_uid']); // German has UID 1
        $this->assertEquals('Deutscher Titel', $record['header']);
    }

    /**
     * Test error handling for invalid language code
     */
    public function testCreateWithInvalidLanguageCode(): void
    {
        $tool = new WriteTableTool();
        
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test',
                'sys_language_uid' => 'xx'  // Invalid ISO code
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Unknown language code: xx', $result->error ?? $result->content[0]->text);
    }

    /**
     * Test translate action
     */
    public function testTranslateRecord(): void
    {
        $tool = new WriteTableTool();
        
        // First create a record in default language
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original English Content',
                'bodytext' => 'This is the original content',
            ]
        ]);
        
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $createData = json_decode($createResult->content[0]->text, true);
        $originalUid = $createData['uid'];
        
        // Now translate it to German
        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de'
            ]
        ]);
        
        $this->assertFalse($translateResult->isError, json_encode($translateResult->jsonSerialize()));
        $translateData = json_decode($translateResult->content[0]->text, true);
        
        $this->assertEquals('translate', $translateData['action']);
        $this->assertEquals('tt_content', $translateData['table']);
        $this->assertEquals($originalUid, $translateData['sourceUid']);
        $this->assertEquals('de', $translateData['targetLanguage']);
        $this->assertNotEmpty($translateData['translationUid']);
        
        // Check if translation UID was found
        if (!is_int($translateData['translationUid'])) {
            $this->fail('Translation failed: ' . $translateData['translationUid']);
        }
        
        // Verify the translation was created - need to use BackendUtility to get workspace overlay
        $translation = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord('tt_content', $translateData['translationUid']);
        
        $this->assertNotFalse($translation, 'Translation record not found. UID was: ' . $translateData['translationUid']);
        $this->assertIsArray($translation, 'Translation should be an array');
        
        $this->assertEquals(1, $translation['sys_language_uid']); // German
        $this->assertEquals($originalUid, $translation['l18n_parent']); // TYPO3 uses l18n_parent for tt_content
        $this->assertStringContainsString('Original English Content', $translation['header']); // May have translation prefix
    }

    /**
     * Test translate action with invalid language
     */
    public function testTranslateWithInvalidLanguage(): void
    {
        $tool = new WriteTableTool();
        
        // Create a record first
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Test',
            ]
        ]);
        
        $createData = json_decode($createResult->content[0]->text, true);
        $uid = $createData['uid'];
        
        // Try to translate to invalid language
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => [
                'sys_language_uid' => 'xx'
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $errorMessage = $result->error ?? ($result->content[0]->text ?? '');
        $this->assertStringContainsString('Unknown language code: xx', $errorMessage);
    }

    /**
     * Test translating already translated record
     */
    public function testTranslateAlreadyTranslatedRecord(): void
    {
        $tool = new WriteTableTool();
        
        // Create original record
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original',
            ]
        ]);
        
        $createData = json_decode($createResult->content[0]->text, true);
        $originalUid = $createData['uid'];
        
        // Translate to German
        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de'
            ]
        ]);
        
        $translateData = json_decode($translateResult->content[0]->text, true);
        $germanUid = $translateData['translationUid'];
        
        // Try to translate the German translation (should fail)
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $germanUid,
            'data' => [
                'sys_language_uid' => 'fr'
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $errorMessage = $result->error ?? ($result->content[0]->text ?? '');
        $this->assertStringContainsString('Cannot translate a record that is already a translation', $errorMessage);
    }

    /**
     * Test duplicate translation prevention
     */
    public function testPreventDuplicateTranslation(): void
    {
        $tool = new WriteTableTool();
        
        // Create original record
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original',
            ]
        ]);
        
        $createData = json_decode($createResult->content[0]->text, true);
        $originalUid = $createData['uid'];
        
        // Translate to German
        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de'
            ]
        ]);
        
        $this->assertFalse($translateResult->isError);
        
        // Try to translate to German again (should fail)
        $result = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de'
            ]
        ]);
        
        $this->assertTrue($result->isError);
        $errorMessage = $result->error ?? ($result->content[0]->text ?? '');
        // TYPO3 returns a different error message when using DataHandler
        $this->assertTrue(
            str_contains($errorMessage, 'Translation already exists') || 
            str_contains($errorMessage, 'already are localizations'),
            'Expected error about existing translation, got: ' . $errorMessage
        );
    }

    /**
     * Test updating translation with ISO code preservation
     */
    public function testUpdateTranslationMaintainsLanguage(): void
    {
        $tool = new WriteTableTool();
        
        // Create original and translate
        $createResult = $tool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Original',
            ]
        ]);
        
        $createData = json_decode($createResult->content[0]->text, true);
        $originalUid = $createData['uid'];
        
        $translateResult = $tool->execute([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $originalUid,
            'data' => [
                'sys_language_uid' => 'de'
            ]
        ]);
        
        $translateData = json_decode($translateResult->content[0]->text, true);
        $germanUid = $translateData['translationUid'];
        
        // Update the German translation
        $updateResult = $tool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $germanUid,
            'data' => [
                'header' => 'Aktualisierter deutscher Titel',
                'bodytext' => 'Aktualisierter deutscher Inhalt'
            ]
        ]);
        
        $this->assertFalse($updateResult->isError, json_encode($updateResult->jsonSerialize()));
        
        // Verify the update - need to use BackendUtility to get workspace overlay
        $record = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord('tt_content', $germanUid);
        
        $this->assertNotFalse($record, 'German translation record not found');
        $this->assertEquals('Aktualisierter deutscher Titel', $record['header']);
        $this->assertEquals('Aktualisierter deutscher Inhalt', $record['bodytext']);
        $this->assertEquals(1, $record['sys_language_uid']); // Still German
        $this->assertEquals($originalUid, $record['l18n_parent']); // Still linked to original
    }
}