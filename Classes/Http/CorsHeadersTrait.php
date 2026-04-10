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
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
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