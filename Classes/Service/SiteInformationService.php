<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Http\RequestUrlTrait;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for centralizing site and domain information
 */
class SiteInformationService
{
    use RequestUrlTrait;

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
     * Turn a possibly-relative URL into an absolute one.
     *
     * Order of preference: scheme/host of the active HTTP request, then the
     * first configured site whose base carries a host, then TYPO3's
     * TYPO3_REQUEST_HOST environment value. Returns the input unchanged when
     * none of those resolve a host (e.g. CLI/stdio without a configured site)
     * — better an honest relative URL than an invented one.
     *
     * @param string|null $url Anything from a relative path ("fileadmin/x.jpg")
     *                         to a leading-slash path ("/x.jpg") to an already
     *                         absolute URL.
     */
    public function makeAbsoluteUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }
        // Already absolute (http://, https://, data:, etc.)
        if (preg_match('#^[a-z][a-z0-9+\-.]*://#i', $url) || str_starts_with($url, '//')) {
            return $url;
        }

        $base = $this->resolveRequestOrSiteBase();
        if ($base === null) {
            return $url;
        }

        return $base . '/' . ltrim($url, '/');
    }

    /**
     * Returns "<scheme>://<host>" from the active request, or the first site
     * whose base has a host, or TYPO3_REQUEST_HOST as a last resort.
     */
    protected function resolveRequestOrSiteBase(): ?string
    {
        if ($this->currentRequest !== null) {
            // Same resolution as the HTTP endpoints: NormalizedParams-based,
            // which keeps non-standard ports, reverse proxy configuration and
            // subdirectory installations intact.
            $base = $this->getRequestBaseUrl($this->currentRequest);
            if (parse_url($base, PHP_URL_HOST) !== null) {
                return $base;
            }
        }

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                $siteBase = $site->getBase();
                if ($siteBase->getHost() !== '') {
                    $scheme = $siteBase->getScheme() ?: 'https';
                    $port = $siteBase->getPort();
                    return $scheme . '://' . $siteBase->getHost() . ($port ? ':' . $port : '');
                }
            }
        } catch (\Throwable) {
            // Ignore and fall through to the env-based fallback
        }

        $envHost = GeneralUtility::getIndpEnv('TYPO3_REQUEST_HOST');
        if (is_string($envHost) && $envHost !== '') {
            // TYPO3_REQUEST_HOST is "<scheme>://<host>" — accept only when the host
            // segment is actually populated, otherwise we'd build URLs like
            // "http:///fileadmin/x.jpg" in CLI/test contexts without a server name.
            $parts = parse_url($envHost);
            if (!empty($parts['host'])) {
                return $envHost;
            }
        }
        return null;
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

            // Domains of a decoupled frontend (EXT:headless): visitors know the site by these
            foreach ($this->getFrontendHosts($site) as $frontendHost) {
                $domains[] = $frontendHost;
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
     * Whether a URL host belongs to the site: its base, or the frontendBase of a
     * decoupled frontend (EXT:headless) that serves the same page tree.
     */
    public function isSiteHost(Site $site, string $host): bool
    {
        $baseHost = $site->getBase()->getHost();
        return $baseHost === '' || $baseHost === $host || in_array($host, $this->getFrontendHosts($site), true);
    }

    /**
     * Hosts of the `frontendBase` settings EXT:headless reads from the site
     * configuration: site-wide, per language and in their base variants.
     *
     * @return string[]
     */
    public function getFrontendHosts(Site $site): array
    {
        $configuration = $site->getConfiguration();
        $configurations = [$configuration, ...($configuration['baseVariants'] ?? [])];
        foreach ($configuration['languages'] ?? [] as $language) {
            $configurations[] = $language;
            foreach ($language['baseVariants'] ?? [] as $variant) {
                $configurations[] = $variant;
            }
        }

        $hosts = [];
        foreach ($configurations as $entry) {
            $frontendBase = trim((string)($entry['frontendBase'] ?? ''));
            $host = $frontendBase !== '' ? parse_url($frontendBase, PHP_URL_HOST) : null;
            if (is_string($host) && $host !== '') {
                $hosts[] = $host;
            }
        }
        return array_values(array_unique($hosts));
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
}
