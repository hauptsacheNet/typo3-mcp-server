<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Maps site languages to the identifiers the MCP tools use instead of
 * numeric language IDs.
 *
 * Each language of a site gets its two-letter code ("de", "en") as long as
 * that code is unique within the site. When several languages of one site
 * share a code (de_DE, de_AT, de_CH), each of them gets its full tag from
 * hreflang or locale instead ("de-DE", "de-AT", "de-CH"), so every language
 * stays addressable. Identifiers are resolved per site: the same identifier
 * may stand for different language IDs on different sites.
 */
class LanguageService implements SingletonInterface
{
    protected SiteFinder $siteFinder;

    /**
     * Languages per site, keyed by site identifier and language ID
     * @var array<string, array<int, array{uid: int, identifier: string, isoCode: string, title: string, locale: mixed, enabled: bool}>>
     */
    protected array $languagesBySite = [];

    /**
     * Lower-cased identifier => site identifier => language ID
     * @var array<string, array<string, int>>
     */
    protected array $identifierIndex = [];

    /**
     * Whether the mappings have been initialized
     */
    protected bool $initialized = false;

    public function __construct()
    {
        $this->siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Initialize language mappings from all sites
     */
    protected function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        foreach ($this->siteFinder->getAllSites() as $site) {
            $languages = $this->buildSiteLanguages($site);
            $this->languagesBySite[$site->getIdentifier()] = $languages;
            foreach ($languages as $uid => $language) {
                $this->identifierIndex[strtolower($language['identifier'])][$site->getIdentifier()] = $uid;
            }
        }

        $this->initialized = true;
    }

    /**
     * @return array<int, array{uid: int, identifier: string, isoCode: string, title: string, locale: mixed, enabled: bool}>
     */
    protected function buildSiteLanguages(Site $site): array
    {
        $candidates = [];
        foreach ($site->getAllLanguages() as $language) {
            $isoCode = $this->extractIsoCode($language);
            if ($isoCode !== null) {
                $candidates[$language->getLanguageId()] = [$language, $isoCode];
            }
        }
        ksort($candidates);

        $codeCount = array_count_values(array_map(static fn(array $candidate): string => $candidate[1], $candidates));
        $languages = [];
        $used = [];
        foreach ($candidates as $uid => [$language, $isoCode]) {
            $identifier = $codeCount[$isoCode] === 1 ? $isoCode : $this->extractLanguageTag($language, $isoCode);
            if (isset($used[strtolower($identifier)])) {
                // Two languages with the same full tag: keep both addressable
                $identifier .= '-' . $uid;
            }
            $used[strtolower($identifier)] = true;
            $languages[$uid] = [
                'uid' => $uid,
                'identifier' => $identifier,
                'isoCode' => $isoCode,
                'title' => $language->getTitle(),
                'locale' => $language->getLocale(),
                'enabled' => $language->isEnabled(),
            ];
        }
        return $languages;
    }

    /**
     * Extract ISO code from SiteLanguage
     * Tries multiple sources in order of preference
     */
    protected function extractIsoCode(SiteLanguage $language): ?string
    {
        // Get the language configuration array
        $languageConfig = $language->toArray();

        // 1. Try iso-639-1 configuration (two-letter code like 'en', 'de')
        if (isset($languageConfig['iso-639-1']) && strlen($languageConfig['iso-639-1']) === 2) {
            return strtolower($languageConfig['iso-639-1']);
        }

        // 2. Try to get language code from Locale object
        try {
            $locale = $language->getLocale();
            if ($locale !== null) {
                $languageCode = $locale->getLanguageCode();
                if (!empty($languageCode) && strlen($languageCode) === 2) {
                    return strtolower($languageCode);
                }
            }
        } catch (\Throwable $e) {
            // Locale might not be properly configured
        }

        // 3. Try hreflang (might be like 'en-us', we take the first part)
        $hreflang = $language->getHreflang();
        if (!empty($hreflang)) {
            $parts = explode('-', $hreflang);
            if (!empty($parts[0]) && strlen($parts[0]) === 2) {
                return strtolower($parts[0]);
            }
        }

        // 4. Try to parse locale string as fallback
        if (isset($languageConfig['locale']) && !empty($languageConfig['locale'])) {
            $parts = preg_split('/[_\-\.]/', $languageConfig['locale']);
            if (!empty($parts[0]) && strlen($parts[0]) === 2) {
                return strtolower($parts[0]);
            }
        }

        return null;
    }

