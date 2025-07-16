<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for centralizing site and domain information
 */
class SiteInformationService
{
    protected SiteFinder $siteFinder;
    protected ?ServerRequestInterface $currentRequest = null;

    public function __construct()
    {
        $this->siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
    }

    /**
     * Set the current HTTP request for context
     */
    public function setCurrentRequest(?ServerRequestInterface $request): void
    {
        $this->currentRequest = $request;
    }

    /**
     * Get all configured domains from TYPO3 sites
     * 
     * @return array Array of unique domain names
     */
    public function getAllDomains(): array
    {
        $sites = $this->siteFinder->getAllSites();
        $domains = [];

        foreach ($sites as $site) {
            $base = $site->getBase();
            $host = $base->getHost();
            
            // Add main domain if it's not empty and not just a path
            if (!empty($host) && $host !== '/') {
                $domains[] = $host;
            }
            
            // Check if the site has base variants (method may not exist in all TYPO3 versions)
            if (method_exists($site, 'getBaseVariants')) {
                foreach ($site->getBaseVariants() ?? [] as $variant) {
                    $variantHost = $variant->getBase()->getHost();
                    if (!empty($variantHost) && $variantHost !== '/' && !in_array($variantHost, $domains)) {
                        $domains[] = $variantHost;
                    }
                }
            }
        }

        // If no domains found but we have a current request, try to use the host header
        if (empty($domains) && $this->currentRequest !== null) {
            $host = $this->currentRequest->getHeaderLine('Host');
            if (!empty($host)) {
                $domains[] = $host;
            }
        }

        return array_unique($domains);
    }

    /**
     * Generate URL for a page with multiple fallback strategies
     * 
     * @param int $pageId The page ID
     * @param int $languageId The language ID (default: 0)
     * @return string|null The generated URL or null if generation fails
     */
    public function generatePageUrl(int $pageId, int $languageId = 0): ?string
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            
            if ($site instanceof Site) {
                // Get the appropriate language
                try {
                    $language = $languageId > 0 ? $site->getLanguageById($languageId) : $site->getDefaultLanguage();
                } catch (\Throwable $e) {
                    // Fall back to default language if specified language not found
                    $language = $site->getDefaultLanguage();
                }
                
                // Generate the URI
                $uri = $site->getRouter()->generateUri($pageId, ['_language' => $language]);
                $generatedUrl = (string)$uri;
                
                // Check if the generated URL is missing a host (e.g., just a path like "/page")
                if (!empty($generatedUrl) && strpos($generatedUrl, 'http') !== 0) {
                    // Try to add host from site configuration
                    $host = $site->getBase()->getHost();
                    
                    // If site base is just "/" or empty, try to get host from current request
                    if (empty($host) || $host === '/') {
                        $host = $this->getHostFromRequest();
                    }
                    
                    if (!empty($host)) {
                        // Determine scheme
                        $scheme = 'https';
                        if ($this->currentRequest !== null) {
                            $scheme = $this->currentRequest->getUri()->getScheme() ?: 'https';
                        }
                        
                        // Build full URL
                        $generatedUrl = $scheme . '://' . $host . $generatedUrl;
                    }
                }
                
                return $generatedUrl;
            }
        } catch (\Throwable $e) {
            // Log error but continue to fallback strategies
            // error_log('SiteInformationService: Error generating URL for page ' . $pageId . ': ' . $e->getMessage());
        }
        
        // Fallback: Try to get page record and build URL from slug
        try {
            $page = $this->getPageRecord($pageId);
            if ($page && !empty($page['slug'])) {
                $host = $this->getHostFromRequest();
                if (!empty($host)) {
                    $scheme = 'https';
                    if ($this->currentRequest !== null) {
                        $scheme = $this->currentRequest->getUri()->getScheme() ?: 'https';
                    }
                    return $scheme . '://' . $host . $page['slug'];
                }
                
                // If no host available, return just the slug
                return $page['slug'];
            }
        } catch (\Throwable $e) {
            // Ignore and return null
        }
        
        return null;
    }

    /**
     * Get formatted text listing all available domains for tool descriptions
     * 
     * @return string Formatted text describing available domains
     */
    public function getAvailableDomainsText(): string
    {
        $domains = $this->getAllDomains();
        
        if (empty($domains)) {
            return 'No specific domains configured. Use page IDs or relative paths.';
        }
        
        if (count($domains) === 1) {
            return 'Available domain: ' . $domains[0];
        }
        
        return 'Available domains: ' . implode(', ', $domains);
    }

    /**
     * Get host from current request
     * 
     * @return string|null
     */
    protected function getHostFromRequest(): ?string
    {
        if ($this->currentRequest === null) {
            return null;
        }
        
        // Try Host header first
        $host = $this->currentRequest->getHeaderLine('Host');
        if (!empty($host)) {
            return $host;
        }
        
        // Try to get from URI
        $uri = $this->currentRequest->getUri();
        $host = $uri->getHost();
        if (!empty($host)) {
            return $host;
        }
        
        return null;
    }

    /**
     * Get page record by ID
     * 
     * @param int $pageId
     * @return array|null
     */
    protected function getPageRecord(int $pageId): ?array
    {
        $connectionPool = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');
        
        $page = $queryBuilder
            ->select('uid', 'slug', 'title')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->executeQuery()
            ->fetchAssociative();
            
        return $page ?: null;
    }
}