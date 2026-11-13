<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A TCA column of type=password holds a secret (usually a hash). The backend
 * never shows the stored value, and the read tools must not either - not even
 * to an admin, and not indirectly through a filter or the search.
 *
 * The tables core ships such a column on (be_users, fe_users) are not exposed
 * by default; an installation can expose them, or have a table of its own. The
 * test turns tt_content.subheader into a password column instead.
 */
class ReadTablePasswordFieldTest extends AbstractFunctionalTest
{
    private const SECRET = '$argon2i$v=19$m=65536,t=16,p=1$c2VjcmV0c2FsdA$storedsecrethash';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TCA']['tt_content']['columns']['subheader']['config'] = ['type' => 'password'];
        $this->connectionPool->getConnectionForTable('tt_content')
            ->update('tt_content', ['subheader' => self::SECRET], ['uid' => 100]);
    }

    public function testThePasswordIsNotReturned(): void
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute(['table' => 'tt_content', 'uid' => 100]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringNotContainsString('storedsecrethash', $result->content[0]->text);
        $this->assertArrayNotHasKey('subheader', json_decode($result->content[0]->text, true)['records'][0]);
    }

    public function testAskingForThePasswordFieldDoesNotReturnIt(): void
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'uid' => 100,
            'fields' => ['header', 'subheader'],
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringNotContainsString('storedsecrethash', $result->content[0]->text);
    }

    public function testThePasswordCannotBeProbedWithAFilter(): void
    {
        $result = GeneralUtility::makeInstance(ReadTableTool::class)->execute([
            'table' => 'tt_content',
            'where' => [['field' => 'subheader', 'operator' => 'startsWith', 'value' => '$argon2i']],
        ]);

        $this->assertTrue($result->isError, 'A filter that matches tells what the hash starts with');
        $this->assertStringContainsString('not a readable field', $result->content[0]->text);
    }

    public function testThePasswordIsNotSearched(): void
    {
        $result = GeneralUtility::makeInstance(SearchTool::class)->execute(['terms' => ['storedsecrethash']]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertStringContainsString('Total Results: 0', $result->content[0]->text);
        $this->assertStringNotContainsString('[UID: 100]', $result->content[0]->text);
    }
}
