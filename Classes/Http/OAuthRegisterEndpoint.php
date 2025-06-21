<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OAuth Dynamic Client Registration endpoint
 */
class OAuthRegisterEndpoint
{
    use CorsHeadersTrait;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        try {
            // Only accept POST requests
            if ($request->getMethod() !== 'POST') {
                return $this->createErrorResponse('invalid_request', 'Method not allowed', 405);
            }

            // Get request body
            $body = $request->getBody()->getContents();
            $clientData = json_decode($body, true);

            if (!$clientData) {
                return $this->createErrorResponse('invalid_request', 'Invalid JSON in request body');
            }

            // Validate required fields (minimal validation for MCP)
            if (empty($clientData['client_name'])) {
                $clientData['client_name'] = 'MCP Client';
            }

            // Set default values for MCP clients
            $clientData['grant_types'] = $clientData['grant_types'] ?? ['authorization_code'];
            $clientData['response_types'] = $clientData['response_types'] ?? ['code'];
            $clientData['scope'] = $clientData['scope'] ?? 'mcp_access';

            // Register the client
            $oauthService = GeneralUtility::makeInstance(OAuthService::class);
            $clientInfo = $oauthService->registerClient($clientData);

            // Return client registration response
            $stream = new Stream('php://temp', 'rw');
            $stream->write(json_encode($clientInfo));
            $stream->rewind();

            $response = new Response(
                $stream,
                201, // Created
                [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-store',
                    'Pragma' => 'no-cache'
                ]
            );
            
            return $this->addCorsHeaders($response);

        } catch (\Throwable $e) {
            return $this->createErrorResponse('server_error', $e->getMessage(), 500);
        }
    }

    private function createErrorResponse(string $error, string $description = '', int $statusCode = 400): ResponseInterface
    {
        $errorData = [
            'error' => $error,
            'error_description' => $description
        ];

        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($errorData));
        $stream->rewind();

        $response = new Response(
            $stream,
            $statusCode,
            ['Content-Type' => 'application/json']
        );
        
        return $this->addCorsHeaders($response);
    }
}