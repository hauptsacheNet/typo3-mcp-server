<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OAuth Authorization Server Metadata endpoint
 * RFC 8414: https://tools.ietf.org/html/rfc8414
 */
class OAuthAuthServerMetadataEndpoint
{
    use CorsHeadersTrait;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        $baseUrl = $this->getBaseUrl($request);
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        
        $metadata = $oauthService->getMetadata($baseUrl);
        
        return $this->createJsonResponse($metadata);
    }

    private function createJsonResponse(array $data): ResponseInterface
    {
        $response = new JsonResponse($data);
        
        // Add CORS headers
        $response = $this->addCorsHeaders($response);
        
        // Add cache headers (short cache for dynamic content)
        $response = $response
            ->withHeader('Cache-Control', 'public, max-age=300') // 5 minutes
            ->withHeader('Vary', 'Origin');
        
        return $response;
    }

    private function getBaseUrl(ServerRequestInterface $request): string
    {
        // Try to get from TYPO3 configuration first
        $baseUrl = $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] ?? '';
        
        if (empty($baseUrl)) {
            // Build from request
            $uri = $request->getUri();
            $scheme = $uri->getScheme();
            $host = $uri->getHost();
            $port = $uri->getPort();
            
            $baseUrl = $scheme . '://' . $host;
            if ($port && !in_array($port, [80, 443])) {
                $baseUrl .= ':' . $port;
            }
        }
        
        return rtrim($baseUrl, '/');
    }
}