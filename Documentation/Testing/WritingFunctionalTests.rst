..  include:: /Includes.rst.txt

..  _testing_writing_functional_tests:

============================
Writing functional tests
============================

When you add an event listener or customisation, write a functional test
for it. The test invokes the MCP tool directly (no transport, no LLM),
which keeps assertions tight and CI fast.

This page covers the patterns. The MCP server's own test suite
(:file:`Tests/Functional/`) is a comprehensive reference — start there
for working code.

The basic shape
===============

The fixture base class :php:`AbstractFunctionalTestCase` (or
TYPO3's :php:`FunctionalTestCase`, depending on your extension's
testing-framework version) gives you a TYPO3 instance, a database, and a
backend user.

..  code-block:: php

    <?php

    declare(strict_types=1);

    namespace Vendor\YourExt\Tests\Functional;

    use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
    use TYPO3\CMS\Core\Utility\GeneralUtility;
    use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

    final class TenantRestrictionListenerTest extends FunctionalTestCase
    {
        protected array $coreExtensionsToLoad = ['workspaces'];

        protected array $testExtensionsToLoad = [
            'hn/typo3-mcp-server',
            'vendor/your-ext',
        ];

        protected function setUp(): void
        {
            parent::setUp();
            $this->importCSVDataSet(__DIR__ . '/Fixtures/be_users.csv');
            $this->setUpBackendUser(1);
            $this->importCSVDataSet(__DIR__ . '/Fixtures/items.csv');
        }

        public function testTenantRestrictionExcludesForeignTenantRecords(): void
        {
            $GLOBALS['BE_USER']->user['tx_yourext_tenant'] = 7;

            $tool = GeneralUtility::makeInstance(ReadTableTool::class);
            $result = $tool->execute([
                'table' => 'tx_yourext_domain_model_item',
                'limit' => 100,
            ]);

            self::assertFalse($result->isError, json_encode($result->jsonSerialize()));

            $payload = $this->decode($result);
            $tenantIds = array_unique(array_column($payload['records'], 'tenant'));
            self::assertSame([7], $tenantIds);
        }

        private function decode($result): array
        {
            return json_decode(
                $result->getContent()[0]->getText(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }
    }

Notes:

*   :php:`GeneralUtility::makeInstance()` returns the tool through the
    DI container, so your listener is registered as expected.
*   :php:`$result->isError` is the first thing to assert; the project
    convention is :php:`self::assertFalse($result->isError, json_encode($result->jsonSerialize()))`
    so the failure message contains the raw response.
*   The tool returns ``CallToolResult`` objects; the JSON body is in
    :php:`$result->getContent()[0]->getText()`.

Testing veto behaviour
======================

For a :php:`BeforeRecordWriteEvent` listener that vetoes:

..  code-block:: php

    public function testWriteIsVetoedWithoutCategories(): void
    {
        $tool = GeneralUtility::makeInstance(WriteTableTool::class);
        $result = $tool->execute([
            'action' => 'create',
            'table' => 'tx_news_domain_model_news',
            'data' => ['pid' => 42, 'title' => 'Test'],
        ]);

        self::assertTrue($result->isError);
        self::assertStringContainsString(
            'need at least one category',
            $result->getContent()[0]->getText()
        );
    }

Testing enrichment
==================

For an :php:`AfterRecordReadEvent` listener that adds a computed field:

..  code-block:: php

    public function testAbsoluteUrlIsAttachedWhenRequested(): void
    {
        $tool = GeneralUtility::makeInstance(ReadTableTool::class);
        $result = $tool->execute([
            'table' => 'pages',
            'uid' => 1,
            'fields' => ['title', 'absolute_url'],
        ]);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $records = $this->decode($result)['records'];
        self::assertNotEmpty($records[0]['absolute_url']);
    }

    public function testAbsoluteUrlIsSkippedWhenNotRequested(): void
    {
        // shouldEnrich() should short-circuit when fields filter excludes it.
        // Make this test stable by spying on the resolver if it has side effects.
    }

Workspace tests
===============

Because the MCP always operates in a workspace, your tests automatically
exercise the workspace path. If your listener has different behaviour
in live vs. workspace, set the workspace explicitly:

..  code-block:: php

    $GLOBALS['BE_USER']->workspace = 1;

The MCP server creates a workspace if none exists; in tests with
existing workspace fixtures, switching is explicit.

Watching the schema
===================

For :php:`AfterSchemaLoadEvent` listeners, exercise the schema tool too:

..  code-block:: php

    public function testComputedFieldAppearsInSchema(): void
    {
        $tool = GeneralUtility::makeInstance(GetTableSchemaTool::class);
        $result = $tool->execute(['table' => 'pages']);

        self::assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $schema = $result->getContent()[0]->getText();
        self::assertStringContainsString('absolute_url', $schema);
        self::assertStringContainsString('Computed', $schema);
    }

What to test
============

A reasonable test inventory for a custom listener:

*   **Happy path** — the listener does the thing it's supposed to.
*   **Doesn't apply** — when the listener should be a no-op (different
    table, different action), the result is unchanged.
*   **Edge case** — empty data, missing context (no BE user, no tenant,
    etc.).
*   **Performance guard** — if you wrote a ``shouldEnrich()``
    short-circuit, a test that proves enrichment doesn't run when no
    one asks for the field.

This list overlaps almost entirely with what you'd test for any
DataHandler hook. Treat MCP listeners with the same rigour.
