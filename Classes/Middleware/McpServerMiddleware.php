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
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Unified PSR-15 Middleware to handle all MCP Server routes
 * Provides clean URLs for MCP protocol, OAuth flow, and discovery endpoints
 */
class McpServerMiddleware implements MiddlewareInterface
{
    private Context $context;
    
    public function __construct(Context $context)
    {
        $this->context = $context;
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        
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
            
            // TYPO3 main page with OAuth continuation check
            '/typo3/main' => $this->handleOAuthCookieContinuation($request, $handler),
            
            // All other routes
            default => $handler->handle($request),
        };
    }
    
    /**
     * Handle OAuth cookie continuation after login
     */
    private function handleOAuthCookieContinuation(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        
        // Check if OAuth cookie exists
        if (!isset($cookies['tx_mcpserver_oauth'])) {
            return $handler->handle($request); // No OAuth cookie, handle normally
        }
        
        $cookieValue = $cookies['tx_mcpserver_oauth'];
        
        // Decode and validate OAuth data
        $oauthData = json_decode(base64_decode($cookieValue), true);
        if (!is_array($oauthData)) {
            return $handler->handle($request); // Invalid data, continue normal flow
        }
        
        // Check if user is now authenticated using Context API
        $backendUserAspect = $this->context->getAspect('backend.user');
        if (!$backendUserAspect->isLoggedIn()) {
            return $handler->handle($request); // User still not authenticated, continue normal flow
        }
        
        // User is authenticated, redirect back to OAuth authorization endpoint
        $queryParams = http_build_query([
            'client_id' => $oauthData['client_id'] ?? '',
            'client_name' => $oauthData['client_name'] ?? '',
            'redirect_uri' => $oauthData['redirect_uri'] ?? '',
            'code_challenge' => $oauthData['code_challenge'] ?? '',
            'code_challenge_method' => $oauthData['code_challenge_method'] ?? '',
            'state' => $oauthData['state'] ?? ''
        ]);
        
        $oauthAuthorizeUrl = '/mcp_oauth/authorize?' . $queryParams;
        
        $stream = new Stream('php://temp', 'rw');
        $stream->write('');
        $stream->rewind();
        
        return new Response(
            $stream,
            302,
            ['Location' => $oauthAuthorizeUrl]
        );
    }
}