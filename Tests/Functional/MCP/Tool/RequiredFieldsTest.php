<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RequiredFieldsTest extends AbstractFunctionalTest
{
    private const TYPE = 'testrequired_textmedia';
    private const CATEGORIES_TYPE = 'testrequired_categories';
    private const CONDITIONAL_TYPE = 'testrequired_conditional';
    private const STAGE_TYPE = 'testrequired_stage';

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_required_fields',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->writeSiteConfiguration();
    }

    public function testSchemaMarksRequiredFieldsOfTheRecordType(): void
    {
        $schema = $this->getSchema('tt_content', self::TYPE);

        $this->assertStringContainsString('[Required]', $this->fieldLine($schema, 'header'));
        $this->assertStringContainsString('[Required]', $this->fieldLine($schema, 'bodytext'));
        $assetsLine = $this->fieldLine($schema, 'assets');
        $this->assertStringContainsString('[Required]', $assetsLine);
        $this->assertStringContainsString('[minitems: 1]', $assetsLine);
        $this->assertStringContainsString('[maxitems: 3]', $assetsLine);
    }

    public function testSchemaDoesNotMarkFieldsRequiredOnlyByAnotherType(): void
    {
        $schema = $this->getSchema('tt_content', 'textmedia');

        $this->assertStringNotContainsString('[Required]', $this->fieldLine($schema, 'header'));
        $this->assertStringNotContainsString('[Required]', $this->fieldLine($schema, 'bodytext'));
        $this->assertStringNotContainsString('[minitems:', $this->fieldLine($schema, 'assets'));
    }

    public function testSchemaMarksRequiredBaseColumn(): void
    {
        $schema = $this->getSchema('pages', '1');

        $this->assertStringContainsString('[Required]', $this->fieldLine($schema, 'title'));
    }

    public function testCreateWithAllRequiredFieldsSucceeds(): void
    {
        $uid = $this->createValidElement();

        $record = BackendUtility::getRecordWSOL('tt_content', $uid);
        $this->assertSame('Required header', $record['header']);
        $this->assertSame(1, $this->countAssetReferences($uid));
    }

    public function testCreateWithoutRequiredFieldsIsRefused(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::TYPE,
                'header' => 'Only a header',
            ],
        ]);

        $this->assertTrue($result->isError, 'A text & media element without text and media must not be saved');
        $message = $result->content[0]->text;
        $this->assertStringContainsString(self::TYPE, $message);
        $this->assertStringContainsString('bodytext', $message);
        $this->assertStringContainsString('assets', $message);
        $this->assertStringContainsString('at least 1', $message);
        $this->assertStringNotContainsString('header', $message, 'The given header is not reported');
        $this->assertSame(0, $this->countElementsOfType(), 'Nothing is written');
    }

    public function testCreateWithEmptyRichtextIsRefused(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::TYPE,
                'header' => 'Header',
                'bodytext' => '<p>&nbsp;</p>',
                'assets' => [['uid_local' => 1]],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('bodytext', $result->content[0]->text);
    }

    public function testCreateWithEmptyFileListIsRefused(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::TYPE,
                'header' => 'Header',
                'bodytext' => '<p>Text</p>',
                'assets' => [],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('assets', $result->content[0]->text);
    }

    public function testRequiredFieldsOfOtherTypesDoNotApply(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'textmedia',
                'header' => 'Plain text & media without media',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testDefaultValueFromPageTsconfigCountsAsFilled(): void
    {
        $this->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => "TCAdefaults.tt_content.header = Default header\n"],
            ['uid' => 1]
        );

        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::TYPE,
                'bodytext' => '<p>Text</p>',
                'assets' => [['uid_local' => 1]],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];
        $this->assertSame('Default header', BackendUtility::getRecordWSOL('tt_content', $uid)['header']);
    }

    public function testUpdateEmptyingRequiredTextIsRefused(): void
    {
        $uid = $this->createValidElement();

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['bodytext' => ''],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('bodytext', $result->content[0]->text);
        $this->assertSame('<p>Text</p>', BackendUtility::getRecordWSOL('tt_content', $uid)['bodytext']);
    }

    public function testUpdateRemovingAllFilesIsRefused(): void
    {
        $uid = $this->createValidElement();

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['assets' => []],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('assets', $result->content[0]->text);
        $this->assertSame(1, $this->countAssetReferences($uid));
    }

    public function testUpdateOfOtherFieldsIsNotBlockedByUntouchedRequiredFields(): void
    {
        $uid = $this->createValidElement();

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['header' => 'Changed header'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testChangingTheTypeChecksTheRequiredFieldsOfTheNewType(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'text',
                'header' => 'Text element',
                'bodytext' => 'Some text',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['CType' => self::TYPE],
        ]);
        $this->assertTrue($result->isError, 'The new type needs media the record does not have');
        $this->assertStringContainsString('assets', $result->content[0]->text);
        $this->assertStringNotContainsString('bodytext', $result->content[0]->text, 'The stored text counts');

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['CType' => self::TYPE, 'assets' => [['uid_local' => 1]]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testRequiredBaseColumnIsChecked(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'pages',
            'data' => ['pid' => 1, 'doktype' => 1, 'nav_title' => 'No title'],
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('title (', $result->content[0]->text);

        $result = $this->write([
            'action' => 'update',
            'table' => 'pages',
            'uid' => 2,
            'data' => ['title' => '  '],
        ]);
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('title (', $result->content[0]->text);
    }

    public function testTranslateIsNotBlockedByFieldsTheTranslationInherits(): void
    {
        $uid = $this->createValidElement();

        $result = $this->write([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => [
                'sys_language_uid' => 'de',
                'header' => 'Pflichtüberschrift',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $translationUid = json_decode($result->content[0]->text, true)['translationUid'];
        $this->assertSame('Pflichtüberschrift', BackendUtility::getRecordWSOL('tt_content', $translationUid)['header']);
    }

    public function testTranslateEmptyingRequiredFieldIsRefused(): void
    {
        $uid = $this->createValidElement();

        $result = $this->write([
            'action' => 'translate',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => [
                'sys_language_uid' => 'de',
                'header' => 'Pflichtüberschrift',
                'bodytext' => '',
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('bodytext', $result->content[0]->text);
    }

    public function testRecordTypeFromPageTsconfigDecidesTheRequiredFields(): void
    {
        $this->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => "TCAdefaults.tt_content.CType = " . self::TYPE . "\n"],
            ['uid' => 1]
        );

        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => ['pid' => 1, 'header' => 'No type given'],
        ]);
        $this->assertTrue($result->isError, 'The type from TCAdefaults needs text and media');
        $this->assertStringContainsString(self::TYPE, $result->content[0]->text);
        $this->assertStringContainsString('bodytext', $result->content[0]->text);
        $this->assertStringContainsString('assets', $result->content[0]->text);

        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'header' => 'No type given',
                'bodytext' => '<p>Text</p>',
                'assets' => [['uid_local' => 1]],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = json_decode($result->content[0]->text, true)['uid'];
        $this->assertSame(self::TYPE, BackendUtility::getRecordWSOL('tt_content', $uid)['CType']);
    }

    public function testSameRelationTwiceCountsAsOneItem(): void
    {
        $this->importCategories();

        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::CATEGORIES_TYPE,
                'header' => 'Two categories',
                'categories' => [1, 1],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('categories', $result->content[0]->text);
        $this->assertStringContainsString('needs at least 2 items, got 1', $result->content[0]->text);
    }

    public function testChangingTheTypeCountsTheRelationsOfTheWorkspaceVersion(): void
    {
        $this->importCategories();

        // Element 101 has no categories live. Its workspace version gets two.
        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 101,
            'data' => ['categories' => [1, 2]],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 101,
            'data' => ['CType' => self::CATEGORIES_TYPE],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(self::CATEGORIES_TYPE, BackendUtility::getRecordWSOL('tt_content', 101)['CType']);
    }

    public function testChangingTheTypeIgnoresLiveRelationsRemovedInTheWorkspace(): void
    {
        $this->importCategories();

        // Element 100 has two categories live. Its workspace version has none.
        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['categories' => []],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['CType' => self::CATEGORIES_TYPE],
        ]);
        $this->assertTrue($result->isError, 'The workspace version has no categories');
        $this->assertStringContainsString('needs at least 2 items, got 0', $result->content[0]->text);
    }

    public function testExplicitlyEmptiedFieldWithDisplayCondIsRefused(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::CONDITIONAL_TYPE,
                'header' => 'Conditional',
                'subheader' => 'Shown because the header is set',
                'imagewidth' => 100,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = (int)json_decode($result->content[0]->text, true)['uid'];

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['subheader' => ''],
        ]);

        $this->assertTrue($result->isError, 'A required field the caller empties must be refused, displayCond or not');
        $this->assertStringContainsString('subheader', $result->content[0]->text);
    }

    public function testOmittedFieldWithDisplayCondIsCheckedOnlyWhenShown(): void
    {
        $data = [
            'pid' => 1,
            'CType' => self::CONDITIONAL_TYPE,
            'imagewidth' => 100,
        ];

        // No header: subheader is hidden (FIELD:header:REQ:true) and not required
        $result = $this->write(['action' => 'create', 'table' => 'tt_content', 'data' => $data]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // With a header the form shows subheader, so it has to be filled
        $result = $this->write(['action' => 'create', 'table' => 'tt_content', 'data' => $data + ['header' => 'Conditional']]);
        $this->assertTrue($result->isError, 'A required field shown by its condition must be filled');
        $this->assertStringContainsString('subheader', $result->content[0]->text);
    }

    public function testZeroFillsARequiredNumberField(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::CONDITIONAL_TYPE,
                'header' => 'Zero width',
                'subheader' => 'Subheader',
                'imagewidth' => 0,
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testSameExistingChildTwiceCountsAsOneItem(): void
    {
        $this->assertSame(1, $this->countItems(['type' => 'file', 'minitems' => 2], [['uid' => 5], ['uid' => 5]]));
        $this->assertSame(2, $this->countItems(['type' => 'file', 'minitems' => 2], [['uid' => 5], ['uid' => 6]]));
        $this->assertSame(2, $this->countItems(['type' => 'file', 'minitems' => 2], [['uid_local' => 1], ['uid_local' => 1]]));
    }

    public function testNewEmbeddedChildWithoutRequiredFieldIsRefused(): void
    {
        [$parentUid, $slideUids] = $this->createSlider();

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $parentUid,
            'data' => [
                'tx_testrequiredfields_slides' => [
                    ['uid' => $slideUids[0]],
                    ['uid' => $slideUids[1]],
                    ['bodytext' => '<p>Quote without author</p>'],
                ],
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('author', $result->content[0]->text);
        $this->assertStringContainsString(
            "Field 'tx_testrequiredfields_slides', item at index 2 (new record)",
            $result->content[0]->text
        );
        $this->assertStringContainsString("table 'tx_testrequiredfields_slide': author", $result->content[0]->text);
        $this->assertSame(['Author 1', 'Author 2'], $this->getSlideAuthors($parentUid));
    }

    public function testEmptyingRequiredFieldOfEmbeddedChildIsRefused(): void
    {
        [$parentUid, $slideUids] = $this->createSlider();

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $parentUid,
            'data' => [
                'tx_testrequiredfields_slides' => [
                    ['uid' => $slideUids[0], 'author' => ''],
                    ['uid' => $slideUids[1]],
                ],
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString(
            "Field 'tx_testrequiredfields_slides', item at index 0 (uid {$slideUids[0]})",
            $result->content[0]->text
        );
        $this->assertStringContainsString("table 'tx_testrequiredfields_slide': author", $result->content[0]->text);
        $this->assertSame(['Author 1', 'Author 2'], $this->getSlideAuthors($parentUid));
    }

    public function testEmbeddedChildrenWithRequiredFieldsFilledAreWritten(): void
    {
        [$parentUid, $slideUids] = $this->createSlider();

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $parentUid,
            'data' => [
                'tx_testrequiredfields_slides' => [
                    ['uid' => $slideUids[0], 'author' => 'Renamed'],
                    ['uid' => $slideUids[1]],
                    ['bodytext' => '<p>Third</p>', 'author' => 'Author 3'],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertSame(['Renamed', 'Author 2', 'Author 3'], $this->getSlideAuthors($parentUid));
    }

    public function testCreateWithEmbeddedChildWithoutRequiredFieldIsRefused(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'testrequired_slides',
                'header' => 'Slider',
                'tx_testrequiredfields_slides' => [
                    ['bodytext' => '<p>Quote without author</p>'],
                ],
            ],
        ]);

        $this->assertTrue($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('author', $result->content[0]->text);
        $this->assertSame(
            0,
            (int)$this->getConnectionForTable('tt_content')->count('uid', 'tt_content', ['CType' => 'testrequired_slides'])
        );
    }

    public function testRequiredFieldOfEmbeddedChildFilledByTheRelationIsAccepted(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'testrequired_slides',
                'header' => 'Slider',
                'tx_testrequiredfields_house_slides' => [
                    ['bodytext' => '<p>Quote by the house</p>'],
                ],
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $parentUid = (int)json_decode($result->content[0]->text, true)['uid'];
        $this->assertSame(['House author'], $this->getSlideAuthors($parentUid));
    }

    /**
     * @return array{0: int, 1: list<int>} parent live uid, slide live uids
     */
    private function createSlider(): array
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => 'testrequired_slides',
                'header' => 'Slider',
                'tx_testrequiredfields_slides' => [
                    ['bodytext' => '<p>First</p>', 'author' => 'Author 1'],
                    ['bodytext' => '<p>Second</p>', 'author' => 'Author 2'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $parentUid = (int)json_decode($result->content[0]->text, true)['uid'];

        $slideUids = array_map(
            static fn(array $row): int => (int)$row['uid'],
            $this->getSlideRows($parentUid)
        );
        $this->assertCount(2, $slideUids);

        return [$parentUid, $slideUids];
    }

    /**
     * Slides of a parent as the workspace sees them, in display order.
     *
     * @return list<array<string, mixed>>
     */
    private function getSlideRows(int $parentUid): array
    {
        $rows = $this->getConnectionForTable('tx_testrequiredfields_slide')
            ->select(['*'], 'tx_testrequiredfields_slide', ['parent_uid' => $parentUid, 'deleted' => 0, 't3ver_oid' => 0], [], ['sorting' => 'ASC'])
            ->fetchAllAssociative();

        $slides = [];
        foreach ($rows as $row) {
            BackendUtility::workspaceOL('tx_testrequiredfields_slide', $row);
            if (is_array($row) && (int)($row['t3ver_state'] ?? 0) !== 2) {
                $slides[] = $row;
            }
        }
        usort($slides, static fn(array $a, array $b): int => (int)$a['sorting'] <=> (int)$b['sorting']);

        return $slides;
    }

    /**
     * @return list<string>
     */
    private function getSlideAuthors(int $parentUid): array
    {
        return array_map(static fn(array $row): string => (string)$row['author'], $this->getSlideRows($parentUid));
    }

    public function testSchemaNamesTheConditionOfConditionallyRequiredFields(): void
    {
        $schema = $this->getSchema('tt_content', self::STAGE_TYPE);

        $subheaderLine = $this->fieldLine($schema, 'subheader');
        $this->assertStringContainsString('[Required when header_layout = 2]', $subheaderLine);
        $this->assertStringNotContainsString('[Required]', $subheaderLine);

        $this->assertStringContainsString(
            '[Required when header_layout is one of 3, 4 and (header_position = center or header_position = left)]',
            $this->fieldLine($schema, 'header_link')
        );
        // A condition that cannot be put into words still tells that it is conditional
        $this->assertStringContainsString('[Required when shown]', $this->fieldLine($schema, 'bodytext'));
        // Fields without displayCond stay plainly required
        $this->assertStringContainsString('[Required when header is set]', $this->fieldLine($this->getSchema('tt_content', self::CONDITIONAL_TYPE), 'subheader'));
        $this->assertStringContainsString('[Required]', $this->fieldLine($this->getSchema('tt_content', self::CONDITIONAL_TYPE), 'imagewidth'));
    }

    public function testFieldHiddenByItsConditionIsNotRequiredOnCreate(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::STAGE_TYPE,
                'header' => 'Plain stage',
                'header_layout' => 0,
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testFieldShownByItsConditionIsRequiredOnCreate(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::STAGE_TYPE,
                'header' => 'Layout 2 stage',
                'header_layout' => 2,
            ],
        ]);

        $this->assertTrue($result->isError, 'subheader is shown for header_layout 2 and must be filled');
        $this->assertStringContainsString('subheader', $result->content[0]->text);
        $this->assertStringNotContainsString('header_link', $result->content[0]->text);
        // Not evaluable (VERSION condition): an omitted value is not checked
        $this->assertStringNotContainsString('bodytext', $result->content[0]->text);

        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::STAGE_TYPE,
                'header' => 'Layout 2 stage',
                'header_layout' => 2,
                'subheader' => 'Filled',
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testCombinedConditionDecidesWhetherTheFieldIsRequired(): void
    {
        $data = [
            'pid' => 1,
            'CType' => self::STAGE_TYPE,
            'header' => 'Layout 3 stage',
            'header_layout' => 3,
        ];

        $result = $this->write(['action' => 'create', 'table' => 'tt_content', 'data' => $data + ['header_position' => 'right']]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $result = $this->write(['action' => 'create', 'table' => 'tt_content', 'data' => $data + ['header_position' => 'center']]);
        $this->assertTrue($result->isError, 'header_link is shown for header_layout 3 with a centered header');
        $this->assertStringContainsString('header_link', $result->content[0]->text);
    }

    public function testUpdateThatShowsAFieldChecksItsStoredValue(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::STAGE_TYPE,
                'header' => 'Plain stage',
                'header_layout' => 0,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $uid = (int)json_decode($result->content[0]->text, true)['uid'];

        // Unrelated updates are not blocked by the hidden field
        $result = $this->write(['action' => 'update', 'table' => 'tt_content', 'uid' => $uid, 'data' => ['header' => 'Renamed']]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Switching to the variant that shows the empty required field is refused
        $result = $this->write(['action' => 'update', 'table' => 'tt_content', 'uid' => $uid, 'data' => ['header_layout' => 2]]);
        $this->assertTrue($result->isError, 'Showing an empty required field must be refused');
        $this->assertStringContainsString('subheader', $result->content[0]->text);

        $result = $this->write([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['header_layout' => 2, 'subheader' => 'Now filled'],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    public function testEmptyingAFieldHiddenByItsConditionIsAllowed(): void
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::STAGE_TYPE,
                'header' => 'Plain stage',
                'header_layout' => 0,
                'subheader' => '',
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    private function createValidElement(): int
    {
        $result = $this->write([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'CType' => self::TYPE,
                'header' => 'Required header',
                'bodytext' => '<p>Text</p>',
                'assets' => [['uid_local' => 1]],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return (int)json_decode($result->content[0]->text, true)['uid'];
    }

    private function countItems(array $config, mixed $value): int
    {
        $method = new \ReflectionMethod(WriteTableTool::class, 'countFieldItems');
        return $method->invoke(GeneralUtility::makeInstance(WriteTableTool::class), $config, $value);
    }

    private function write(array $params): \Mcp\Types\CallToolResult
    {
        return GeneralUtility::makeInstance(WriteTableTool::class)->execute($params);
    }

    private function getSchema(string $table, string $type): string
    {
        $result = GeneralUtility::makeInstance(GetTableSchemaTool::class)->execute([
            'table' => $table,
            'type' => $type,
            'pid' => 1,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return $result->content[0]->text;
    }

    private function fieldLine(string $schema, string $field): string
    {
        $this->assertSame(
            1,
            preg_match('/^\s*(?:-|├─|└─) ' . preg_quote($field, '/') . ' \(.*$/mu', $schema, $matches),
            "Field '$field' is listed in the schema:\n" . $schema
        );

        return $matches[0];
    }

    private function importCategories(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_category_record_mm.csv');
    }

    private function countAssetReferences(int $contentUid): int
    {
        return (int)$this->getConnectionForTable('sys_file_reference')->count('uid', 'sys_file_reference', [
            'uid_foreign' => $contentUid,
            'tablenames' => 'tt_content',
            'fieldname' => 'assets',
            'deleted' => 0,
        ]);
    }

    private function countElementsOfType(): int
    {
        return (int)$this->getConnectionForTable('tt_content')
            ->count('uid', 'tt_content', ['CType' => self::TYPE]);
    }

    private function writeSiteConfiguration(): void
    {
        $directory = Environment::getConfigPath() . '/sites/main';
        GeneralUtility::mkdir_deep($directory);
        file_put_contents($directory . '/config.yaml', implode("\n", [
            'rootPageId: 1',
            "base: 'https://example.com/'",
            'languages:',
            '  -',
            '    title: English',
            '    languageId: 0',
            "    base: '/'",
            '    locale: en_US.UTF-8',
            '  -',
            '    title: German',
            '    languageId: 1',
            "    base: '/de/'",
            '    locale: de_DE.UTF-8',
            '',
        ]));
    }
}
