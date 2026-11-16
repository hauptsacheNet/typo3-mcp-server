<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetFlexFormSchemaTool;
use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * TCA descriptions (record type items, field descriptions incl. the record
 * type's columnsOverrides, select item descriptions) are part of the schema
 * output, resolved from LLL, and silently dropped when empty or unresolvable.
 */
class GetTableSchemaDescriptionTest extends FunctionalTestCase
{
    private const LLL = 'LLL:EXT:test_descriptions/Resources/Private/Language/locallang_tca.xlf:';

    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_descriptions',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');
    }

    private function getSchema(array $params): string
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $result = $tool->execute($params);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        return $result->content[0]->text;
    }

    /**
     * Returns the line following the output line of the given field.
     */
    private function getLineAfterField(string $output, string $fieldName): string
    {
        $lines = explode("\n", $output);
        foreach ($lines as $index => $line) {
            if (preg_match('/(- |├─ |└─ )' . preg_quote($fieldName, '/') . ' \(/', $line)) {
                return $lines[$index + 1] ?? '';
            }
        }
        $this->fail('Field ' . $fieldName . ' not found in schema output:' . "\n" . $output);
    }

    public function testOverviewListsTypesWithDescriptions(): void
    {
        $output = $this->getSchema(['table' => 'tt_content']);

        $this->assertStringContainsString("AVAILABLE TYPES:\n", $output);
        $this->assertStringContainsString(
            "- test_described (Described element): Use this element for structured data that is not rendered.\n",
            $output
        );
        // Types without (usable) description are listed with their label only
        $this->assertStringContainsString("- test_plain (Plain element)\n", $output);
        $this->assertStringContainsString("- test_broken (Broken element)\n", $output);
        $this->assertStringNotContainsString('does.not.exist', $output);
    }

    public function testTypeDescriptionInCurrentRecordType(): void
    {
        $output = $this->getSchema(['table' => 'tt_content', 'type' => 'test_described']);

        $this->assertStringContainsString(
            "Type: test_described (Described element)\nDescription: Use this element for structured data that is not rendered.\n\n",
            $output
        );
        // No overview section when a type was requested
        $this->assertStringNotContainsString('AVAILABLE TYPES:', $output);
        // The CType option list stays compact: the type description appears only once
        $this->assertSame(1, substr_count($output, 'Use this element for structured data'));
        $this->assertStringNotContainsString('Description:', $this->getLineAfterField($output, 'CType'));
    }

    public function testTypeWithoutDescriptionHasNoDescriptionLine(): void
    {
        $output = $this->getSchema(['table' => 'tt_content', 'type' => 'test_plain']);

        $this->assertStringContainsString("Type: test_plain (Plain element)\n\n", $output);
        // columnsOverrides of test_described must not leak into other types
        $this->assertStringNotContainsString('JSON-LD', $output);
    }

    public function testUnresolvableTypeDescriptionIsOmitted(): void
    {
        $output = $this->getSchema(['table' => 'tt_content', 'type' => 'test_broken']);

        $this->assertStringContainsString("Type: test_broken (Broken element)\n\n", $output);
        $this->assertStringNotContainsString('LLL:', $output);
    }

    public function testColumnsOverridesDescriptionIsShownBelowField(): void
    {
        $output = $this->getSchema(['table' => 'tt_content', 'type' => 'test_described']);

        $this->assertSame(
            '        Description: Plain text only, it ends up in the JSON-LD output.',
            $this->getLineAfterField($output, 'bodytext')
        );
    }

    public function testFieldDescriptionInPaletteIsResolvedAndSingleLine(): void
    {
        $output = $this->getSchema(['table' => 'tt_content', 'type' => 'test_described']);

        // Not the last palette field: continuation line keeps the palette border
        $this->assertSame(
            '    │      Description: Internal note, spread over two lines.',
            $this->getLineAfterField($output, 'tx_testdescriptions_note')
        );
    }

    public function testSelectItemDescriptionsAreListedBelowField(): void
    {
        $output = $this->getSchema(['table' => 'tt_content', 'type' => 'test_described']);

        // Inline option list is unchanged
        $this->assertStringContainsString(
            '[Options: a (Mode A), b (Mode B), c (Mode C), d (Mode D), e (Mode E)]',
            $output
        );
        $this->assertStringContainsString(
            "           Option descriptions:\n"
            . "             - a (Mode A): Mode A shows everything.\n"
            . "             - b (Mode B): Mode B shows nothing.\n",
            $output
        );
        // Items without, with empty or with unresolvable description are not listed
        $this->assertStringNotContainsString('- c (Mode C):', $output);
        $this->assertStringNotContainsString('- d (Mode D):', $output);
        $this->assertStringNotContainsString('- e (Mode E):', $output);
    }

    public function testUserFieldDescriptionIsShown(): void
    {
        $output = $this->getSchema(['table' => 'tt_content', 'type' => 'test_described']);

        $this->assertSame(
            '        Description: Read-only hint rendered by a custom element.',
            $this->getLineAfterField($output, 'tx_testdescriptions_hint')
        );
    }

    public function testEmptyAndUnresolvableFieldDescriptionsAreOmitted(): void
    {
        $output = $this->getSchema(['table' => 'tt_content', 'type' => 'test_described']);

        $this->assertStringNotContainsString('Description:', $this->getLineAfterField($output, 'tx_testdescriptions_empty'));
        $this->assertStringNotContainsString('Description:', $this->getLineAfterField($output, 'tx_testdescriptions_broken'));
        $this->assertStringNotContainsString('LLL:', $output);
        $this->assertStringNotContainsString("Description: \n", $output);
    }

    public function testTranslateDescription(): void
    {
        $this->assertSame('Mode A shows everything.', TableAccessService::translateDescription(self::LLL . 'mode.a.description'));
        $this->assertSame('Internal note, spread over two lines.', TableAccessService::translateDescription(self::LLL . 'note.description'));
        $this->assertSame('Plain text', TableAccessService::translateDescription("  Plain\n text "));
        $this->assertSame('', TableAccessService::translateDescription(self::LLL . 'does.not.exist'));
        $this->assertSame('', TableAccessService::translateDescription('LLL:EXT:not_installed/locallang.xlf:key'));
        $this->assertSame('', TableAccessService::translateDescription(''));
        $this->assertSame('', TableAccessService::translateDescription('   '));
        $this->assertSame('', TableAccessService::translateDescription(null));
        $this->assertSame('', TableAccessService::translateDescription(['not', 'a', 'string']));
    }

    public function testAvailableTypeItemsCarryDescriptions(): void
    {
        $types = GeneralUtility::makeInstance(TableAccessService::class)->getAvailableTypeItems('tt_content');

        // FormEngine may already translate item descriptions; either way they resolve to the text
        $this->assertSame(
            'Use this element for structured data that is not rendered.',
            TableAccessService::translateDescription($types['test_described']['description'])
        );
        $this->assertSame('', $types['test_plain']['description']);
        // getAvailableTypes() keeps its value => label shape
        $labels = GeneralUtility::makeInstance(TableAccessService::class)->getAvailableTypes('tt_content');
        $this->assertSame('Described element', TableAccessService::translateLabel($labels['test_described']));
        $this->assertSame($types['test_described']['label'], $labels['test_described']);
    }

    public function testFlexFormItemAndFieldDescriptions(): void
    {
        $GLOBALS['TCA']['tt_content']['types']['test_described']['columnsOverrides']['pi_flexform']['config']['ds'] = '<T3DataStructure>
    <sheets>
        <sDEF>
            <ROOT>
                <sheetTitle>General</sheetTitle>
                <type>array</type>
                <el>
                    <settings.mode>
                        <label>Mode</label>
                        <description>' . self::LLL . 'mode.a.description</description>
                        <config>
                            <type>select</type>
                            <renderType>selectSingle</renderType>
                            <items>
                                <numIndex index="0">
                                    <label>Mode A</label>
                                    <value>a</value>
                                    <description>' . self::LLL . 'mode.a.description</description>
                                </numIndex>
                                <numIndex index="1">
                                    <label>Mode C</label>
                                    <value>c</value>
                                </numIndex>
                            </items>
                        </config>
                    </settings.mode>
                    <settings.broken>
                        <label>Broken</label>
                        <description>' . self::LLL . 'does.not.exist</description>
                        <config>
                            <type>input</type>
                        </config>
                    </settings.broken>
                </el>
            </ROOT>
        </sDEF>
    </sheets>
</T3DataStructure>';

        $tool = GeneralUtility::makeInstance(GetFlexFormSchemaTool::class);
        $result = $tool->execute(['identifier' => 'test_described']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $output = $result->content[0]->text;

        $this->assertMatchesRegularExpression('/- settings\.mode \(Mode\): select .* - Mode A shows everything\.\n/', $output);
        $this->assertStringContainsString("Option descriptions:\n", $output);
        $this->assertMatchesRegularExpression('/\n\s+- a \(Mode A\): Mode A shows everything\.\n/', $output);
        $this->assertStringNotContainsString('- c (Mode C):', $output);
        // Unresolvable field description is dropped instead of leaking the LLL key
        $this->assertMatchesRegularExpression('/- settings\.broken \(Broken\): input\n/', $output);
        $this->assertStringNotContainsString('does.not.exist', $output);
        $this->assertStringNotContainsString('LLL:', $output);
    }
}
