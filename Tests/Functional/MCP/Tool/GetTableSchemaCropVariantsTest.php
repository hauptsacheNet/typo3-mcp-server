<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\GetTableSchemaTool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * File fields advertise the crop variants and aspect ratios of their references,
 * so an LLM can build a valid sys_file_reference.crop value.
 */
class GetTableSchemaCropVariantsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        __DIR__ . '/../../Fixtures/Extensions/test_crop_variants',
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
    }

    public function testFileFieldListsCropVariantsWithAspectRatios(): void
    {
        $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'type' => 'textmedia']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $assetsLine = $this->findFieldLine($result->content[0]->text, 'assets');

        $this->assertStringContainsString('desktop (ratios: "16:9"=1.7778, "1_91:1"=1.91)', $assetsLine);
        $this->assertStringContainsString('mobile (ratios: "2:3"=0.6667, "free" (free))', $assetsLine);
        $this->assertStringContainsString('"selectedRatio"', $assetsLine);
    }

    public function testDisabledVariantsAndRatiosAreNotListed(): void
    {
        $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'type' => 'textmedia']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $assetsLine = $this->findFieldLine($result->content[0]->text, 'assets');

        $this->assertStringNotContainsString('retired', $assetsLine);
        $this->assertStringNotContainsString('legacy', $assetsLine);
    }

    public function testVariantsOfTheChildTableAreMergedWithTheFieldOverride(): void
    {
        $original = $GLOBALS['TCA']['sys_file_reference']['columns']['crop']['config']['cropVariants'] ?? null;
        $GLOBALS['TCA']['sys_file_reference']['columns']['crop']['config']['cropVariants'] = [
            'social' => ['title' => 'Social', 'allowedAspectRatios' => ['1:1' => ['title' => '1:1', 'value' => 1.0]]],
        ];

        try {
            $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'type' => 'textmedia']);
            $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
            $assetsLine = $this->findFieldLine($result->content[0]->text, 'assets');
        } finally {
            if ($original === null) {
                unset($GLOBALS['TCA']['sys_file_reference']['columns']['crop']['config']['cropVariants']);
            } else {
                $GLOBALS['TCA']['sys_file_reference']['columns']['crop']['config']['cropVariants'] = $original;
            }
        }

        $this->assertStringContainsString('social (ratios: "1:1"=1)', $assetsLine);
        $this->assertStringContainsString('desktop (ratios:', $assetsLine);
    }

    public function testFileFieldWithoutOverrideDoesNotMentionCropVariants(): void
    {
        $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'type' => 'image']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $imageLine = $this->findFieldLine($result->content[0]->text, 'image');

        $this->assertStringNotContainsString('crop variants', $imageLine);
    }

    public function testCropVariantsAreHiddenWithoutAccessToTheCropField(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 99,
            'pid' => 0,
            'username' => 'editor_without_crop',
            'password' => '$argon2i$v=19$m=65536,t=16,p=1$dGVzdHNhbHQ$testpasswordhash',
            'admin' => 0,
        ]);
        $editor = $this->setUpBackendUser(99);
        $editor->groupData['tables_select'] = 'tt_content,sys_file_reference';
        $editor->groupData['tables_modify'] = 'tt_content,sys_file_reference';
        $editor->groupData['non_exclude_fields'] = 'tt_content:assets,sys_file_reference:alternative';

        $result = (new GetTableSchemaTool())->execute(['table' => 'tt_content', 'type' => 'textmedia']);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $assetsLine = $this->findFieldLine($result->content[0]->text, 'assets');

        $this->assertStringNotContainsString('crop variants', $assetsLine);
    }

    private function findFieldLine(string $schema, string $field): string
    {
        foreach (explode("\n", $schema) as $line) {
            if (str_starts_with(trim($line), '- ' . $field . ' (')) {
                return $line;
            }
        }
        $this->fail('Field ' . $field . ' not found in schema: ' . $schema);
    }
}
