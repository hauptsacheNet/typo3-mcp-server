<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Resource\WriteTableDiffUiResource;
use Hn\McpServer\MCP\Tool\Record\GetRecordDiffTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class GetRecordDiffToolTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;
    private GetRecordDiffTool $diffTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->diffTool = GeneralUtility::makeInstance(GetRecordDiffTool::class);
    }

    public function testWriteTableSchemaAdvertisesUiResource(): void
    {
        $schema = $this->writeTool->getSchema();
        $this->assertArrayHasKey('_meta', $schema, 'write_table schema must advertise an MCP App UI resource');
        $this->assertSame(
            WriteTableDiffUiResource::URI,
            $schema['_meta']['ui']['resourceUri'] ?? null
        );
    }

    public function testWriteIncludesMonotonicWriteId(): void
    {
        $pid = $this->getRootPageUid();

        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $pid,
                'CType' => 'textmedia',
                'header' => 'WriteId test',
                'bodytext' => 'first body',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $created = $this->extractJsonFromResult($createResult);
        $this->assertArrayHasKey('writeId', $created);
        $firstWriteId = (int)$created['writeId'];
        $this->assertGreaterThan(0, $firstWriteId);

        $updateResult = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $created['uid'],
            'data' => ['header' => 'WriteId test (revised)'],
        ]);
        $this->assertFalse($updateResult->isError, json_encode($updateResult->jsonSerialize()));
        $updated = $this->extractJsonFromResult($updateResult);
        $this->assertArrayHasKey('writeId', $updated);
        $this->assertGreaterThan($firstWriteId, (int)$updated['writeId']);
    }

    public function testDiffForUpdatedTextField(): void
    {
        $pid = $this->getRootPageUid();

        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $pid,
                'CType' => 'textmedia',
                'header' => 'Original header',
                'bodytext' => 'Original body text',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $uid = (int)$this->extractJsonFromResult($createResult)['uid'];

        $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => [
                'header' => 'Revised header',
                'bodytext' => 'Revised body text',
            ],
        ]);

        $diffResult = $this->diffTool->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($diffResult->isError, json_encode($diffResult->jsonSerialize()));
        $diff = $this->extractJsonFromResult($diffResult);

        $this->assertSame('tt_content', $diff['table']);
        $this->assertSame($uid, $diff['uid']);
        $this->assertGreaterThan(0, (int)$diff['currentWriteId']);
        $this->assertArrayHasKey('fields', $diff);

        $byName = [];
        foreach ($diff['fields'] as $field) {
            $byName[$field['name']] = $field;
        }

        $this->assertArrayHasKey('header', $byName, 'Expected header field in diff: ' . json_encode($diff['fields']));
        $this->assertTrue($byName['header']['isText']);
        $this->assertIsString($byName['header']['diffHtml']);
        $this->assertNotSame('', trim($byName['header']['diffHtml']));
        // DiffUtility marks changes with <ins>/<del> spans.
        $this->assertMatchesRegularExpression(
            '/<(ins|del)\b/i',
            $byName['header']['diffHtml'],
            'DiffUtility output should contain <ins> or <del> markers'
        );

        $this->assertArrayHasKey('bodytext', $byName);
        $this->assertTrue($byName['bodytext']['isText']);
        $this->assertIsString($byName['bodytext']['diffHtml']);
    }

    public function testDiffForNewWorkspaceRecord(): void
    {
        $pid = $this->getRootPageUid();

        $createResult = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $pid,
                'CType' => 'textmedia',
                'header' => 'Brand new in workspace',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($createResult->isError, json_encode($createResult->jsonSerialize()));
        $uid = (int)$this->extractJsonFromResult($createResult)['uid'];

        $diffResult = $this->diffTool->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($diffResult->isError, json_encode($diffResult->jsonSerialize()));
        $diff = $this->extractJsonFromResult($diffResult);

        $this->assertTrue((bool)$diff['hasWorkspaceChange']);
        $this->assertTrue((bool)$diff['isNewRecord']);
        $this->assertFalse((bool)$diff['isDeleted']);
        $this->assertNotEmpty($diff['fields']);
    }

    public function testDiffReportsNoWorkspaceChangeForLiveRecord(): void
    {
        // Insert a record directly into live (no workspace overlay)
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'pid' => $this->getRootPageUid(),
            'CType' => 'textmedia',
            'header' => 'Live only',
            'colPos' => 0,
        ]);
        $uid = (int)$connection->lastInsertId();

        $diffResult = $this->diffTool->execute(['table' => 'tt_content', 'uid' => $uid]);
        $this->assertFalse($diffResult->isError, json_encode($diffResult->jsonSerialize()));
        $diff = $this->extractJsonFromResult($diffResult);

        $this->assertFalse((bool)$diff['hasWorkspaceChange']);
        $this->assertSame([], $diff['fields']);
    }
}
