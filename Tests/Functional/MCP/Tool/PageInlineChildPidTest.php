<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Doctrine\DBAL\ParameterType;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * New inline children of a page are stored on that page itself, as the backend
 * form does it (TcaInline::addInlineFirstPid()) - not on the page's parent.
 * Children of a page translation belong to the default-language page.
 *
 * Page TSconfig and permissions are resolved from a child's pid, so a child on
 * the parent page is edited under the parent page's configuration.
 */
class PageInlineChildPidTest extends AbstractFunctionalTest
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/sys_file.csv');
        $this->writeSiteConfiguration();
    }

    #[Test]
    public function createdPageStoresNewInlineChildrenOnItself(): void
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'pages',
            'action' => 'create',
            'data' => [
                'pid' => 2,
                'title' => 'Page with media',
                'media' => [
                    ['uid_local' => 1, 'title' => 'First image'],
                    ['uid_local' => 3, 'title' => 'Second image'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $pageUid = json_decode($result->content[0]->text, true)['uid'];

        $this->assertSame([$pageUid, $pageUid], $this->findMediaReferencePids($pageUid));
    }

    #[Test]
    public function updatedPageStoresNewInlineChildrenOnItself(): void
    {
        $result = GeneralUtility::makeInstance(WriteTableTool::class)->execute([
            'table' => 'pages',
            'action' => 'update',
            'uid' => 4,
            'data' => [
                'media' => [
                    ['uid_local' => 1, 'title' => 'Team image'],
                ],
            ],
        ]);
        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $this->assertSame([4], $this->findMediaReferencePids(4));
    }

    #[Test]
    public function translatedPageStoresNewInlineChildrenOnDefaultLanguagePage(): void
    {
        $writeTool = GeneralUtility::makeInstance(WriteTableTool::class);
        $translateResult = $writeTool->execute([
            'table' => 'pages',
            'action' => 'translate',
            'uid' => 4,
            'data' => [
                'sys_language_uid' => 'de',
                'title' => 'Team (de)',
            ],
        ]);
        $this->assertFalse($translateResult->isError, json_encode($translateResult->jsonSerialize()));
        $translationUid = json_decode($translateResult->content[0]->text, true)['translationUid'];
        $this->assertIsInt($translationUid);

        $updateResult = $writeTool->execute([
            'table' => 'pages',
            'action' => 'update',
            'uid' => $translationUid,
            'data' => [
                'media' => [
                    ['uid_local' => 1, 'title' => 'Teambild'],
                ],
            ],
        ]);
        $this->assertFalse($updateResult->isError, json_encode($updateResult->jsonSerialize()));

        $this->assertSame([4], $this->findMediaReferencePids($translationUid));
    }

    /**
     * Pids of all pages.media references of the page, workspace versions included.
     *
     * @return list<int>
     */
    private function findMediaReferencePids(int $pageUid): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();
        $pids = $queryBuilder
            ->select('pid')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter('pages')),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('media')),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', 0)
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map('intval', $pids);
    }

    private function writeSiteConfiguration(): void
    {
        $siteConfiguration = [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'title' => 'English',
                    'enabled' => true,
                    'languageId' => 0,
                    'base' => '/',
                    'locale' => 'en_US.UTF-8',
                    'iso-639-1' => 'en',
                    'flag' => 'us',
                ],
                [
                    'title' => 'German',
                    'enabled' => true,
                    'languageId' => 1,
                    'base' => '/de/',
                    'locale' => 'de_DE.UTF-8',
                    'iso-639-1' => 'de',
                    'flag' => 'de',
                ],
            ],
        ];
        $configurationPath = $this->instancePath . '/typo3conf/sites/page-inline-child-pid';
        GeneralUtility::mkdir_deep($configurationPath);
        GeneralUtility::writeFile($configurationPath . '/config.yaml', Yaml::dump($siteConfiguration, 99, 2), true);
    }
}
