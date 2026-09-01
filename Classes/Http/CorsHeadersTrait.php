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

        // Authentication uses Bearer tokens, not cookies, so credentialed CORS
        // is not needed. Reflecting an arbitrary Origin is only acceptable
        // because of that: combining it with Allow-Credentials would be
        // equivalent to the wildcard-with-credentials setup the CORS spec
        // forbids for a reason.
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            // The MCP Streamable HTTP transport requires several custom request
            // headers (session id, protocol version, SSE resumption, routing
            // headers) that are not CORS-safelisted and must be listed here or
            // browsers reject the preflight.
            ->withHeader(
                'Access-Control-Allow-Headers',
                'Content-Type, Content-Disposition, Authorization, X-Requested-With, Accept, '
                . 'MCP-Protocol-Version, Mcp-Session-Id, Mcp-Method, Mcp-Name, Last-Event-ID'
            )
            // The server hands out the session id as a response header on
            // initialize; without exposing it, browser clients can never read
            // and echo it back on subsequent requests.
            ->withHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id, MCP-Protocol-Version')
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