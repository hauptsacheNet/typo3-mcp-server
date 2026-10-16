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
            return $this->handlePreflightRequest($request);
        }

        try {
            // Only accept POST requests
            if ($request->getMethod() !== 'POST') {
                return $this->createErrorResponse($request, 'invalid_request', 'Method not allowed', 405);
            }

            $parsedBody = $request->getParsedBody() ?: [];

            // Extract parameters (support both form data and JSON)
            $grantType = $parsedBody['grant_type'] ?? '';
            $code = $parsedBody['code'] ?? '';
            $clientId = $parsedBody['client_id'] ?? '';
            $clientSecret = $parsedBody['client_secret'] ?? null;
            $codeVerifier = $parsedBody['code_verifier'] ?? null;
            $redirectUri = $parsedBody['redirect_uri'] ?? null;

            // RFC 6749 §2.3.1: clients registered with client_secret_basic send their
            // credentials via HTTP Basic auth instead of the request body.
            $basicAuth = $this->extractBasicAuthCredentials($request);
            if ($basicAuth !== null) {
                $clientId = $basicAuth['client_id'];
                $clientSecret = $basicAuth['client_secret'];
            }

            // Validate required parameters
            if ($grantType !== 'authorization_code') {
                return $this->createErrorResponse($request, 'unsupported_grant_type', 'Only authorization_code grant type is supported');
            }

            if (empty($code)) {
                return $this->createErrorResponse($request, 'invalid_request', 'Missing required parameter: code');
            }

            $oauthService = GeneralUtility::makeInstance(OAuthService::class);
            $client = $oauthService->getClient((string)$clientId);
            if ($client === null) {
                return $this->createErrorResponse($request, 'invalid_client', 'Unknown client_id');
            }
            if (!$oauthService->verifyClientSecret($client, $clientSecret)) {
                return $this->createErrorResponse($request, 'invalid_client', 'Invalid client_secret');
            }

            // Exchange code for token (also enforces redirect_uri match per RFC 6749 §4.1.3
            // and code-to-client binding per RFC 6749 §10.5)
            $tokenData = $oauthService->exchangeCodeForToken($code, $codeVerifier, $request, $redirectUri, $client['client_id']);

            if (!$tokenData) {
                return $this->createErrorResponse($request, 'invalid_grant', 'Invalid or expired authorization code');
            }

            // Log successful token exchange for debugging
            error_log("OAuth: Successfully exchanged code for token: " . substr($tokenData['access_token'], 0, 20) . "...");
            
            // Return token response
            $stream = new Stream('php://temp', 'rw');
            $stream->write(json_encode($tokenData));
            $stream->rewind();

            $response = new Response(
                $stream,
                200,
                ['Content-Type' => 'application/json']
            );
            
            return $this->addCorsHeaders($response, $request);

        } catch (\Throwable $e) {
            return $this->createErrorResponse($request, 'server_error', $e->getMessage(), 500);
        }
    }

    /**
     * Parse HTTP Basic client authentication (RFC 6749 §2.3.1).
     * Both client_id and client_secret are form-urlencoded before being
     * concatenated and base64-encoded, so they must be decoded here.
     *
     * @return array{client_id: string, client_secret: string}|null
     */
    private function extractBasicAuthCredentials(ServerRequestInterface $request): ?array
    {
        $header = trim($request->getHeaderLine('Authorization'));
        if (stripos($header, 'Basic ') !== 0) {
            return null;
        }
        $decoded = base64_decode(substr($header, 6), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }
        [$clientId, $clientSecret] = explode(':', $decoded, 2);
        return [
            'client_id' => urldecode($clientId),
            'client_secret' => urldecode($clientSecret),
        ];
    }

    private function createErrorResponse(ServerRequestInterface $request, string $error, string $description = '', int $statusCode = 400): ResponseInterface
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
        
        return $this->addCorsHeaders($response, $request);
    }
}