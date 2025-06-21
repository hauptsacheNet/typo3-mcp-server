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
    private function addCorsHeaders(ResponseInterface $response): ResponseInterface
    {
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Max-Age', '86400'); // Cache preflight for 24 hours
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