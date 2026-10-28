<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Hn\McpServer\Event\AfterRecordUrlGeneratedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\PageDoktypeRegistry;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\InvalidRouteArgumentsException;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Versioning\VersionState;

/**
 * Frontend URLs of records, resolved the way the backend "View" button does it.
 *
 * Pages link to themselves and content elements to their page with a "#c<uid>"
 * anchor. Any other table (news, events, ...) is linkable once the integrator
 * configured `TCEMAIN.preview.<table>` in Page TSconfig — the same setting the
 * backend and the workspace module use — so the URL is whatever the site's
 * routing (route enhancers, slugs) makes of previewPageId, fieldToParameterMap
 * and additionalGetParameters. Records without a viewable target page (e.g.
 * content in a sysfolder, a table without TCEMAIN.preview) get no URL instead
 * of a misleading link to the site root. AfterRecordUrlGeneratedEvent lets
 * listeners move a URL to where visitors see the site, e.g. the decoupled
 * frontend of EXT:headless (HeadlessFrontendUrlListener).
 *
 * Two URLs are resolved per record:
 * - frontend_url: the record on the live website. Missing for records that so
 *   far only exist as a draft.
 * - preview_url: only for records whose draft differs from live. A login-free
 *   workspace preview link (ADMCMD_prev token, as the workspace module creates
 *   them). Tokens are reused while at least half their lifetime remains, so
 *   reading records does not add a sys_preview row per request.
 *
 * Cost: TSconfig, rootline and page lookups hit TYPO3's runtime caches, so a
 * batch costs about one router call per record (plus whatever query its route
 * enhancers run). Content elements of one page share a single router call.
 */
class RecordUrlService
{
    public const PREVIEW_PARAMETER = 'ADMCMD_prev';

    /**
     * Per-batch memo of the preview token, generated URLs (without fragment) and
     * viewable pages. Reset on every getUrls() call so a long-running stdio
     * process never serves a URL of a since renamed page or an expiring token.
     *
     * @var array{workspace: int, keyword: string, endtime: int}|null
     */
    protected ?array $previewToken = null;

    /**
     * @var array<string, string|null>
     */
    protected array $urlCache = [];

    /**
     * @var array<string, bool>
     */
    protected array $viewablePageCache = [];

    /**
     * SiteFinder::getSiteByPageId() builds a RootlineUtility, which in a workspace
     * queries the page uncached on every call.
     *
     * @var array<int, Site|null>
     */
    protected array $siteCache = [];