    /**
     * Full language tag ("de-AT") for a language whose two-letter code is not
     * unique within its site: from hreflang, else from the locale, else the
     * code plus the language ID.
     */
    protected function extractLanguageTag(SiteLanguage $language, string $isoCode): string
    {
        $languageConfig = $language->toArray();
        $sources = [$language->getHreflang(), (string)($languageConfig['locale'] ?? '')];
        foreach ($sources as $source) {
            // Drop encoding and modifier: de_AT.UTF-8, de_AT@euro
            $source = preg_replace('/[.@].*$/', '', trim((string)$source));
            $parts = preg_split('/[_\-]/', (string)$source) ?: [];
            if (count($parts) < 2 || strlen($parts[0]) !== 2) {
                continue;
            }
            $tag = [strtolower($parts[0])];
            foreach (array_slice($parts, 1) as $part) {
                $tag[] = match (strlen($part)) {
                    2 => strtoupper($part),
                    4 => ucfirst(strtolower($part)),
                    default => $part,
                };
            }
            return implode('-', $tag);
        }
        return $isoCode . '-' . $language->getLanguageId();
    }

    /**
     * Language UID for an identifier ("de", "de-AT", case-insensitive).
     *
     * With a page, the identifier is resolved within that page's site.
     * Without one it must mean the same language ID on every site that
     * knows it; otherwise it is ambiguous and null is returned.
     */
    public function getUidFromIsoCode(string $isoCode, ?int $pageId = null): ?int
    {
        $this->initialize();

        $matches = $this->identifierIndex[strtolower($isoCode)] ?? [];
        $siteIdentifier = $this->getSiteIdentifierForPage($pageId);
        if ($siteIdentifier !== null) {
            return $matches[$siteIdentifier] ?? null;
        }
        $uids = array_unique(array_values($matches));
        return count($uids) === 1 ? (int)reset($uids) : null;
    }

    /**
     * Resolve a language given as identifier or numeric ID, in the context of
     * a page's site when given.
     *
     * @throws \InvalidArgumentException With the valid values when the language is unknown or ambiguous
     */
    public function resolveLanguageUid(string|int $language, ?int $pageId = null): int
    {
        $this->initialize();

        $siteIdentifier = $this->getSiteIdentifierForPage($pageId);
        $value = is_string($language) ? trim($language) : $language;

        if (is_int($value) || preg_match('/^-?\d+$/', $value)) {
            $uid = (int)$value;
            if ($uid === -1) {
                // "All languages" is not a site language but always valid
                return -1;
            }
            $known = $siteIdentifier !== null
                ? isset($this->languagesBySite[$siteIdentifier][$uid])
                : $this->isLanguageUidKnown($uid);
            if ($known) {
                return $uid;
            }
            throw new \InvalidArgumentException(sprintf(
                'Unknown language ID: %d%s. Valid languages: %s',
                $uid,
                $siteIdentifier !== null ? ' for this site' : '',
                $this->describeLanguages($pageId)
            ));
        }

        $matches = $this->identifierIndex[strtolower($value)] ?? [];
        if ($siteIdentifier !== null) {
            if (isset($matches[$siteIdentifier])) {
                return $matches[$siteIdentifier];
            }
        } else {
            $uids = array_unique(array_values($matches));
            if (count($uids) === 1) {
                return (int)reset($uids);
            }
            if (count($uids) > 1) {
                $perSite = [];
                foreach ($matches as $site => $uid) {
                    $perSite[] = $site . ': ' . $uid;
                }
                throw new \InvalidArgumentException(sprintf(
                    'Ambiguous language code: "%s" stands for different language IDs on different sites (%s). '
                    . 'Narrow the request to a page of the site, or pass the numeric language ID.',
                    $value,
                    implode(', ', $perSite)
                ));
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'Unknown language code: %s%s. Valid languages: %s',
            $value,
            $siteIdentifier !== null ? ' for this site' : '',
            $this->describeLanguages($pageId)
        ));
    }

