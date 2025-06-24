<?php

declare(strict_types=1);

namespace Hn\McpServer\Middleware;

use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PSR-15 Middleware to handle OAuth .well-known discovery endpoints dynamically
 * This eliminates the need for static files with hardcoded domains
 */
class OAuthWellKnownMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
        // Only handle OAuth .well-known requests
        if (!$this->isOAuthWellKnownRequest($path)) {
            return $handler->handle($request);
        }
        
        // Generate dynamic response based on the requested endpoint
        if ($path === '/.well-known/oauth-authorization-server') {
            return $this->createAuthorizationServerResponse($request);
        }
        
        if ($path === '/.well-known/oauth-protected-resource') {
            return $this->createProtectedResourceResponse($request);
        }
        
        // If we get here, it's an unknown .well-known/oauth-* request
        return $handler->handle($request);
    }
    
    /**
     * Check if this is an OAuth .well-known request we should handle
     */
    private function isOAuthWellKnownRequest(string $path): bool
    {
        return str_starts_with($path, '/.well-known/oauth-');
    }
    
    /**
     * Create OAuth Authorization Server Metadata response
     * RFC 8414: https://tools.ietf.org/html/rfc8414
     */
    private function createAuthorizationServerResponse(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = $this->getBaseUrl($request);
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);
        
        $metadata = $oauthService->getMetadata($baseUrl);
        
        return $this->createJsonResponse($metadata, $request);
    }
    
    /**
     * Create OAuth Protected Resource Metadata response
     * RFC 8707: https://tools.ietf.org/html/rfc8707
     */
    private function createProtectedResourceResponse(ServerRequestInterface $request): ResponseInterface
    {
        $baseUrl = $this->getBaseUrl($request);
        
        $metadata = [
            'resource' => $baseUrl . '/index.php?eID=mcp_server',
            'authorization_servers' => [$baseUrl],
            'bearer_methods_supported' => ['header', 'query'],
            'resource_documentation' => $baseUrl . '/typo3/module/user/mcp-server',
            'revocation_endpoint' => $baseUrl . '/index.php?eID=mcp_server_oauth_token',
            'revocation_endpoint_auth_methods_supported' => ['none']
        ];
        
        return $this->createJsonResponse($metadata, $request);
    }
    
    /**
     * Create JSON response with proper headers including CORS
     */
    private function createJsonResponse(array $data, ServerRequestInterface $request): ResponseInterface
    {
        $response = new JsonResponse($data);
        
        // Add CORS headers for browser access
        $response = $this->addCorsHeaders($response, $request);
        
        // Add cache headers (short cache for dynamic content)
        $response = $response
            ->withHeader('Cache-Control', 'public, max-age=300') // 5 minutes
            ->withHeader('Vary', 'Origin');
        
        return $response;
    }
    
    /**
     * Add CORS headers for browser access
     */
    private function addCorsHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        // Get allowed origin
        $allowedOrigin = $this->getAllowedOrigin($request);
        
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', '86400');
    }
    
    /**
     * Get allowed CORS origin from request
     */
    private function getAllowedOrigin(ServerRequestInterface $request): string
    {
        $origin = $request->getHeaderLine('Origin');
        
        // Allow specific known origins (like MCP Inspector)
        $allowedOrigins = [
            'http://localhost:6274',  // MCP Inspector
            'http://localhost:3000',  // Common dev port
            'http://localhost:8080',  // Common dev port
            'http://127.0.0.1:6274',  // Alternative localhost
        ];
        
        if (!empty($origin) && in_array($origin, $allowedOrigins)) {
            return $origin;
        }
        
        // Fallback to request base URL for same-origin requests
        return $this->getBaseUrl($request);
    }
    
    /**
     * Get base URL from request for building dynamic URLs
     */
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