    public function __construct(
        protected readonly SiteFinder $siteFinder,
        protected readonly ConnectionPool $connectionPool,
        protected readonly SiteInformationService $siteInformationService,
        protected readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Whether a table can produce URLs at the given page, i.e. whether ReadTable
     * should advertise the URL fields for it.
     */
    public function isLinkableTable(string $table, int $pid): bool
    {
        if ($table === 'pages' || $table === 'tt_content') {
            return true;
        }
        return is_array($this->getPreviewConfiguration($table, $pid));
    }

    /**
     * Whether preview links (which grant login-free read access to all drafts of
     * the workspace until they expire) may be handed out at all.
     */
    public function isPreviewLinkEnabled(): bool
    {
        try {
            $enabled = GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('mcp_server', 'workspacePreviewLinks');
        } catch (\Exception) {
            $enabled = true;
        }
        return (bool)$enabled;
    }

    /**
     * Resolve the URLs of a batch of records.
     *
     * @param string $table
     * @param array<int|string, array<string, mixed>> $records Raw rows after workspace overlay
     *        (BackendUtility::workspaceOL), with uid still the live uid where one exists
     * @param int|null $languageId Language to link pages in; null uses each record's own language
     * @return array<int|string, array{frontend_url?: string, preview_url?: string}> Keyed like
     *         $records; records without any URL are left out
     */
    public function getUrls(string $table, array $records, ?int $languageId = null): array
    {
        $this->previewToken = null;
        $this->urlCache = [];
        $this->viewablePageCache = [];
        $this->siteCache = [];

        $workspaceId = $this->getWorkspaceId();
        $withPreview = $workspaceId > 0 && $this->isPreviewLinkEnabled();

        // A page's preview differs from live as soon as any of its content does,
        // not only when the page record itself was edited.
        $pagesWithPendingChanges = [];
        if ($table === 'pages' && $withPreview) {
            $pageUids = [];
            foreach ($records as $record) {
                $pageUids[] = $this->getLiveUid($record);
            }
            $pagesWithPendingChanges = $this->findPagesWithPendingChanges($pageUids);
        }

        $urls = [];
        foreach ($records as $key => $record) {
            $entry = [];
            $isDraftOnly = VersionState::tryFrom((int)($record['t3ver_state'] ?? 0)) === VersionState::NEW_PLACEHOLDER;

            if (!$isDraftOnly) {
                $livePid = (int)($record['_ORIG_pid'] ?? $record['pid'] ?? 0);
                $target = $this->resolveTarget($table, $record, $livePid, $languageId);
                $url = $target !== null ? $this->buildUrl($target, 0) : null;
                if ($url !== null) {
                    $entry['frontend_url'] = $url;
                }
            }

            $hasPendingChanges = (int)($record['t3ver_wsid'] ?? 0) > 0
                || isset($pagesWithPendingChanges[$this->getLiveUid($record)]);
            if ($withPreview && $hasPendingChanges) {
                $target = $this->resolveTarget($table, $record, (int)($record['pid'] ?? 0), $languageId);
                $url = $target !== null ? $this->buildUrl($target, $workspaceId) : null;
                if ($url !== null) {
                    $entry['preview_url'] = $this->addPreviewToken($url, $workspaceId);
                }
            }

            if ($entry !== []) {
                $urls[$key] = $entry;
            }
        }
        return $urls;
    }

    /**
     * Expiry (unix timestamp) of the preview token in the URLs of the last
     * getUrls() call, null when they contain none.
     */
    public function getPreviewLinkEndtime(): ?int
    {
        return $this->previewToken['endtime'] ?? null;
    }

    /**
     * Live page uids (of the given ones) whose workspace draft differs from live:
     * the page itself, one of its translations or content on it was created,
     * changed, moved or deleted in the current workspace. Two queries per batch.
     *
     * @param int[] $pageUids Live page uids (or the own uid of draft-only pages)
     * @return array<int, true>
     */
    public function findPagesWithPendingChanges(array $pageUids): array
    {
        $workspaceId = $this->getWorkspaceId();
        $pageUids = array_values(array_unique(array_filter(array_map('intval', $pageUids))));
        if ($workspaceId <= 0 || $pageUids === []) {
            return [];
        }

        $pending = [];

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $uidsParameter = $queryBuilder->createNamedParameter($pageUids, ArrayParameterType::INTEGER);
        $versions = $queryBuilder
            ->select('uid', 't3ver_oid', 'l10n_parent')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER)),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->in('uid', $uidsParameter),
                    $queryBuilder->expr()->in('t3ver_oid', $uidsParameter),
                    $queryBuilder->expr()->in('l10n_parent', $uidsParameter)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($versions as $version) {
            $pageUid = (int)$version['l10n_parent'] ?: ((int)$version['t3ver_oid'] ?: (int)$version['uid']);
            $pending[$pageUid] = true;
        }

