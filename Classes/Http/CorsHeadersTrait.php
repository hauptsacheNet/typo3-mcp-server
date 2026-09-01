<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Mcp\Shared\McpHeaders;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Trait for adding CORS headers to HTTP responses
 */
trait CorsHeadersTrait
{
    /**
     * Request headers a browser client may send when no preflight lists them
     * explicitly. This is only a fallback: on a real preflight the requested
     * headers are reflected instead, because the MCP Streamable HTTP transport
     * also mirrors tool arguments as dynamic Mcp-Param-{name} headers
     * (SEP-2243), which no static list can ever cover.
     */
    private function corsFallbackAllowHeaders(): string
    {
        return implode(', ', [
            'Content-Type',
            'Content-Disposition',
            'Authorization',
            'X-Requested-With',
            'Accept',
            McpHeaders::PROTOCOL_VERSION,
            'Mcp-Session-Id',
            McpHeaders::METHOD,
            McpHeaders::NAME,
            'Last-Event-ID',
        ]);
    }

    /**
     * Add CORS headers to response for OAuth/API endpoints
     */
    private function addCorsHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        // The response content depends on these request headers, so shared
        // caches must key on them - even when the current request carries no
        // Origin at all, or a cached non-CORS response would later be served
        // to a CORS request (and vice versa).
        foreach (['Origin', 'Access-Control-Request-Headers'] as $varyOn) {
            if (stripos($response->getHeaderLine('Vary'), $varyOn) === false) {
                $response = $response->withAddedHeader('Vary', $varyOn);
            }
        }

        $allowedOrigin = $request->getHeaderLine('Origin');

        // No CORS headers for non-CORS requests (no Origin header)
        if (empty($allowedOrigin)) {
            return $response;
        }

        // On a preflight the browser lists exactly the headers the client
        // wants to send; reflecting that list keeps this trait free of
        // transport knowledge and covers the dynamic Mcp-Param-* headers.
        // Allow-Headers only gates the browser preflight - the server
        // validates every actual request itself, so reflecting is safe.
        $requestedHeaders = trim($request->getHeaderLine('Access-Control-Request-Headers'));
        if ($requestedHeaders === '') {
            $requestedHeaders = $this->corsFallbackAllowHeaders();
        }

        // Authentication uses Bearer tokens, not cookies, so credentialed CORS
        // is not needed. Reflecting an arbitrary Origin is only acceptable
        // because of that: combining it with Allow-Credentials would be
        // equivalent to the wildcard-with-credentials setup the CORS spec
        // forbids for a reason.
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', $requestedHeaders)
            // The server hands out the session id as a response header on
            // initialize; without exposing it, browser clients can never read
            // and echo it back on subsequent requests.
            ->withHeader('Access-Control-Expose-Headers', 'Mcp-Session-Id, ' . McpHeaders::PROTOCOL_VERSION)
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
