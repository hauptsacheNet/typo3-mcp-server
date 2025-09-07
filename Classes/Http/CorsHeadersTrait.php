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
        // Get the actual origin from the request, or use a safe default
        $allowedOrigin = $origin ?: $this->getAllowedOrigin();
        
        return $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', '86400'); // Cache preflight for 24 hours
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

        // Fallback for non-CORS requests
        return '*';
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