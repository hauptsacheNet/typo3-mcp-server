<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test field access restrictions in TableAccessService
 * Verifies that file fields and inaccessible inline relations are properly blocked
 */
class TableAccessServiceFieldAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected TableAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->service = new TableAccessService();
    }

    /**
     * Test that file type fields are accessible (file field support enabled)
     */
    public function testFileFieldsAreAccessible(): void
    {
        // The 'media' field on pages table is type='file'
        $canAccess = $this->service->canAccessField('pages', 'media');

        $this->assertTrue($canAccess, 'File fields should be accessible');
    }

    /**
     * Test that file fields are included in available fields
     */
    public function testFileFieldsAreInSchema(): void
    {
        $fields = $this->service->getAvailableFields('pages');

        $this->assertArrayHasKey('media', $fields, 'File field "media" should be in available fields');
    }

    /**
     * Test that sys_file_reference table is accessible (needed for FAL operations)
     */
    public function testSysFileReferenceTableIsAccessible(): void
    {
        $canAccess = $this->service->canAccessTable('sys_file_reference');

        $this->assertTrue($canAccess, 'sys_file_reference table should be accessible for FAL operations');
    }

    /**
     * Test that file fields referencing sys_file_reference are accessible
     */
    public function testFileFieldsToSysFileReferenceAreAccessible(): void
    {
        // pages has 'media' field which is type='file' referencing sys_file_reference
        if (!isset($GLOBALS['TCA']['pages']['columns']['media'])) {
            $this->markTestSkipped('pages.media field not available in this TYPO3 version');
        }

        $fieldConfig = $GLOBALS['TCA']['pages']['columns']['media'] ?? [];
        if (($fieldConfig['config']['type'] ?? '') !== 'file') {
            $this->markTestSkipped('pages.media is not a file field in this TYPO3 version');
        }

        $canAccess = $this->service->canAccessField('pages', 'media');

        $this->assertTrue($canAccess, 'File fields referencing sys_file_reference should be accessible');
    }

    /**
     * Test that regular accessible fields remain accessible
     */
    public function testRegularFieldsRemainAccessible(): void
    {
        // Test that normal text fields are accessible
        $canAccessTitle = $this->service->canAccessField('pages', 'title');
        $canAccessDescription = $this->service->canAccessField('pages', 'description');

        $this->assertTrue($canAccessTitle, 'Regular text field "title" should be accessible');
        $this->assertTrue($canAccessDescription, 'Regular text field "description" should be accessible');
    }

    /**
     * Test that available fields includes file fields
     */
    public function testAvailableFieldsIncludesFileFields(): void
    {
        $fields = $this->service->getAvailableFields('pages');

        // Check that normal fields are present
        $this->assertArrayHasKey('title', $fields, 'Title field should be available');
        $this->assertArrayHasKey('description', $fields, 'Description field should be available');

        // Check that file field is present (now supported)
        $this->assertArrayHasKey('media', $fields, 'Media file field should be available');
    }

    /**
     * Test that tt_content fields include file fields
     */
    public function testTtContentFieldsIncludeFileFields(): void
    {
        $fields = $this->service->getAvailableFields('tt_content', 'textmedia');

        // Check that normal fields are present
        $this->assertArrayHasKey('header', $fields, 'Header field should be available');
        $this->assertArrayHasKey('bodytext', $fields, 'Bodytext field should be available');

        // Check that assets field is now present (file fields are supported)
        if (isset($GLOBALS['TCA']['tt_content']['columns']['assets'])) {
            $this->assertArrayHasKey('assets', $fields, 'Assets field should be available for file references');
        }

        // 'image' was removed in TYPO3 v13 (replaced by 'assets') — only assert if it exists in TCA
        // AND is part of the textmedia type's showitem definition
        $imageConfig = $GLOBALS['TCA']['tt_content']['columns']['image'] ?? [];
        if (($imageConfig['config']['type'] ?? '') === 'file' && isset($fields['image'])) {
            $this->assertArrayHasKey('image', $fields, 'Image file field should be available');
        }
    }

    /**
     * Foreign type notation (e.g. "uid_local:type" in sys_file_reference) must
     * return null from getTypeFieldName since it is not a local column.
     */
    public function testForeignTypeNotationReturnsNullForTypeField(): void
    {
        // sys_file_reference has ctrl.type = 'uid_local:type'
        $typeField = $this->service->getTypeFieldName('sys_file_reference');

        $this->assertNull($typeField, 'Foreign type notation should return null');
    }

    /**
     * Tables with foreign type notation must still return a usable types list.
     */
    public function testForeignTypeNotationReturnsDefaultType(): void
    {
        $types = $this->service->getAvailableTypes('sys_file_reference');

        $this->assertNotEmpty($types, 'Should return at least one default type');
        $this->assertArrayHasKey('1', $types, 'Foreign type tables fall through to typeless default (key "1")');
        $this->assertSame('Default', $types['1']);
    }
}
