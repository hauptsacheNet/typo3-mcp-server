<?php

declare(strict_types=1);

namespace Hn\McpServer\Middleware;

use Hn\McpServer\Http\CorsHeadersTrait;
use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Http\OAuthMetadataEndpoint;
use Hn\McpServer\Http\OAuthRegisterEndpoint;
use Hn\McpServer\Http\OAuthResourceMetadataEndpoint;
use Hn\McpServer\Http\OAuthAuthServerMetadataEndpoint;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Unified PSR-15 Middleware to handle all MCP Server routes
 * Provides clean URLs for MCP protocol, OAuth flow, and discovery endpoints
 */
class McpServerMiddleware implements MiddlewareInterface
{
    use CorsHeadersTrait;
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
        // Handle preflight OPTIONS requests for CORS
        if ($request->getMethod() === 'OPTIONS') {
            if ($this->isHandledPath($path)) {
                return $this->handlePreflightRequest();
            }
        }
        
        // Route to appropriate endpoint
        return match($path) {
            // Main MCP endpoint
            '/mcp' => GeneralUtility::makeInstance(McpEndpoint::class)($request),
            
            // OAuth endpoints
            '/mcp_oauth/authorize' => GeneralUtility::makeInstance(OAuthAuthorizeEndpoint::class)($request),
            '/mcp_oauth/token' => GeneralUtility::makeInstance(OAuthTokenEndpoint::class)($request),
            '/mcp_oauth/metadata' => GeneralUtility::makeInstance(OAuthMetadataEndpoint::class)($request),
            '/mcp_oauth/register' => GeneralUtility::makeInstance(OAuthRegisterEndpoint::class)($request),
            '/mcp_oauth/resource' => GeneralUtility::makeInstance(OAuthResourceMetadataEndpoint::class)($request),
            
            // OAuth discovery endpoints (.well-known)
            '/.well-known/oauth-authorization-server' => GeneralUtility::makeInstance(OAuthAuthServerMetadataEndpoint::class)($request),
            '/.well-known/oauth-protected-resource' => GeneralUtility::makeInstance(OAuthResourceMetadataEndpoint::class)($request),
            
            // Not our route, pass to next middleware
            default => $handler->handle($request),
        };
    }
    
    /**
     * Check if this path is handled by our middleware
     */
    private function isHandledPath(string $path): bool
    {
        return $path === '/mcp' 
            || str_starts_with($path, '/mcp_oauth/') 
            || str_starts_with($path, '/.well-known/oauth-');
    }
}