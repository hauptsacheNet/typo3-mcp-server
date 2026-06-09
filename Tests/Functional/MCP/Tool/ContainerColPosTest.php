<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use B13\Container\Tca\ContainerConfiguration;
use B13\Container\Tca\Registry;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Tests writing content into b13/container containers via the MCP WriteTableTool.
 *
 * A child element is linked to its parent container through tx_container_parent
 * and sits in one of the parent's grid columns. The colPos numbers of those
 * columns are arbitrary integers an integrator picks when registering a
 * container (201, 305, 9001, ...); container resolves them dynamically via an
 * itemsProcFunc and they are not a fixed list. tx_container_parent is likewise a
 * dynamic select with no static items.
 *
 * WriteTableTool therefore must NOT validate colPos / tx_container_parent of a
 * container child against a hardcoded set — it must accept whatever the project
 * uses once the record is linked to a container. The strict validation stays in
 * place for ordinary (non-container) records.
 */
class ContainerColPosTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/mcp_server',
        'container',
    ];

    protected const CONTAINER_CTYPE = 'mcptest_container';

    protected WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');

        // Register a container. The grid columns here are irrelevant to the test:
        // the point is precisely that a child's colPos is NOT validated against
        // this grid. Registering one just makes tx_container_parent meaningful.
        $registry = GeneralUtility::getContainer()->get(Registry::class);
        $registry->configureContainer(new ContainerConfiguration(
            self::CONTAINER_CTYPE,
            'MCP Test Container',
            'Container for MCP tests',
            [[['name' => 'Main', 'colPos' => 200]]]
        ));

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
    }

    /**
     * Create the container element via the MCP tool and return its (live) uid.
     */
    protected function createContainerElement(): int
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => self::CONTAINER_CTYPE,
                'header' => 'Test Container',
                'colPos' => 0,
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $data = json_decode($result->content[0]->text, true);
        $this->assertIsInt($data['uid'] ?? null, 'Container create should return a uid: ' . json_encode($data));

        return (int)$data['uid'];
    }

    protected function colPosOf(int $uid): ?int
    {
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content')
            ->select(['colPos'], 'tt_content', ['uid' => $uid])
            ->fetchAssociative();

        return $row === false ? null : (int)$row['colPos'];
    }

    /**
     * Any colPos a project might use is accepted for a child linked to a
     * container — including values that are not in this container's registered
     * grid. The validator must not enforce a fixed set. The numbers below are
     * deliberately arbitrary and span magnitudes to make that point.
     */
    public function testWriteChildAcceptsArbitraryColPos(): void
    {
        $containerUid = $this->createContainerElement();

        foreach ([7, 142, 9001, 123456] as $colPos) {
            $result = $this->writeTool->execute([
                'action' => 'create',
                'table' => 'tt_content',
                'pid' => 1,
                'data' => [
                    'CType' => 'text',
                    'header' => 'Child at colPos ' . $colPos,
                    'colPos' => $colPos,
                    'tx_container_parent' => $containerUid,
                ],
            ]);

            $this->assertFalse(
                $result->isError,
                'A container child with colPos ' . $colPos . ' should be accepted: '
                    . json_encode($result->jsonSerialize())
            );

            $childUid = (int)(json_decode($result->content[0]->text, true)['uid'] ?? 0);
            $this->assertSame($colPos, $this->colPosOf($childUid), 'The submitted colPos should be stored as-is');
        }
    }

    /**
     * Attaching an existing top-level element to a container (update that sets
     * tx_container_parent) accepts the container colPos too.
     */
    public function testAttachingExistingRecordToContainerAcceptsColPos(): void
    {
        $containerUid = $this->createContainerElement();

        $create = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Top level', 'colPos' => 0],
        ]);
        $this->assertFalse($create->isError, json_encode($create->jsonSerialize()));
        $uid = (int)json_decode($create->content[0]->text, true)['uid'];

        $update = $this->writeTool->execute([
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['tx_container_parent' => $containerUid, 'colPos' => 777],
        ]);

        $this->assertFalse($update->isError, 'Attaching to a container should accept its colPos: '
            . json_encode($update->jsonSerialize()));
        $this->assertSame(777, $this->colPosOf($uid));
    }

    /**
     * The bypass is scoped to container children: an ordinary tt_content record
     * (no container link) with a colPos that is not a real page column is still
     * rejected by the standard validator.
     */
    public function testOrdinaryRecordStillRejectsUnknownColPos(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Not a container child',
                'colPos' => 123456,
            ],
        ]);

        $this->assertTrue($result->isError, 'A non-container record with an unknown colPos should be rejected');
        $errorMessage = $result->jsonSerialize()['content'][0]->text ?? '';
        $this->assertStringContainsString('colPos', $errorMessage);
    }

    /**
     * The relaxation is tight: pointing tx_container_parent at an ordinary content
     * element (not a container) must NOT enable the bypass, so an unknown colPos is
     * still rejected and a misplaced child cannot be stored.
     */
    public function testNonContainerParentDoesNotBypassValidation(): void
    {
        // An ordinary text element, not a container.
        $create = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => ['CType' => 'text', 'header' => 'Just text', 'colPos' => 0],
        ]);
        $this->assertFalse($create->isError, json_encode($create->jsonSerialize()));
        $plainUid = (int)json_decode($create->content[0]->text, true)['uid'];

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Pretend child',
                'colPos' => 123456,
                'tx_container_parent' => $plainUid,
            ],
        ]);

        $this->assertTrue($result->isError, 'A non-container parent must not relax colPos validation');
    }

    /**
     * A non-existent parent uid must not enable the bypass either.
     */
    public function testNonexistentParentDoesNotBypassValidation(): void
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Orphan',
                'colPos' => 123456,
                'tx_container_parent' => 999999,
            ],
        ]);

        $this->assertTrue($result->isError, 'A non-existent container parent must not relax colPos validation');
    }
}
