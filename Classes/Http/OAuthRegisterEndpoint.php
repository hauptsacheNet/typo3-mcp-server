<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Exception\InvalidClientMetadataException;
use Hn\McpServer\Service\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\RateLimiter\Storage\CachingFrameworkStorage;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * OAuth Dynamic Client Registration endpoint
 */
class OAuthRegisterEndpoint
{
    use CorsHeadersTrait;
    use RequestBodyLimitTrait;

    /**
     * Client metadata (RFC 7591) is a handful of short strings and URLs. The
     * endpoint is reachable without authentication, so the body is bounded
     * before it is buffered or decoded.
     */
    private const MAX_BODY_BYTES = 131072;

    /**
     * Registrations allowed per client IP address. Every registration stores
     * a row, and anyone may register, so the number has to be bounded. A
     * client registers once when a connection is set up; the limit leaves
     * room for many users behind one address. It can be changed through
     * $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter']['mcp-oauth-register'].
     */
    private const RATE_LIMIT_LOCK_SLOTS = 16;

    private const RATE_LIMIT = [
        'id' => 'mcp-oauth-register',
        'policy' => 'sliding_window',
        'limit' => 20,
        'interval' => '15 minutes',
    ];

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

            // Before the body is read: a rejected request should cost nothing.
            $retryAfter = $this->consumeRateLimit($request);
            if ($retryAfter !== null) {
                return $this->createErrorResponse($request, 'too_many_requests', 'Too many client registrations, try again later', 429)
                    ->withHeader('Retry-After', (string)$retryAfter);
            }

            // Get request body
            $body = $this->readBodyWithLimit($request, self::MAX_BODY_BYTES);
            if ($body === null) {
                return $this->createErrorResponse($request, 'invalid_request', 'Request body too large', 413);
            }
            $clientData = json_decode($body, true, 32);

            // Note: an empty JSON object {} is a valid RFC 7591 request — every
            // metadata field has a server-side default — so only reject non-objects.
            if (!is_array($clientData)) {
                return $this->createErrorResponse($request, 'invalid_request', 'Invalid JSON in request body');
            }

            // Validate required fields (minimal validation for MCP)
            if (empty($clientData['client_name'])) {
                $clientData['client_name'] = 'MCP Client';
            }

            // redirect_uris is required by RFC 7591, but for MCP clients we can provide a default
            if (empty($clientData['redirect_uris']) || !is_array($clientData['redirect_uris'])) {
                $clientData['redirect_uris'] = ['http://localhost']; // Default for local MCP clients
            }

            // Set default values for MCP clients
            $clientData['grant_types'] = $clientData['grant_types'] ?? ['authorization_code'];
            $clientData['response_types'] = $clientData['response_types'] ?? ['code'];
            $clientData['scope'] = $clientData['scope'] ?? 'mcp_access';

            // Register the client
            $oauthService = GeneralUtility::makeInstance(OAuthService::class);
            try {
                $clientInfo = $oauthService->registerClient($clientData);
            } catch (InvalidClientMetadataException $e) {
                return $this->createErrorResponse($request, 'invalid_client_metadata', $e->getMessage());
            } catch (\InvalidArgumentException $e) {
                return $this->createErrorResponse($request, 'invalid_redirect_uri', $e->getMessage());
            }

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
            
            return $this->addCorsHeaders($response, $request);

        } catch (\Throwable $e) {
            return $this->createErrorResponse($request, 'server_error', $e->getMessage(), 500);
        }
    }

    /**
     * Count this registration against the limit of the client's IP address.
     *
     * @return int|null seconds to wait when the limit is exceeded, null when the request may proceed
     */
    private function consumeRateLimit(ServerRequestInterface $request): ?int
    {
        $config = array_replace(
            self::RATE_LIMIT,
            $GLOBALS['TYPO3_CONF_VARS']['SYS']['rateLimiter'][self::RATE_LIMIT['id']] ?? []
        );
        $normalizedParams = $request->getAttribute('normalizedParams') ?? NormalizedParams::createFromRequest($request);
        $remoteAddress = $normalizedParams->getRemoteAddress();

        // The limiter reads, decides and saves its state in separate steps.
        // Without a lock across PHP workers, parallel requests would each see
        // the same remaining allowance and could repeatedly exceed the limit.
        // The file lock strategy keeps one file per lock name, so addresses
        // share a fixed pool of locks instead of getting one each.
        $lockSlot = crc32($remoteAddress) % self::RATE_LIMIT_LOCK_SLOTS;
        $locker = GeneralUtility::makeInstance(LockFactory::class)
            ->createLocker('mcp-oauth-register-' . $lockSlot);
        $locker->acquire();
        try {
            $storage = new CachingFrameworkStorage(GeneralUtility::makeInstance(CacheManager::class));
            $limit = (new RateLimiterFactory($config, $storage))
                ->create($remoteAddress)
                ->consume();
        } finally {
            $locker->release();
        }
        if ($limit->isAccepted()) {
            return null;
        }

        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
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