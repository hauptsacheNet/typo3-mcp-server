<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;

/**
 * OAuth Resource Server Metadata endpoint
 * RFC 8707: https://tools.ietf.org/html/rfc8707
 */
class OAuthResourceMetadataEndpoint
{
    use CorsHeadersTrait;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        // Get base URL from request
        $baseUrl = $this->getBaseUrl($request);
        
        $metadata = [
            'resource' => $baseUrl . '/mcp',
            'authorization_servers' => [
                $baseUrl
            ],
            'bearer_methods_supported' => [
                'header',
                'query'
            ],
            'resource_documentation' => $baseUrl . '/typo3/module/user/mcp-server',
            'revocation_endpoint' => $baseUrl . '/mcp_oauth/token',
            'revocation_endpoint_auth_methods_supported' => [
                'none'
            ]
        ];

        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $stream->rewind();

        $response = new Response(
            $stream,
            200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'public, max-age=3600'
            ]
        );
        
        return $this->addCorsHeaders($response);
    }
    
    
    private function getBaseUrl(ServerRequestInterface $request): string
    {
        $scheme = $request->getUri()->getScheme();
        $host = $request->getUri()->getHost();
        $port = $request->getUri()->getPort();
        
        $baseUrl = $scheme . '://' . $host;
        if ($port && !in_array($port, [80, 443])) {
            $baseUrl .= ':' . $port;
        }
        
        return $baseUrl;
    }
}