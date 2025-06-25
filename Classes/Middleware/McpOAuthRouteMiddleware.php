<?php

declare(strict_types=1);

namespace Hn\McpServer\Middleware;

use Hn\McpServer\Http\CorsHeadersTrait;
use Hn\McpServer\Http\OAuthAuthorizeEndpoint;
use Hn\McpServer\Http\OAuthTokenEndpoint;
use Hn\McpServer\Http\OAuthMetadataEndpoint;
use Hn\McpServer\Http\OAuthRegisterEndpoint;
use Hn\McpServer\Http\OAuthResourceMetadataEndpoint;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * PSR-15 Middleware to handle MCP OAuth routes without query parameters
 * Provides clean URLs like /mcp_oauth/authorize instead of /index.php?eID=...
 */
class McpOAuthRouteMiddleware implements MiddlewareInterface
{
    use CorsHeadersTrait;
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
        // Only handle MCP OAuth requests
        if (!str_starts_with($path, '/mcp_oauth/')) {
            return $handler->handle($request);
        }
        
        // Handle preflight OPTIONS requests for CORS
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }
        
        // Route to appropriate OAuth endpoint
        switch ($path) {
            case '/mcp_oauth/authorize':
                $endpoint = GeneralUtility::makeInstance(OAuthAuthorizeEndpoint::class);
                return $endpoint($request);
                
            case '/mcp_oauth/token':
                $endpoint = GeneralUtility::makeInstance(OAuthTokenEndpoint::class);
                return $endpoint($request);
                
            case '/mcp_oauth/metadata':
                $endpoint = GeneralUtility::makeInstance(OAuthMetadataEndpoint::class);
                return $endpoint($request);
                
            case '/mcp_oauth/register':
                $endpoint = GeneralUtility::makeInstance(OAuthRegisterEndpoint::class);
                return $endpoint($request);
                
            case '/mcp_oauth/resource':
                $endpoint = GeneralUtility::makeInstance(OAuthResourceMetadataEndpoint::class);
                return $endpoint($request);
                
            default:
                // Unknown MCP OAuth route, let normal handler deal with it (404)
                return $handler->handle($request);
        }
    }
}