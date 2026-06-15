<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use B13\Container\Tca\ContainerConfiguration;
use B13\Container\Tca\Registry;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\SelectItemResolver;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Validates that SelectItemResolver actually executes a field's itemsProcFunc
 * with the *record context* it needs.
 *
 * b13/container is the realistic example: tt_content.colPos uses an itemsProcFunc
 * (\B13\Container\Tca\ItemProcFunc::colPos) that reads $parameters['row'] —
 * specifically tx_container_parent (which container the child belongs to) and the
 * current colPos (it only returns the grid column matching that value). Without the
 * row, the function falls through to the plain backend-layout colPos list and the
 * container columns never appear.
 *
 * If SelectItemResolver feeds the record into the FormDataCompiler correctly, the
 * registered grid colPos of a container child resolves and validates; an
 * unregistered colPos does not. This is exactly what PR #96 worked around by
 * bypassing validation entirely.
 */
class ContainerSelectItemResolverTest extends FunctionalTestCase
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
    protected const GRID_COLPOS = 200;

    protected SelectItemResolver $selectItemResolver;
    protected TableAccessService $tableAccessService;
    protected WriteTableTool $writeTool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');

        // Register a container with a single grid column at colPos 200.
        $registry = GeneralUtility::getContainer()->get(Registry::class);
        $registry->configureContainer(new ContainerConfiguration(
            self::CONTAINER_CTYPE,
            'MCP Test Container',
            'Container for MCP tests',
            [[['name' => 'Main', 'colPos' => self::GRID_COLPOS]]]
        ));

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->selectItemResolver = GeneralUtility::makeInstance(SelectItemResolver::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
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

    /**
     * Sanity check: itemsProcFunc IS executed at all. Without any container link,
     * colPos resolves to the standard backend-layout columns (which always include 0).
     */
    public function testColPosResolvesWithoutContainerContext(): void
    {
        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos', ['pid' => 1]);
        $this->assertNotNull($resolved);
        $this->assertContains('0', $resolved['values'], 'Default colPos list should contain 0: ' . json_encode($resolved['values']));
    }

    /**
     * The core assertion: when the record context carries tx_container_parent and the
     * registered grid colPos, the container itemsProcFunc must expose that colPos.
     *
     * This fails with the current implementation because compileFormData() never
     * passes the record into the FormDataCompiler (it compiles a blank "new" record),
     * so the itemsProcFunc sees an empty row and the container column never appears.
     */
    public function testContainerGridColPosIsResolvedForChild(): void
    {
        $containerUid = $this->createContainerElement();

        $record = [
            'pid' => 1,
            'CType' => 'text',
            'tx_container_parent' => $containerUid,
            'colPos' => self::GRID_COLPOS,
        ];

        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos', $record);
        $this->assertNotNull($resolved, 'colPos should resolve for a container child');

        $this->assertContains(
            (string)self::GRID_COLPOS,
            $resolved['values'],
            'The container grid colPos ' . self::GRID_COLPOS . ' must be resolved via the itemsProcFunc when the '
            . 'record carries tx_container_parent. Got: ' . json_encode($resolved['values'])
        );
    }

    /**
     * Consequence for validation: a container child placed in a registered grid
     * column must pass validateFieldValue, and one placed in an unregistered column
     * must fail. The container itemsProcFunc only echoes back the colPos that matches
     * a registered grid column, so this distinction is exactly what we want.
     */
    public function testValidationAcceptsRegisteredColPosAndRejectsUnregistered(): void
    {
        $containerUid = $this->createContainerElement();

        $validRecord = [
            'pid' => 1,
            'CType' => 'text',
            'tx_container_parent' => $containerUid,
            'colPos' => self::GRID_COLPOS,
        ];
        $error = $this->tableAccessService->validateFieldValue('tt_content', 'colPos', self::GRID_COLPOS, $validRecord);
        $this->assertNull($error, 'A container child in the registered grid colPos should validate. Error: ' . (string)$error);

        $invalidRecord = [
            'pid' => 1,
            'CType' => 'text',
            'tx_container_parent' => $containerUid,
            'colPos' => 9001,
        ];
        $error = $this->tableAccessService->validateFieldValue('tt_content', 'colPos', 9001, $invalidRecord);
        $this->assertNotNull($error, 'A container child in an unregistered colPos (9001) should fail validation');
    }

    /**
     * The runtime cache must not collide across different record contexts on the same
     * pid. Resolving colPos for a container child and then for a plain record on the
     * same page must yield different item sets — the container column must not leak
     * into the plain record and vice versa.
     */
    public function testCacheDoesNotCollideAcrossRecordContextsOnSamePid(): void
    {
        $containerUid = $this->createContainerElement();

        $childResolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos', [
            'pid' => 1,
            'CType' => 'text',
            'tx_container_parent' => $containerUid,
            'colPos' => self::GRID_COLPOS,
        ]);
        $plainResolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos', [
            'pid' => 1,
            'CType' => 'text',
        ]);

        $this->assertNotNull($childResolved);
        $this->assertNotNull($plainResolved);

        $this->assertContains((string)self::GRID_COLPOS, $childResolved['values'], 'Child context should expose the grid colPos');
        $this->assertNotContains(
            (string)self::GRID_COLPOS,
            $plainResolved['values'],
            'Plain record on the same pid must NOT inherit the container grid colPos from a cached result'
        );
    }
}
