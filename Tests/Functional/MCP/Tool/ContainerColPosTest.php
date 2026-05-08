<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Service\SelectItemResolver;
use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies that select-field validation (in particular tt_content.colPos)
 * works correctly when the b13/container extension is in play.
 *
 * b13/container wires an itemsProcFunc on tt_content.colPos that REPLACES
 * the resolved items based on the row context: when tx_container_parent
 * points at an existing container, only that container's grid colPos is
 * exposed; otherwise the page's backend-layout colPos list wins.
 *
 * That makes itemsProcFunc strictly row-dependent — and exposes a hole in
 * SelectItemResolver if it does not pass the candidate record to
 * FormDataCompiler.
 */
class ContainerColPosTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'typo3conf/ext/mcp_server',
        'b13/container',
        __DIR__ . '/../../Fixtures/Extensions/test_container',
    ];

    protected WriteTableTool $writeTool;
    protected TableAccessService $tableAccessService;
    protected SelectItemResolver $selectItemResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');

        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('en');

        $this->writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $this->tableAccessService = GeneralUtility::makeInstance(TableAccessService::class);
        $this->selectItemResolver = GeneralUtility::makeInstance(SelectItemResolver::class);
    }

    /**
     * Sanity check: the test container extension actually ran and registered
     * the 50/50 grid columns (colPos 200/201) into TCA.
     */
    public function testContainerCTypeIsRegistered(): void
    {
        $this->assertNotEmpty(
            $GLOBALS['TCA']['tt_content']['containerConfiguration']['test_two_columns'] ?? null,
            'test_two_columns container configuration must be present in TCA'
        );

        $colPosItems = $GLOBALS['TCA']['tt_content']['columns']['colPos']['config']['items'] ?? [];
        $values = array_map(static fn (array $item) => (string)($item['value'] ?? $item[1] ?? ''), $colPosItems);
        $this->assertContains('200', $values, 'Container grid colPos 200 must be in static items list');
        $this->assertContains('201', $values, 'Container grid colPos 201 must be in static items list');
    }

    /**
     * When a content element's tx_container_parent points at an existing
     * test_two_columns container, the resolved colPos items must include
     * the grid column the record belongs to.
     */
    public function testColPosResolvesGridColumnsForContainerChild(): void
    {
        // Create the container as a tt_content record on page 1 (admin can use uid=0 as workspace)
        $containerUid = $this->createContainerOnPage(1);

        // Resolve colPos items for a child whose tx_container_parent points
        // at our container. The submitted colPos value is 200 (Left column).
        $childRecord = [
            'pid' => 1,
            'CType' => 'text',
            'tx_container_parent' => $containerUid,
            'colPos' => 200,
        ];

        $resolved = $this->selectItemResolver->resolveSelectItems('tt_content', 'colPos', $childRecord);
        $this->assertNotNull(
            $resolved,
            'colPos resolution must succeed for a container child. Resolved: ' . var_export($resolved, true)
        );
        $this->assertContains(
            '200',
            $resolved['values'],
            'Resolved colPos items for a container child must include the grid column being targeted. Got: ' . json_encode($resolved['values'])
        );
    }

    /**
     * Validating the colPos value of a content element being placed into
     * an existing container must accept the container's own grid columns.
     *
     * This is the regression that motivated the dynamic select-item work:
     * b13/container exposes 50/50 grid columns (colPos 200/201) only via
     * itemsProcFunc, which keys off tx_container_parent in the row.
     */
    public function testValidationAcceptsContainerGridColPos(): void
    {
        $containerUid = $this->createContainerOnPage(1);

        $childRecord = [
            'pid' => 1,
            'CType' => 'text',
            'tx_container_parent' => $containerUid,
            'colPos' => 200,
        ];

        $error = $this->tableAccessService->validateFieldValue(
            'tt_content',
            'colPos',
            200,
            $childRecord
        );
        $this->assertNull(
            $error,
            'Validation must accept colPos 200 when the record is a child of the test_two_columns container. Error: ' . ($error ?? 'null')
        );
    }

    /**
     * End-to-end: writing a content element into a container's left column
     * via the WriteTableTool must succeed without colPos validation rejecting it.
     */
    public function testWriteContentIntoContainerLeftColumn(): void
    {
        $containerUid = $this->createContainerOnPage(1);

        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => 1,
            'data' => [
                'CType' => 'text',
                'header' => 'Inside container',
                'tx_container_parent' => $containerUid,
                'colPos' => 200,
            ],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
    }

    /**
     * A colPos value that does not belong to any registered grid column or
     * backend layout column must still be rejected. Container-aware
     * validation must not become a "any number is allowed" sieve.
     */
    public function testValidationRejectsUnknownColPosForContainerChild(): void
    {
        $containerUid = $this->createContainerOnPage(1);

        $childRecord = [
            'pid' => 1,
            'CType' => 'text',
            'tx_container_parent' => $containerUid,
            'colPos' => 999,
        ];

        $error = $this->tableAccessService->validateFieldValue(
            'tt_content',
            'colPos',
            999,
            $childRecord
        );
        $this->assertNotNull($error, 'colPos 999 must not be accepted for a container child');
        $this->assertStringContainsString('must be one of', $error);
    }

    /**
     * Create a test_two_columns container element on the given page and
     * return its uid. Uses WriteTableTool so the workspace plumbing matches
     * how every other write goes through the MCP tools.
     */
    protected function createContainerOnPage(int $pid): int
    {
        $result = $this->writeTool->execute([
            'action' => 'create',
            'table' => 'tt_content',
            'pid' => $pid,
            'data' => [
                'CType' => 'test_two_columns',
                'header' => 'Test Container',
            ],
        ]);
        $this->assertFalse($result->isError, 'Container creation failed: ' . json_encode($result->jsonSerialize()));
        $payload = json_decode($result->content[0]->text, true);
        $uid = (int)($payload['uid'] ?? 0);
        $this->assertGreaterThan(0, $uid, 'Container uid must be > 0');
        return $uid;
    }
}