    /**
     * Identifier of a language UID, within the page's site when given,
     * otherwise the first site that has the language.
     */
    public function getIsoCodeFromUid(int $uid, ?int $pageId = null): ?string
    {
        $language = $this->findLanguage($uid, $pageId);
        return $language['identifier'] ?? null;
    }

    /**
     * Human-readable language reference, e.g. "de-AT (1, Österreich)".
     */
    public function formatLanguage(int $uid, ?int $pageId = null): string
    {
        $language = $this->findLanguage($uid, $pageId);
        if ($language === null) {
            return 'unknown (' . $uid . ')';
        }
        return sprintf('%s (%d, %s)', $language['identifier'], $uid, $language['title']);
    }

    /**
     * Identifiers of all languages on the sites the current backend user can
     * reach (all sites for admins and outside a backend context).
     *
     * @return string[]
     */
    public function getAvailableIsoCodes(): array
    {
        $this->initialize();

        $identifiers = [];
        foreach ($this->getReachableSiteIdentifiers() as $siteIdentifier) {
            foreach ($this->languagesBySite[$siteIdentifier] ?? [] as $language) {
                $identifiers[$language['identifier']] = true;
            }
        }
        return array_keys($identifiers);
    }

    /**
     * Identifier of the default language (language 0) of the first site
     */
    public function getDefaultIsoCode(): ?string
    {
        return $this->getIsoCodeFromUid(0);
    }

    /**
     * Identifiers mapped to language UIDs. An identifier that stands for
     * different UIDs on different sites maps to the UID of the first site.
     *
     * @return array<string, int>
     */
    public function getAllMappings(): array
    {
        $this->initialize();

        $mappings = [];
        foreach ($this->languagesBySite as $languages) {
            foreach ($languages as $uid => $language) {
                $mappings[$language['identifier']] ??= $uid;
            }
        }
        return $mappings;
    }

    /**
     * Check if a language identifier is known on any site
     */
    public function isIsoCodeAvailable(string $isoCode): bool
    {
        $this->initialize();

        return isset($this->identifierIndex[strtolower($isoCode)]);
    }

    /**
     * Language UIDs configured in the site of the page, or null when the page
     * belongs to no site.
     *
     * @return int[]|null
     */
    public function getLanguageUidsForPage(int $pageId): ?array
    {
        $this->initialize();

        $siteIdentifier = $this->getSiteIdentifierForPage($pageId);
        return $siteIdentifier !== null ? array_keys($this->languagesBySite[$siteIdentifier] ?? []) : null;
    }

    /**
     * Valid languages as "identifier (id, title)" list for error messages:
     * the page's site, or every reachable site without a page.
     */
    public function describeLanguages(?int $pageId = null): string
    {
        $this->initialize();

        $siteIdentifier = $this->getSiteIdentifierForPage($pageId);
        $siteIdentifiers = $siteIdentifier !== null ? [$siteIdentifier] : $this->getReachableSiteIdentifiers();
        $descriptions = [];
        foreach ($siteIdentifiers as $site) {
            foreach ($this->languagesBySite[$site] ?? [] as $uid => $language) {
                $description = sprintf('%s (%d, %s)', $language['identifier'], $uid, $language['title']);
                if ($siteIdentifier === null && count($siteIdentifiers) > 1) {
                    $description .= ' on site "' . $site . '"';
                }
                $descriptions[] = $description;
            }
        }
        return $descriptions === [] ? 'none' : implode(', ', $descriptions);
    }

