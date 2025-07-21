<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for handling language mappings between ISO codes and UIDs
 */
class LanguageService implements SingletonInterface
{
    protected SiteFinder $siteFinder;
    
    /**
     * Cached mapping of ISO codes to language UIDs
     * @var array<string, int>
     */
    protected array $isoToUidMap = [];
    
    /**
     * Cached mapping of language UIDs to ISO codes
     * @var array<int, string>
     */
    protected array $uidToIsoMap = [];
    
    /**
     * Default language ISO code
     */
    protected ?string $defaultIsoCode = null;
    
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

        $sites = $this->siteFinder->getAllSites();
        
        foreach ($sites as $site) {
            $languages = $site->getAllLanguages();
            
            foreach ($languages as $language) {
                $uid = $language->getLanguageId();
                $isoCode = $this->extractIsoCode($language);
                
                if ($isoCode !== null) {
                    // Store the mapping (first occurrence wins if there are conflicts)
                    if (!isset($this->isoToUidMap[$isoCode])) {
                        $this->isoToUidMap[$isoCode] = $uid;
                    }
                    if (!isset($this->uidToIsoMap[$uid])) {
                        $this->uidToIsoMap[$uid] = $isoCode;
                    }
                    
                    // Set default language ISO code
                    if ($uid === 0 && $this->defaultIsoCode === null) {
                        $this->defaultIsoCode = $isoCode;
                    }
                }
            }
        }
        
        $this->initialized = true;
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
     * Get language UID from ISO code
     * 
     * @param string $isoCode Two-letter ISO code (e.g., 'en', 'de')
     * @return int|null Language UID or null if not found
     */
    public function getUidFromIsoCode(string $isoCode): ?int
    {
        $this->initialize();
        
        $isoCode = strtolower($isoCode);
        return $this->isoToUidMap[$isoCode] ?? null;
    }

    /**
     * Get ISO code from language UID
     * 
     * @param int $uid Language UID
     * @return string|null ISO code or null if not found
     */
    public function getIsoCodeFromUid(int $uid): ?string
    {
        $this->initialize();
        
        return $this->uidToIsoMap[$uid] ?? null;
    }

    /**
     * Get all available language ISO codes
     * 
     * @return array Array of ISO codes
     */
    public function getAvailableIsoCodes(): array
    {
        $this->initialize();
        
        return array_keys($this->isoToUidMap);
    }

    /**
     * Get default language ISO code
     * 
     * @return string|null Default language ISO code
     */
    public function getDefaultIsoCode(): ?string
    {
        $this->initialize();
        
        return $this->defaultIsoCode;
    }

    /**
     * Get all language mappings
     * 
     * @return array Array with ISO codes as keys and UIDs as values
     */
    public function getAllMappings(): array
    {
        $this->initialize();
        
        return $this->isoToUidMap;
    }

    /**
     * Check if a language ISO code is available
     * 
     * @param string $isoCode
     * @return bool
     */
    public function isIsoCodeAvailable(string $isoCode): bool
    {
        $this->initialize();
        
        return isset($this->isoToUidMap[strtolower($isoCode)]);
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
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            $languages = [];
            
            foreach ($site->getAllLanguages() as $language) {
                $isoCode = $this->extractIsoCode($language);
                if ($isoCode !== null) {
                    $languages[] = [
                        'uid' => $language->getLanguageId(),
                        'isoCode' => $isoCode,
                        'title' => $language->getTitle(),
                        'locale' => (string)$language->getLocale(),
                        'enabled' => $language->isEnabled(),
                    ];
                }
            }
            
            return $languages;
        } catch (SiteNotFoundException $e) {
            // Return all available languages if site not found
            return $this->getAllLanguageInfo();
        }
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
        
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $uid = $language->getLanguageId();
                
                // Skip if we've already processed this UID
                if (isset($seen[$uid])) {
                    continue;
                }
                
                $isoCode = $this->extractIsoCode($language);
                if ($isoCode !== null) {
                    $languages[] = [
                        'uid' => $uid,
                        'isoCode' => $isoCode,
                        'title' => $language->getTitle(),
                        'locale' => $language->getLocale(),
                        'enabled' => $language->isEnabled(),
                    ];
                    $seen[$uid] = true;
                }
            }
        }
        
        // Sort by UID to have consistent ordering
        usort($languages, function ($a, $b) {
            return $a['uid'] <=> $b['uid'];
        });
        
        return $languages;
    }
}