<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Trait for adding CORS headers to HTTP responses
 */
trait CorsHeadersTrait
{
    /**
     * Add CORS headers to response for OAuth/API endpoints
     */
    private function addCorsHeaders(ResponseInterface $response, ?string $origin = null): ResponseInterface
    {
        $allowedOrigin = $origin ?: $this->getAllowedOrigin();

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
     * Get the allowed origin from the request
     */
    private function getAllowedOrigin(): string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request && $request->hasHeader('Origin')) {
            return $request->getHeaderLine('Origin');
        }

        // No Origin header = not a CORS request
        return '';
    }

    /**
     * Handle preflight OPTIONS requests
     */
    private function handlePreflightRequest(): ResponseInterface
    {
        $response = new \TYPO3\CMS\Core\Http\Response();
        return $this->addCorsHeaders($response->withStatus(200));
    }
}