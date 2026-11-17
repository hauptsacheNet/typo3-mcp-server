<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RequiredFieldsTest extends AbstractFunctionalTest
{
    private const TYPE = 'testrequired_textmedia';

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
