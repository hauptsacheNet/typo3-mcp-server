<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Trait for adding CORS headers to HTTP responses
 */
trait CorsHeadersTrait
{
    /**
     * Add CORS headers to response for OAuth/API endpoints
     */
    private function addCorsHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $allowedOrigin = $request->hasHeader('Origin') ? $request->getHeaderLine('Origin') : '';

        // No CORS headers for non-CORS requests (no Origin header)
        if (empty($allowedOrigin)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            // DELETE is required by the MCP Streamable HTTP transport so a client
            // can terminate its session; PUT stays for the file upload endpoint.
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            // The MCP Streamable HTTP transport lets the client send Accept,
            // MCP-Protocol-Version, Mcp-Session-Id and Last-Event-ID; a browser
            // preflight is rejected unless the server allows each of them.
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Content-Disposition, Authorization, X-Requested-With, Accept, MCP-Protocol-Version, Mcp-Session-Id, Last-Event-ID')
            // Browser MCP clients read the session id (and the negotiated
            // protocol version) off the response; without Expose-Headers the
            // browser hides them, and the 401 needs WWW-Authenticate readable
            // for the OAuth discovery flow.
            ->withHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id, MCP-Protocol-Version, WWW-Authenticate')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    /**
     * Handle preflight OPTIONS requests
     */
    private function handlePreflightRequest(ServerRequestInterface $request): ResponseInterface
    {
        $response = new \TYPO3\CMS\Core\Http\Response();
        return $this->addCorsHeaders($response->withStatus(200), $request);
    }
}