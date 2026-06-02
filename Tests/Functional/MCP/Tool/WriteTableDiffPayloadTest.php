<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Resource\WriteTableDiffUiResource;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Hn\McpServer\Tests\Functional\Traits\McpAssertionsTrait;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Every successful write_table call must return a `structuredContent` payload
 * with the per-field diff, preview URL and writeId so the MCP App widget can
 * render the change without an extra round-trip.
 */
class WriteTableDiffPayloadTest extends AbstractFunctionalTest
{
    use McpAssertionsTrait;

    private WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
    }

    public function testWriteTableSchemaAdvertisesUiResource(): void
    {
        $schema = $this->writeTool->getSchema();
        $this->assertSame(
            WriteTableDiffUiResource::URI,
            $schema['_meta']['ui']['resourceUri'] ?? null,
            'write_table schema must declare its MCP App UI resource'
        );
        $this->assertContains(
            'model',
            $schema['_meta']['ui']['visibility'] ?? [],
            'write_table must remain visible to the LLM'
        );
    }

    public function testCreateEmbedsDiffAsStructuredContent(): void
    {
        $pid = $this->getRootPageUid();

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $pid,
                'CType' => 'textmedia',
                'header' => 'Brand new headline',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $diff = $result->structuredContent;
        $this->assertIsArray($diff, 'structuredContent must be populated for hosts that support MCP Apps');
        $this->assertSame('create', $diff['action']);
        $this->assertSame('tt_content', $diff['table']);
        $this->assertGreaterThan(0, (int)$diff['uid']);
        $this->assertGreaterThan(0, (int)$diff['writeId']);
        $this->assertSame($diff['writeId'], $diff['currentWriteId']);
        $this->assertTrue((bool)$diff['isNewRecord']);
        $this->assertTrue((bool)$diff['hasWorkspaceChange']);
        $this->assertFalse((bool)$diff['isDeleted']);

        // Headline must appear among the diff fields.
        $byName = [];
        foreach ($diff['fields'] as $f) {
            $byName[$f['name']] = $f;
        }
        $this->assertArrayHasKey('header', $byName);
        $this->assertSame('Brand new headline', $byName['header']['after']);
    }

    public function testUpdateProducesInsDelDiffOnTextFields(): void
    {
        $pid = $this->getRootPageUid();

        $create = $this->writeTool->execute([
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
        $this->assertFalse($create->isError, json_encode($create->jsonSerialize()));
        $uid = (int)$create->structuredContent['uid'];

        $update = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => [
                'header' => 'Revised header',
                'bodytext' => 'Revised body text',
            ],
        ]);
        $this->assertFalse($update->isError, json_encode($update->jsonSerialize()));
        $diff = $update->structuredContent;
        $this->assertIsArray($diff);
        $this->assertSame('update', $diff['action']);

        $byName = [];
        foreach ($diff['fields'] as $f) {
            $byName[$f['name']] = $f;
        }
        $this->assertArrayHasKey('header', $byName);
        $this->assertTrue($byName['header']['isText']);
        $this->assertMatchesRegularExpression(
            '/<(ins|del)\b/i',
            (string)$byName['header']['diffHtml'],
            'DiffUtility output should contain <ins> or <del> markers'
        );
    }

    public function testWriteIdIncreasesMonotonically(): void
    {
        $pid = $this->getRootPageUid();
        $first = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $pid,
                'CType' => 'textmedia',
                'header' => 'WriteId test',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($first->isError, json_encode($first->jsonSerialize()));
        $uid = (int)$first->structuredContent['uid'];
        $firstId = (int)$first->structuredContent['writeId'];

        $second = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['header' => 'WriteId test (revised)'],
        ]);
        $this->assertFalse($second->isError, json_encode($second->jsonSerialize()));
        $this->assertGreaterThan($firstId, (int)$second->structuredContent['writeId']);
    }

    public function testDeleteCarriesDeletePlaceholderFlag(): void
    {
        // Insert a live record first so we have something to mark for deletion.
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', [
            'pid' => $this->getRootPageUid(),
            'CType' => 'textmedia',
            'header' => 'About to be removed',
            'colPos' => 0,
        ]);
        $uid = (int)$connection->lastInsertId();

        $result = $this->writeTool->execute([
            'action' => 'delete',
            'table' => 'tt_content',
            'uid' => $uid,
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $diff = $result->structuredContent;
        $this->assertIsArray($diff);
        $this->assertSame('delete', $diff['action']);
        $this->assertTrue((bool)$diff['isDeleted']);
    }

    public function testTextPayloadStaysCompact(): void
    {
        // The text payload (what the LLM reads) must stay compact — diff data
        // belongs in structuredContent only.
        $pid = $this->getRootPageUid();
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'data' => [
                'pid' => $pid,
                'CType' => 'textmedia',
                'header' => 'Compact text payload',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $textPayload = $this->extractJsonFromResult($result);
        $this->assertArrayNotHasKey('fields', $textPayload, 'Field diff must stay out of the text payload');
        $this->assertArrayNotHasKey('previewUrl', $textPayload, 'previewUrl must stay out of the text payload');
        $this->assertSame(
            ['action', 'table', 'uid', 'writeId'],
            array_keys($textPayload),
            'Text payload schema drift — only action/table/uid/writeId belong here'
        );

        // Sanity: assert the test fixture really did insert a row, even if we
        // don't surface much in the text payload itself.
        $uid = (int)$textPayload['uid'];
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $this->assertGreaterThan(
            0,
            (int)$qb->count('uid')->from('tt_content')->where(
                $qb->expr()->eq('t3ver_oid', $qb->createNamedParameter($uid))
            )->orWhere(
                $qb->expr()->eq('uid', $qb->createNamedParameter($uid))
            )->executeQuery()->fetchOne()
        );
    }
}
