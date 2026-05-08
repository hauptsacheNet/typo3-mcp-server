<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\TypoScript\PageTsConfigFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies TCEFORM-driven field visibility in {@see TableAccessService::canAccessField}.
 *
 * Specifically: installations that disable a field globally and re-enable it
 * for selected record types via `TCEFORM.<table>.<field>.types.<recordType>.disabled = 0`
 * must see the field for those types (issue #38, PR #39).
 */
class TableAccessServiceTSconfigTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected TableAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // The legacy `defaultPageTSconfig` mechanism was removed in TYPO3 14
        // (#101799), and `Configuration/page.tsconfig` is loaded from extensions
        // at bootstrap. We therefore seed page TSconfig for pid=0 directly into
        // the runtime cache that `BackendUtility::getPagesTSconfig(0)` consults
        // first. The minimum supported version is 13, where the cache keys are
        // identical, so the same approach works there as well.
        if (GeneralUtility::makeInstance(Typo3Version::class)->getMajorVersion() < 13) {
            $this->markTestSkipped('TableAccessService requires TYPO3 13 or newer.');
        }

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        $this->service = new TableAccessService();
    }

    /**
     * Globally disabled field is hidden when no record type is given.
     */
    public function testGloballyDisabledFieldIsHiddenWithoutType(): void
    {
        $this->seedRootTSconfig('TCEFORM.tt_content.bodytext.disabled = 1');

        $this->assertFalse(
            $this->service->canAccessField('tt_content', 'bodytext'),
            'Globally disabled field should be hidden when no record type is given'
        );
    }

    /**
     * Type-specific override re-enables a globally disabled field.
     *
     * Regression for #38 / PR #39: installations that disable fields by default
     * and selectively re-enable them per record type must see the field for
     * those types — not just have the global disable shadow the override.
     */
    public function testTypeSpecificOverrideReEnablesGloballyDisabledField(): void
    {
        $this->seedRootTSconfig(
            "TCEFORM.tt_content.bodytext.disabled = 1\n"
            . 'TCEFORM.tt_content.bodytext.types.textmedia.disabled = 0'
        );

        $this->assertTrue(
            $this->service->canAccessField('tt_content', 'bodytext', 'textmedia'),
            'TCEFORM types.<recordType>.disabled = 0 must override the global disabled = 1'
        );
        $this->assertFalse(
            $this->service->canAccessField('tt_content', 'bodytext', 'text'),
            'Record types without an explicit override must still inherit the global disabled = 1'
        );
        $this->assertFalse(
            $this->service->canAccessField('tt_content', 'bodytext'),
            'Without a record type the global disabled = 1 must still apply'
        );
    }

    /**
     * Type-specific disable hides a field that is enabled globally.
     */
    public function testTypeSpecificDisableHidesField(): void
    {
        $this->seedRootTSconfig('TCEFORM.tt_content.header.types.text.disabled = 1');

        $this->assertFalse(
            $this->service->canAccessField('tt_content', 'header', 'text'),
            'TCEFORM types.<recordType>.disabled = 1 must hide the field for that record type'
        );
        $this->assertTrue(
            $this->service->canAccessField('tt_content', 'header', 'textmedia'),
            'Other record types must remain unaffected by an unrelated type-specific disable'
        );
        $this->assertTrue(
            $this->service->canAccessField('tt_content', 'header'),
            'Without a record type, the field must remain accessible because it is not globally disabled'
        );
    }

    /**
     * Place the given TSconfig at the root level so `BackendUtility::getPagesTSconfig(0)`
     * returns it. We bypass the rootline lookup by populating the runtime cache that
     * BackendUtility consults first.
     */
    private function seedRootTSconfig(string $tsConfig): void
    {
        // TsConfigTreeBuilder skips rootline entries with uid=0 (see
        // `getRootlinePageTsConfigTree`), so a synthetic uid=1 entry is needed
        // for the factory to actually parse our TSconfig string. The resulting
        // PageTsConfig is then cached under pid=0 — the id `canAccessField`
        // reads from.
        $rootLine = [
            0 => [
                'uid' => 1,
                'pid' => 0,
                'TSconfig' => $tsConfig,
                'tsconfig_includes' => '',
                'is_siteroot' => 0,
                't3ver_oid' => 0,
                't3ver_wsid' => 0,
                't3ver_state' => 0,
                't3ver_stage' => 0,
                'doktype' => 0,
                'sorting' => 0,
                'deleted' => 0,
                'hidden' => 0,
            ],
        ];

        $factory = GeneralUtility::makeInstance(PageTsConfigFactory::class);
        $pageTsConfig = $factory->create($rootLine, new NullSite(), null);

        $cache = GeneralUtility::makeInstance(CacheManager::class)->getCache('runtime');
        $hash = 'mcp-test-' . md5($tsConfig);
        $cache->set('pageTsConfig-pid-to-hash-0', $hash);
        $cache->set('pageTsConfig-hash-to-object-' . $hash, $pageTsConfig);
    }
}