        if (!empty($GLOBALS['TCA']['tt_content']['ctrl']['versioningWS'])) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $pids = $queryBuilder
                ->select('pid')
                ->distinct()
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter($workspaceId, ParameterType::INTEGER)),
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pageUids, ArrayParameterType::INTEGER))
                )
                ->executeQuery()
                ->fetchFirstColumn();
            foreach ($pids as $pid) {
                $pending[(int)$pid] = true;
            }
        }

        return $pending;
    }

    /**
     * Page, route parameters and anchor a record links to, following the
     * TCEMAIN.preview semantics of the TYPO3 backend.
     *
     * @return array{pageId: int, parameters: array<string, mixed>, fragment: string}|null
     */
    protected function resolveTarget(string $table, array $record, int $pid, ?int $languageId): ?array
    {
        $languageField = (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
        $l10nPointerField = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');
        $recordLanguage = $languageField !== '' ? (int)($record[$languageField] ?? 0) : 0;
        $l10nParent = $l10nPointerField !== '' ? (int)($record[$l10nPointerField] ?? 0) : 0;
        $language = $languageId ?? $recordLanguage;
        $parameters = $language > 0 ? ['_language' => $language] : [];
        $uid = $this->getLiveUid($record);

        if ($table === 'pages') {
            // A page translation is linked through its default language page
            return ['pageId' => $l10nParent ?: $uid, 'parameters' => $parameters, 'fragment' => ''];
        }

        $configuration = $this->getPreviewConfiguration($table, $pid);
        if ($configuration === null && $table !== 'tt_content') {
            return null;
        }
        $configuration ??= [];

        $linkParameters = [];
        if (is_array($configuration['fieldToParameterMap.'] ?? null)) {
            // useDefaultLanguageRecord (default 1): translations link with the uid of
            // their default language record, which is what plugins usually expect.
            $useDefaultLanguageRecord = (bool)($configuration['useDefaultLanguageRecord'] ?? true);
            foreach ($configuration['fieldToParameterMap.'] as $field => $parameterName) {
                $value = $record[$field] ?? '';
                if ($field === 'uid') {
                    $value = $useDefaultLanguageRecord && $l10nParent > 0 ? $l10nParent : $uid;
                }
                $linkParameters[$parameterName] = $value;
            }
        }
        if (is_array($configuration['additionalGetParameters.'] ?? null)) {
            $linkParameters = array_replace(
                $linkParameters,
                GeneralUtility::removeDotsFromTS($configuration['additionalGetParameters.'])
            );
        }
        // Parameter names like "tx_news_pi1[news]" become nested arrays, as the router expects them
        parse_str(HttpUtility::buildQueryString($linkParameters), $routeParameters);

        return [
            'pageId' => (int)($configuration['previewPageId'] ?? $pid),
            'parameters' => array_replace($routeParameters, $parameters),
            'fragment' => $table === 'tt_content' ? 'c' . $uid : '',
        ];
    }

    /**
     * @param array{pageId: int, parameters: array<string, mixed>, fragment: string} $target
     */
    protected function buildUrl(array $target, int $workspaceId): ?string
    {
        $pageId = $target['pageId'];
        if ($pageId <= 0) {
            return null;
        }
        $context = $this->createContext($workspaceId);
        if (!$this->isViewablePage($pageId, $workspaceId, $context)) {
            return null;
        }

        $cacheKey = $workspaceId . '|' . $pageId . '|' . serialize($target['parameters']);
        if (!array_key_exists($cacheKey, $this->urlCache)) {
            $url = null;
            $site = $this->getSite($pageId);
            try {
                if ($site !== null) {
                    $parameters = $target['parameters'];
                    $language = $site->getLanguageById((int)($parameters['_language'] ?? 0));
                    $parameters['_language'] = $language;
                    $uri = $site->getRouter($context)->generateUri($pageId, $parameters, '', RouterInterface::ABSOLUTE_URL);
                    $url = (string)$this->siteInformationService->makeAbsoluteUrl((string)$uri);

                    // Let listeners move the URL to where visitors actually see the site (EXT:headless)
                    $event = $this->eventDispatcher->dispatch(
                        new AfterRecordUrlGeneratedEvent($url, $site, $language, $pageId, $workspaceId > 0)
                    );
                    $url = $event->getUrl();
                }
            } catch (InvalidRouteArgumentsException|\InvalidArgumentException) {
                // Unknown language or arguments the route rejects: no link
            }
            $this->urlCache[$cacheKey] = $url;
        }

        $url = $this->urlCache[$cacheKey];
        if ($url === null || $target['fragment'] === '') {
            return $url;
        }
        return $url . '#' . $target['fragment'];
    }

    protected function getSite(int $pageId): ?Site
    {
        if (!array_key_exists($pageId, $this->siteCache)) {
            try {
                $this->siteCache[$pageId] = $this->siteFinder->getSiteByPageId($pageId);
            } catch (SiteNotFoundException) {
                $this->siteCache[$pageId] = null;
            }
        }
        return $this->siteCache[$pageId];
    }

    /**
     * The page exists in the given workspace (hidden pages included, as in the
     * backend) and its doktype renders in the frontend. Without this check the
     * router would link a page it cannot find to the site root.
     */
    protected function isViewablePage(int $pageId, int $workspaceId, Context $context): bool
    {
        $cacheKey = $workspaceId . '|' . $pageId;
        if (!isset($this->viewablePageCache[$cacheKey])) {
            $page = GeneralUtility::makeInstance(PageRepository::class, $context)->getPage($pageId, true);
            $this->viewablePageCache[$cacheKey] = $page !== []
                && VersionState::tryFrom((int)($page['t3ver_state'] ?? 0)) !== VersionState::DELETE_PLACEHOLDER
                && $this->isViewableDoktype((int)($page['doktype'] ?? 0), $pageId);
        }
        return $this->viewablePageCache[$cacheKey];
    }

    /**
     * Same rule as the backend view button: TCEMAIN.preview.disableButtonForDokType,
     * by default sysfolders and spacers are not viewable.
     */
    protected function isViewableDoktype(int $doktype, int $pageId): bool
    {
        if ($doktype <= 0) {
            return false;
        }
        $registry = GeneralUtility::makeInstance(PageDoktypeRegistry::class);
        // TYPO3 14 also honours the doktype's TCA "isViewable" flag
        if (method_exists($registry, 'isPageViewable')) {
            return $registry->isPageViewable($doktype, $pageId);
        }
        $configuration = BackendUtility::getPagesTSconfig($pageId)['TCEMAIN.']['preview.'] ?? [];
        $excludedDoktypes = isset($configuration['disableButtonForDokType'])
            ? GeneralUtility::intExplode(',', (string)$configuration['disableButtonForDokType'], true)
            : [PageRepository::DOKTYPE_SYSFOLDER, PageRepository::DOKTYPE_SPACER];
        return !in_array($doktype, $excludedDoktypes, true);
    }

    /**
     * Context to generate links in: the given workspace, with hidden and scheduled
     * records resolvable like in the backend preview.
     */
    protected function createContext(int $workspaceId): Context
    {
        $context = clone GeneralUtility::makeInstance(Context::class);
        $context->setAspect('workspace', new WorkspaceAspect($workspaceId));
        $context->setAspect('visibility', new VisibilityAspect(true, true, false, true));
        return $context;
    }

    protected function getPreviewConfiguration(string $table, int $pid): ?array
    {
        $configuration = BackendUtility::getPagesTSconfig(max(0, $pid))['TCEMAIN.']['preview.'][$table . '.'] ?? null;
        return is_array($configuration) ? $configuration : null;
    }

    protected function addPreviewToken(string $url, int $workspaceId): string
    {
        $uri = new Uri($url);
        $query = $uri->getQuery();
        // ADMCMD_prev is excluded from the cHash calculation, so it can be appended
        // to a URL that already carries a cHash.
        $query .= ($query === '' ? '' : '&') . self::PREVIEW_PARAMETER . '=' . $this->getPreviewKeyword($workspaceId);
        return (string)$uri->withQuery($query);
    }

    /**
     * A sys_preview keyword for the workspace, reusing a stored one while at least
     * half of the configured lifetime remains on it.
     */
    protected function getPreviewKeyword(int $workspaceId): string
    {
        if ($this->previewToken !== null && $this->previewToken['workspace'] === $workspaceId) {
            return $this->previewToken['keyword'];
        }

        $now = time();
        $lifetime = $this->getPreviewLinkLifetime($workspaceId) * 3600;
        $minimumEndtime = $now + intdiv($lifetime, 2);

        // Same configuration the workspace module writes, so either token is equivalent
        $configuration = (string)json_encode(['fullWorkspace' => $workspaceId]);
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_preview');
        $queryBuilder->getRestrictions()->removeAll();
        $token = $queryBuilder
            ->select('keyword', 'endtime')
            ->from('sys_preview')
            ->where(
                $queryBuilder->expr()->eq('config', $queryBuilder->createNamedParameter($configuration)),
                $queryBuilder->expr()->gte('endtime', $queryBuilder->createNamedParameter($minimumEndtime, ParameterType::INTEGER)),
                // A token outliving the configured lifetime predates a shortened lifetime
                $queryBuilder->expr()->lte('endtime', $queryBuilder->createNamedParameter($now + $lifetime, ParameterType::INTEGER))
            )
            ->orderBy('endtime', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (is_array($token)) {
            $keyword = (string)$token['keyword'];
            $endtime = (int)$token['endtime'];
        } else {
            $keyword = bin2hex(random_bytes(16));
            $endtime = $now + $lifetime;
            $this->connectionPool->getConnectionForTable('sys_preview')->insert('sys_preview', [
                'keyword' => $keyword,
                'tstamp' => $now,
                'endtime' => $endtime,
                'config' => $configuration,
            ]);
        }

        $this->previewToken = ['workspace' => $workspaceId, 'keyword' => $keyword, 'endtime' => $endtime];
        return $keyword;
    }

    /**
     * Lifetime in hours, resolved like the workspace module does: the workspace's
     * own setting, then the user's TSconfig, then 48 hours.
     */
    protected function getPreviewLinkLifetime(int $workspaceId): int
    {
        $workspace = BackendUtility::getRecord('sys_workspace', $workspaceId, 'previewlink_lifetime');
        if ((int)($workspace['previewlink_lifetime'] ?? 0) > 0) {
            return (int)$workspace['previewlink_lifetime'];
        }
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $hours = $backendUser !== null
            ? (int)($backendUser->getTSConfig()['options.']['workspaces.']['previewLinkTTLHours'] ?? 0)
            : 0;
        return $hours > 0 ? $hours : 48;
    }

    protected function getLiveUid(array $record): int
    {
        return (int)($record['t3ver_oid'] ?? 0) ?: (int)($record['uid'] ?? 0);
    }

    protected function getWorkspaceId(): int
    {
        return (int)($GLOBALS['BE_USER']->workspace ?? 0);
    }
}
