<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\SiteInformationService;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Test file reference (sys_file_reference) support through MCP tools
 */
class FileReferenceTest extends FunctionalTestCase
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
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_metadata.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file_reference.csv');
        $this->setUpBackendUser(1);
    }

    /**
     * Test reading a content element returns embedded file references
     */
    public function testReadContentElementWithFileReferences(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];

        // assets field should contain embedded file references
        $this->assertArrayHasKey('assets', $record);
        $this->assertIsArray($record['assets']);
        $this->assertCount(2, $record['assets'], 'Should have 2 asset references');

        // Verify first file reference has expected fields
        $firstRef = $record['assets'][0];
        $this->assertArrayHasKey('uid', $firstRef);
        $this->assertArrayHasKey('uid_local', $firstRef);
        $this->assertEquals('Hero Image', $firstRef['title']);
        $this->assertEquals('The main hero image', $firstRef['description']);
        $this->assertEquals('Homepage hero banner', $firstRef['alternative']);

        // Verify file metadata enrichment
        $this->assertArrayHasKey('file_name', $firstRef);
        $this->assertEquals('test.jpg', $firstRef['file_name']);
        $this->assertArrayHasKey('file_identifier', $firstRef);
        $this->assertEquals('/user_upload/test.jpg', $firstRef['file_identifier']);
        $this->assertArrayHasKey('file_mime_type', $firstRef);
        $this->assertEquals('image/jpeg', $firstRef['file_mime_type']);
    }

    /**
     * Test that foreign_match_fields prevent cross-contamination between file fields.
     * The assets field and media field should not mix up their file references.
     */
    public function testFileReferencesAreFieldScoped(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];

        // assets field should have exactly 2 references
        $this->assertArrayHasKey('assets', $record);
        $this->assertCount(2, $record['assets'], 'assets field should have 2 references');

        // media field should have exactly 1 reference
        $this->assertArrayHasKey('media', $record);
        $this->assertCount(1, $record['media'], 'media field should have 1 reference');

        // Verify the media reference is the correct one
        $mediaRef = $record['media'][0];
        $this->assertEquals('Media File', $mediaRef['title']);
    }

    /**
     * Test creating a content element with file references
     */
    public function testCreateContentElementWithFileReferences(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create a content element with an assets reference to existing sys_file uid=1
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Content with assets',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Created Image', 'alternative' => 'Created alt text'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $responseData = json_decode($result->content[0]->text, true);
        $contentUid = $responseData['uid'];

        // Read back and verify
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];

        $this->assertArrayHasKey('assets', $record);
        $this->assertCount(1, $record['assets']);

        $ref = $record['assets'][0];
        $this->assertEquals('Created Image', $ref['title']);
        $this->assertEquals('Created alt text', $ref['alternative']);
        $this->assertEquals(1, $ref['uid_local']);

        // Verify file enrichment on the created reference
        $this->assertEquals('test.jpg', $ref['file_name']);
    }

    /**
     * Test that file references work correctly in workspaces (live UIDs exposed)
     */
    public function testFileReferencesInWorkspace(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create content with file reference (this goes into a workspace)
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Workspace content with assets',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Workspace Image'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        // Read it back - should show the file reference
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];

        $this->assertArrayHasKey('assets', $record);
        $this->assertNotEmpty($record['assets'], 'File references should be visible in workspace');
        $this->assertEquals('Workspace Image', $record['assets'][0]['title']);
    }

    /**
     * Test updating file reference metadata (title, alt text, description)
     */
    public function testUpdateFileReferenceMetadata(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create content with a file reference
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Content for update test',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Original Title', 'alternative' => 'Original Alt'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        // Update with new file references (replaces all)
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Updated Title', 'alternative' => 'Updated Alt'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];

        $this->assertCount(1, $record['assets']);
        $this->assertEquals('Updated Title', $record['assets'][0]['title']);
        $this->assertEquals('Updated Alt', $record['assets'][0]['alternative']);
    }

    /**
     * Test reading sys_file as a read-only table
     */
    public function testReadSysFileTable(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertNotEmpty($data['records']);

        $file = $data['records'][0];
        $this->assertEquals(1, $file['uid']);
        $this->assertEquals('test.jpg', $file['name']);
        $this->assertEquals('/user_upload/test.jpg', $file['identifier']);
    }

    /**
     * public_url is registered as a computed field via AfterSchemaLoadEvent, so it
     * behaves like any other advertised column: returned by default, narrowed by
     * the `fields` whitelist when the caller passes one.
     */
    public function testSysFilePublicUrlIsAdvertisedField(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        // Default: public_url is part of the standard response
        $result = $readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $defaultFile = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('public_url', $defaultFile, 'public_url should be in the default response');

        // Whitelist that omits public_url drops it like any other column
        $result = $readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
            'fields' => ['name'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $narrowed = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayNotHasKey('public_url', $narrowed, 'public_url should drop when whitelist excludes it');
        $this->assertEquals('test.jpg', $narrowed['name']);
    }

    /**
     * GetTableSchema(sys_file) used to short-circuit with "No field layout defined
     * for this type" because sys_file's TCA only defines a showitem for type "1".
     * Picking a different default type (or any type without showitem) hid every
     * useful column. The schema must list filterable columns regardless.
     */
    public function testSysFileSchemaListsFilterableColumns(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $result = $tool->execute(['table' => 'sys_file']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;
        $this->assertStringNotContainsString('No field layout defined', $content);
        foreach (['name', 'identifier', 'mime_type', 'sha1', 'size', 'type'] as $column) {
            $this->assertStringContainsString($column, $content, "sys_file schema should mention '$column'");
        }
    }

    /**
     * GetTableSchema(sys_file_reference) used to expose only the type-"1" palette
     * (title, description, uid_local, hidden, sys_language_uid, l10n_parent),
     * hiding fields like alternative/link/crop/autoplay even though WriteTable
     * accepts them. Foreign type notation must surface the union of fields.
     */
    public function testSysFileReferenceSchemaIncludesAllWritableFields(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $result = $tool->execute(['table' => 'sys_file_reference']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;
        foreach (['title', 'description', 'alternative', 'link', 'crop', 'autoplay'] as $column) {
            $this->assertStringContainsString($column, $content, "sys_file_reference schema should mention '$column'");
        }
    }

    /**
     * Computed fields registered via AfterSchemaLoadEvent must show up in their
     * own section so the LLM can discover them and opt in via `fields`.
     */
    public function testComputedFieldsAreAdvertisedInSchema(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);

        $sysFile = $tool->execute(['table' => 'sys_file']);
        $this->assertFalse($sysFile->isError, json_encode($sysFile->jsonSerialize()));
        $this->assertStringContainsString('Computed read-only — included by default', $sysFile->content[0]->text);
        $this->assertStringContainsString('public_url', $sysFile->content[0]->text);

        $sysFileReference = $tool->execute(['table' => 'sys_file_reference']);
        $this->assertFalse($sysFileReference->isError, json_encode($sysFileReference->jsonSerialize()));
        $content = $sysFileReference->content[0]->text;
        $this->assertStringContainsString('Computed read-only — included by default', $content);
        foreach (['file_name', 'file_identifier', 'file_mime_type', 'file_size', 'public_url'] as $field) {
            $this->assertStringContainsString($field, $content, "computed field '$field' should appear");
        }
    }

    /**
     * The LLM needs an absolute URL to download a file. TYPO3's getPublicUrl()
     * returns a path relative to the document root; the listener must promote
     * it to "<scheme>://<host>/<path>" using the current request context.
     */
    public function testPublicUrlIsAbsoluteWhenRequestIsAvailable(): void
    {
        $request = new ServerRequest('https://typo3.example.com/api/mcp');
        GeneralUtility::makeInstance(SiteInformationService::class)->setCurrentRequest($request);

        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $file = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('public_url', $file);
        $this->assertStringStartsWith('https://typo3.example.com/', $file['public_url']);
    }

    /**
     * Embedded sys_file_reference children used to leak every TYPO3 plumbing
     * field (t3ver_*, l10n_state, deleted) because processRecord skipped its
     * field filter when the type field uses foreign type notation. Now the
     * filter always applies — non-TCA columns are gone, TCA columns plus
     * declared computed fields remain.
     */
    public function testInlineChildrenStripWorkspaceAndTranslationPlumbing(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => 100,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $ref = json_decode($result->content[0]->text, true)['records'][0]['assets'][0];
        foreach (['t3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage', 'l10n_state', 'deleted'] as $leaked) {
            $this->assertArrayNotHasKey($leaked, $ref, "embedded ref should not expose '$leaked'");
        }

        // Option a: inline children always include enrichment regardless of fields.
        $this->assertArrayHasKey('public_url', $ref);
        $this->assertArrayHasKey('file_name', $ref);
    }

    /**
     * sys_file_metadata is exposed as a standalone table (configured via
     * `additionalStandaloneTables`), so reading sys_file no longer embeds the
     * full metadata records. The inline `metadata` field collapses to a list
     * of UIDs — enough for the LLM to discover that metadata exists and to
     * fetch/edit it through the sys_file_metadata table directly.
     */
    public function testSysFileMetadataIsNotEmbeddedAsStandaloneTable(): void
    {
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'sys_file',
            'uid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $file = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertArrayHasKey('metadata', $file);
        $this->assertSame([1], $file['metadata'], 'metadata field should be a list of uids, not embedded objects');
    }

    /**
     * Test that sys_file is read-only (writing should fail)
     */
    public function testSysFileIsReadOnly(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $writeTool->execute([
            'table' => 'sys_file',
            'action' => 'create',
            'pid' => 0,
            'data' => [
                'name' => 'hacked.jpg',
            ],
        ]);
        $this->assertTrue($result->isError, 'Writing to sys_file should fail');
    }

    /**
     * The crop value (TCA type=imageManipulation) is stored as a JSON string in
     * the database and ReadTableTool decodes it back into an array. A round trip
     * through update must preserve the structure — sending the value back as the
     * same array shape that read returned must not corrupt it into the literal
     * string "Array" (which is what a (string)$array cast yields).
     */
    public function testCropFieldRoundTripThroughCreateUpdateRead(): void
    {
        // Use fractional values so the JSON round trip can't paper over a type
        // change from float to int.
        $cropPayload = [
            'default' => [
                'cropArea' => [
                    'x' => 0.1,
                    'y' => 0.2,
                    'width' => 0.8,
                    'height' => 0.7,
                ],
                'selectedRatio' => 'NaN',
                'focusArea' => [],
            ],
        ];

        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        // Create with crop provided as an array (shape clients see when reading)
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Crop array round trip',
                'CType' => 'textmedia',
                'assets' => [
                    [
                        'uid_local' => 1,
                        'title' => 'Image with crop',
                        'crop' => $cropPayload,
                    ],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        // Read after create — crop must come back as the same array
        $result = $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $afterCreate = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertCount(1, $afterCreate['assets']);
        $this->assertIsArray(
            $afterCreate['assets'][0]['crop'] ?? null,
            'crop must be returned as an array after create, got: '
            . var_export($afterCreate['assets'][0]['crop'] ?? null, true)
        );
        $this->assertEquals($cropPayload, $afterCreate['assets'][0]['crop']);

        // Get the existing reference uid so update patches the same row
        $referenceUid = (int)$afterCreate['assets'][0]['uid'];

        // Update — feed crop back as an array (the shape the read returned)
        $newCrop = $cropPayload;
        $newCrop['default']['cropArea']['width'] = 0.42;

        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'assets' => [
                    [
                        'uid' => $referenceUid,
                        'title' => 'Image with crop updated',
                        'crop' => $newCrop,
                    ],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read after update — crop must still be the (updated) array, not "Array"
        $result = $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $afterUpdate = json_decode($result->content[0]->text, true)['records'][0];
        $this->assertCount(1, $afterUpdate['assets']);

        $cropAfterUpdate = $afterUpdate['assets'][0]['crop'] ?? null;
        $this->assertNotSame(
            'Array',
            $cropAfterUpdate,
            'crop got cast to the string "Array" — array value reached the DB without being JSON-encoded'
        );
        $this->assertIsArray(
            $cropAfterUpdate,
            'crop must be returned as an array after update, got: ' . var_export($cropAfterUpdate, true)
        );
        $this->assertEquals($newCrop, $cropAfterUpdate);
    }

    /**
     * Updating only sibling fields on an existing reference must not corrupt the
     * crop value already stored in the database. This is the read-modify-write
     * scenario flagged in real usage: the LLM updates the title, leaves crop
     * untouched, and the existing crop JSON survives.
     */
    public function testCropSurvivesUpdateOfSiblingFieldsOnly(): void
    {
        $cropPayload = [
            'default' => [
                'cropArea' => ['x' => 0.1, 'y' => 0.2, 'width' => 0.8, 'height' => 0.7],
                'selectedRatio' => 'NaN',
                'focusArea' => [],
            ],
        ];

        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);

        // Seed the row with a crop value via create
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Sibling-only update',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'Initial', 'crop' => $cropPayload],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        $afterCreate = json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid])->content[0]->text,
            true
        )['records'][0];
        $referenceUid = (int)$afterCreate['assets'][0]['uid'];

        // Update only the title — do not send crop
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'assets' => [
                    ['uid' => $referenceUid, 'title' => 'Just retitled'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $afterUpdate = json_decode(
            $readTool->execute(['table' => 'tt_content', 'uid' => $contentUid])->content[0]->text,
            true
        )['records'][0];

        $this->assertSame('Just retitled', $afterUpdate['assets'][0]['title']);
        $this->assertIsArray(
            $afterUpdate['assets'][0]['crop'] ?? null,
            'pre-existing crop must survive an update that does not touch it'
        );
        $this->assertEquals($cropPayload, $afterUpdate['assets'][0]['crop']);
    }

    /**
     * Test removing file references by updating with empty array
     */
    public function testRemoveFileReferences(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);

        // Create content with file references
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'create',
            'pid' => 1,
            'data' => [
                'header' => 'Content to clear assets',
                'CType' => 'textmedia',
                'assets' => [
                    ['uid_local' => 1, 'title' => 'To be removed'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $contentUid = json_decode($result->content[0]->text, true)['uid'];

        // Update with empty assets array to remove all references
        $result = $writeTool->execute([
            'table' => 'tt_content',
            'action' => 'update',
            'uid' => $contentUid,
            'data' => [
                'assets' => [],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Read back - should have no assets
        $readTool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $readTool->execute([
            'table' => 'tt_content',
            'uid' => $contentUid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $record = $data['records'][0];

        $this->assertArrayHasKey('assets', $record);
        $this->assertEmpty($record['assets'], 'Asset references should be removed');
    }
}
