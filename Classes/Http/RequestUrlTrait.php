<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * Resolves absolute base URLs and the site path prefix from the request.
 *
 * Using NormalizedParams ensures the MCP, OAuth and discovery endpoints
 * generate correct URLs and route correctly when TYPO3 is installed in a
 * subdirectory / behind a path prefix (e.g. https://example.com/subdir/).
 */
trait RequestUrlTrait
{
    /**
     * Absolute base URL including the site path prefix, without a trailing slash.
     *
     * Honors the reverseProxyBaseUrl configuration when set.
     */
    protected function getRequestBaseUrl(ServerRequestInterface $request): string
    {
        $reverseProxyBaseUrl = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] ?? '');
        if ($reverseProxyBaseUrl !== '') {
            return rtrim($reverseProxyBaseUrl, '/');
        }

        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return rtrim($normalizedParams->getSiteUrl(), '/');
        }

        // Fallback when normalizedParams are unavailable
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();
        if ($port && !in_array($port, [80, 443], true)) {
            $baseUrl .= ':' . $port;
        }

        return $baseUrl;
    }

    /**
     * Path prefix of the TYPO3 installation without a trailing slash.
     *
     * Returns an empty string for a root-level installation.
     */
    protected function getRequestSitePath(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');
        if ($normalizedParams instanceof NormalizedParams) {
            return rtrim($normalizedParams->getSitePath(), '/');
        }

        return '';
    }
}