    /**
     * Get language information for a specific page
     * This considers the site configuration for the given page
     *
     * @param int $pageId
     * @return array Array of language information
     */
    public function getLanguagesForPage(int $pageId): array
    {
        $this->initialize();

        $siteIdentifier = $this->getSiteIdentifierForPage($pageId);
        if ($siteIdentifier === null) {
            // Return all available languages if site not found
            return $this->getAllLanguageInfo();
        }

        $languages = [];
        foreach ($this->languagesBySite[$siteIdentifier] ?? [] as $language) {
            $languages[] = [
                'uid' => $language['uid'],
                'isoCode' => $language['identifier'],
                'title' => $language['title'],
                'locale' => (string)$language['locale'],
                'enabled' => $language['enabled'],
            ];
        }
        return $languages;
    }

    /**
     * Get information about all available languages
     *
     * @return array
     */
    public function getAllLanguageInfo(): array
    {
        $this->initialize();

        $languages = [];
        $seen = [];

        foreach ($this->languagesBySite as $siteLanguages) {
            foreach ($siteLanguages as $uid => $language) {
                // Skip if we've already processed this UID
                if (isset($seen[$uid])) {
                    continue;
                }
                $languages[] = [
                    'uid' => $uid,
                    'isoCode' => $language['identifier'],
                    'title' => $language['title'],
                    'locale' => $language['locale'],
                    'enabled' => $language['enabled'],
                ];
                $seen[$uid] = true;
            }
        }

        // Sort by UID to have consistent ordering
        usort($languages, function ($a, $b) {
            return $a['uid'] <=> $b['uid'];
        });

        return $languages;
    }

    /**
     * @return array{uid: int, identifier: string, isoCode: string, title: string, locale: mixed, enabled: bool}|null
     */
    protected function findLanguage(int $uid, ?int $pageId): ?array
    {
        $this->initialize();

        $siteIdentifier = $this->getSiteIdentifierForPage($pageId);
        if ($siteIdentifier !== null) {
            return $this->languagesBySite[$siteIdentifier][$uid] ?? null;
        }
        foreach ($this->languagesBySite as $languages) {
            if (isset($languages[$uid])) {
                return $languages[$uid];
            }
        }
        return null;
    }

    protected function isLanguageUidKnown(int $uid): bool
    {
        return $this->findLanguage($uid, null) !== null;
    }

    protected function getSiteIdentifierForPage(?int $pageId): ?string
    {
        if ($pageId === null || $pageId <= 0) {
            return null;
        }
        try {
            $identifier = $this->siteFinder->getSiteByPageId($pageId)->getIdentifier();
        } catch (SiteNotFoundException) {
            return null;
        }
        return isset($this->languagesBySite[$identifier]) ? $identifier : null;
    }

    /**
     * Sites the current backend user has a DB mount in. Admins, callers
     * without a backend user and users without any mount in a site get all
     * sites, so the tool schemas never end up without languages.
     *
     * @return string[]
     */
    protected function getReachableSiteIdentifiers(): array
    {
        $all = array_keys($this->languagesBySite);
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !is_array($backendUser->user) || $backendUser->isAdmin()) {
            return $all;
        }

        $reachable = [];
        $mounts = GeneralUtility::intExplode(',', (string)($backendUser->groupData['webmounts'] ?? ''), true);
        foreach ($mounts as $mount) {
            $siteIdentifier = $this->getSiteIdentifierForPage($mount);
            if ($siteIdentifier !== null) {
                $reachable[$siteIdentifier] = true;
            }
        }
        return $reachable !== [] ? array_values(array_intersect($all, array_keys($reachable))) : $all;
    }
}
