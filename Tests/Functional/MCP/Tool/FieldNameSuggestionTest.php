<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\FieldNameSuggestionService;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Unknown field names must point at the field the caller meant.
 *
 * Motivated by LLM tests: several models invent `tt_content_type` instead of
 * `CType` on nearly every content element creation, self-correct after the
 * error, and cost a full extra round trip doing so.
 */
class FieldNameSuggestionTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected WriteTableTool $writeTool;
    protected FieldNameSuggestionService $suggestions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->writeTool = new WriteTableTool();
        $this->suggestions = GeneralUtility::makeInstance(FieldNameSuggestionService::class);
    }

    /**
     * The exact hallucination observed in the LLM tests.
     */
    public function testWriteTableSuggestsCTypeForHallucinatedTypeField(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => 1,
                'header' => 'Youtube Video',
                'tt_content_type' => 'textmedia',
            ],
        ]);

        $this->assertTrue($result->isError, 'Unknown field should still be rejected');
        $text = $result->content[0]->text;
        $this->assertStringContainsString("Field 'tt_content_type' does not exist", $text);
        $this->assertStringContainsString("Did you mean 'CType'?", $text);
    }

    #[DataProvider('ttContentAliasProvider')]
    public function testSuggestionForCommonTtContentAliases(string $wrongField, string $expected): void
    {
        $this->assertSame(
            $expected,
            $this->suggestions->suggest('tt_content', $wrongField),
            sprintf("'%s' should resolve to '%s'", $wrongField, $expected)
        );
    }

    public static function ttContentAliasProvider(): array
    {
        return [
            // Type field, the expensive one.
            'table-prefixed type' => ['tt_content_type', 'CType'],
            'content_type' => ['content_type', 'CType'],
            'camelCase contentType' => ['contentType', 'CType'],
            'record_type' => ['record_type', 'CType'],
            'element_type' => ['element_type', 'CType'],
            'wrong casing only' => ['ctype', 'CType'],
            'wrong casing with underscore' => ['c_type', 'CType'],
            // Label field: tt_content calls it `header`, not `title`.
            'title' => ['title', 'header'],
            'headline' => ['headline', 'header'],
            // Normalization and typos.
            'body_text' => ['body_text', 'bodytext'],
            'bodyText' => ['bodyText', 'bodytext'],
            'typo in bodytext' => ['bodytxt', 'bodytext'],
            'col_pos' => ['col_pos', 'colPos'],
            // The page a record lives on is `pid`.
            'page_id' => ['page_id', 'pid'],
            'pageUid' => ['pageUid', 'pid'],
        ];
    }

    /**
     * The mapping is derived from TCA ctrl, not hardcoded for tt_content.
     */
    public function testSuggestionUsesTcaCtrlOfTheTargetTable(): void
    {
        $this->assertSame('doktype', $this->suggestions->suggest('pages', 'page_type'));
        $this->assertSame('doktype', $this->suggestions->suggest('pages', 'pages_type'));
        // `pages` really does have a `title` column, so nothing is remapped.
        $this->assertSame('title', $this->suggestions->suggest('pages', 'Title'));
    }

    /**
     * A field name that resembles nothing must not produce a wild guess — the
     * caller gets pointed at the schema tool instead.
     */
    public function testUnrelatedFieldNameGetsSchemaPointerInsteadOfGuess(): void
    {
        $this->assertNull($this->suggestions->suggest('tt_content', 'quuxfrobnicator'));

        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => ['quuxfrobnicator' => 'foo'],
        ]);

        $this->assertTrue($result->isError);
        $text = $result->content[0]->text;
        $this->assertStringNotContainsString('Did you mean', $text);
        $this->assertStringContainsString('GetTableSchema', $text);
    }

    /**
     * Suggesting a control-only field would only buy a second failed call:
     * WriteTable rejects `sorting` and directs callers to `position`.
     */
    public function testControlOnlyFieldsAreNotSuggested(): void
    {
        $this->assertNotSame('sorting', $this->suggestions->suggest('tt_content', 'sort_order'));
    }

    /**
     * The search_replace path validates field names separately.
     */
    public function testSearchReplaceErrorAlsoSuggests(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => 100,
            'data' => [
                'body_text' => [
                    ['search' => 'old', 'replace' => 'new'],
                ],
            ],
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString("Did you mean 'bodytext'?", $result->content[0]->text);
    }

    /**
     * GetTableSchema must name the type field explicitly. "type: CType" inside
     * CONTROL FIELDS was too cryptic to be picked up.
     */
    public function testGetTableSchemaNamesTypeFieldAndListsTypes(): void
    {
        $tool = new GetTableSchemaTool();
        $result = $tool->execute(['table' => 'tt_content']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $text = $result->content[0]->text;

        $this->assertStringContainsString('Type field: CType', $text);
        // tt_content lists CType itself, so the type values are referenced
        // rather than repeated.
        $this->assertStringContainsString('Available types: listed as the options of CType', $text);
        $this->assertStringContainsString('textmedia', $text);
    }

    /**
     * pages uses doktype, and its type values are numeric.
     */
    public function testGetTableSchemaNamesTypeFieldForPages(): void
    {
        $tool = new GetTableSchemaTool();
        $result = $tool->execute(['table' => 'pages']);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Type field: doktype', $result->content[0]->text);
    }
}
