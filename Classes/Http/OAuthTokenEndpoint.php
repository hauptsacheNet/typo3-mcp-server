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
 * OAuth token endpoint for exchanging authorization codes for access tokens
 */
class OAuthTokenEndpoint
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

            $parsedBody = $request->getParsedBody() ?: [];
            
            // Extract parameters (support both form data and JSON)
            $grantType = $parsedBody['grant_type'] ?? '';
            $code = $parsedBody['code'] ?? '';
            $clientId = $parsedBody['client_id'] ?? '';
            $codeVerifier = $parsedBody['code_verifier'] ?? null;

            // Validate required parameters
            if ($grantType !== 'authorization_code') {
                return $this->createErrorResponse('unsupported_grant_type', 'Only authorization_code grant type is supported');
            }

            if (empty($code)) {
                return $this->createErrorResponse('invalid_request', 'Missing required parameter: code');
            }

            if (empty($clientId) || $clientId !== 'typo3-mcp-server') {
                return $this->createErrorResponse('invalid_client', 'Invalid client_id');
            }

            // Exchange code for token
            $oauthService = GeneralUtility::makeInstance(OAuthService::class);
            $tokenData = $oauthService->exchangeCodeForToken($code, $codeVerifier);

            if (!$tokenData) {
                return $this->createErrorResponse('invalid_grant', 'Invalid or expired authorization code');
            }

            // Return token response
            $stream = new Stream('php://temp', 'rw');
            $stream->write(json_encode($tokenData));
            $stream->rewind();

            $response = new Response(
                $stream,
                200,
                ['Content-Type' => 'application/json']
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