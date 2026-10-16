<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OAuth Authorization Server Metadata endpoint
 * RFC 8414: https://tools.ietf.org/html/rfc8414
 */
class OAuthAuthServerMetadataEndpoint
{
    use CorsHeadersTrait;
    use RequestUrlTrait;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest($request);
        }

        $baseUrl = $this->getRequestBaseUrl($request);
        $oauthService = GeneralUtility::makeInstance(OAuthService::class);

        $metadata = $oauthService->getMetadata($baseUrl);

        return $this->createJsonResponse($metadata, $request);
    }

    private function createJsonResponse(array $data, ServerRequestInterface $request): ResponseInterface
    {
        $response = new JsonResponse($data);

        // Add CORS headers
        $response = $this->addCorsHeaders($response, $request);
        
        // Add cache headers (short cache for dynamic content)
        $response = $response
            ->withHeader('Cache-Control', 'public, max-age=300') // 5 minutes
            ->withHeader('Vary', 'Origin');
        
        return $response;
    }
}