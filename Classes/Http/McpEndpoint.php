<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Service\WorkspaceContextService;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpMessage;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * MCP HTTP Endpoint for remote access
 */
class McpEndpoint
{
    use CorsHeadersTrait;
    use RequestUrlTrait;

    /**
     * Fallback for the sessionTimeout extension setting, in seconds.
     */
    private const DEFAULT_SESSION_TIMEOUT = 14400;

    /**
     * Header and query parameter names whose values must never reach a log file.
     *
     * Access tokens are handed out with a 30 day lifetime, so a single logged
     * request is enough to leak a long lived credential to everyone who can read
     * - or forward - the log.
     */
    private const CREDENTIAL_KEYS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'token',
        'access_token',
        'refresh_token',
        'client_secret',
    ];

    /**
     * Whether the endpoint may write its diagnostic output to the log.
     *
     * The endpoint is polled by connector proxies in a retry loop, so logging
     * every request unconditionally fills a production log with entries nobody
     * asked for and nobody reads. Diagnostics are therefore limited to the
     * Development context, which is also where they are actually needed.
     */
    private function isDebugLoggingEnabled(): bool
    {
        return Environment::getContext()->isDevelopment();
    }

    /**
     * Write a diagnostic message, unless the context says otherwise.
     */
    private function logDebug(string $message): void
    {
        if (!$this->isDebugLoggingEnabled()) {
            return;
        }

        error_log($message);
    }

    /**
     * Replace credential values with a marker while keeping the key itself, so the
     * log still shows which headers and parameters a client actually sent.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function redactCredentials(array $values): array
    {
        foreach ($values as $key => $value) {
            if (in_array(strtolower((string)$key), self::CREDENTIAL_KEYS, true)) {
                $values[$key] = '***redacted***';
            }
        }

        return $values;
    }

    /**
     * eID entry point via __invoke method
     */
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // Get services through DI container
            $container = GeneralUtility::getContainer();
            $serverFactory = $container->get(McpServerFactory::class);

            $queryParams = $request->getQueryParams();

            // Debug: Log all request details
            if ($this->isDebugLoggingEnabled()) {
                $requestHeaders = [];
                foreach ($request->getHeaders() as $name => $values) {
                    $requestHeaders[$name] = implode(', ', $values);
                }

                error_log("MCP: Request method: " . $request->getMethod());
                error_log("MCP: Request headers: " . json_encode($this->redactCredentials($requestHeaders)));
                error_log("MCP: Query params: " . json_encode($this->redactCredentials($queryParams)));
            }

            // Check if this is an auth header test request
            if (isset($queryParams['test']) && $queryParams['test'] === 'auth') {
                return $this->handleAuthHeaderTest($request);
            }

            // Authenticate via Bearer token or query parameter
            $token = $this->extractToken($request);

            if (!$token) {
                $this->logDebug("MCP: No token found in Authorization header or query params");
                return $this->createUnauthorizedResponse('Missing authentication token', $request);
            }

            // Log authentication status without exposing token material
            $this->logDebug('MCP: Received authentication token');

            $oauthService = GeneralUtility::makeInstance(OAuthService::class);
            $tokenInfo = $oauthService->validateToken($token, $request);

            if (!$tokenInfo) {
                $this->logDebug('MCP: Authentication token validation failed');
                return $this->createUnauthorizedResponse('Invalid or expired token', $request);
            }

            $this->logDebug("MCP: Token validation successful for user: " . $tokenInfo['be_user_uid']);

            // Set up TYPO3 backend context for the authenticated user
            $this->setupBackendUserContext($tokenInfo['be_user_uid']);

            // Set current request context in SiteInformationService
            $siteInformationService = $container->get(SiteInformationService::class);
            if ($siteInformationService instanceof SiteInformationService) {
                $siteInformationService->setCurrentRequest($request);
            }

            // Create MCP server instance using the factory
            $server = $serverFactory->createServer();

            // Configure HTTP options
            $httpOptions = [
                'session_timeout' => $this->getSessionTimeout(),
                'max_queue_size' => 500,
                'enable_sse' => false,
                'shared_hosting' => false,
            ];

            // Create session store in TYPO3's var directory
            $sessionStore = new FileSessionStore(
                Environment::getVarPath() . '/mcp_sessions'
            );

            // Create initialization options using the factory
            $initOptions = $serverFactory->createInitializationOptions($server);

            // Create runner and adapter
            $runner = new HttpServerRunner(
                $server,
                $initOptions,
                $httpOptions,
                null,
                $sessionStore
            );

            // Convert the PSR-7 request into the SDK's HttpMessage and let the
            // runner handle it directly. This keeps the whole request/response
            // cycle inside PSR-7 (no superglobals, no output buffering), which
            // also makes the endpoint testable in functional tests.
            $mcpRequest = new HttpMessage((string)$request->getBody());
            $mcpRequest->setMethod($request->getMethod());
            $mcpRequest->setUri((string)$request->getUri());
            $mcpRequest->setQueryParams($request->getQueryParams());
            foreach ($request->getHeaders() as $name => $values) {
                $mcpRequest->setHeader($name, implode(', ', $values));
            }

            $mcpResponse = $runner->handleRequest($mcpRequest);

            $stream = new Stream('php://temp', 'rw');
            $stream->write((string)($mcpResponse->getBody() ?? ''));
            $stream->rewind();

            $headers = $mcpResponse->getHeaders();
            if (!isset($headers['content-type'])) {
                $headers['content-type'] = 'application/json';
            }

            $response = new Response(
                $stream,
                $mcpResponse->getStatusCode(),
                $headers
            );

            return $this->addCorsHeaders($response, $request);

        } catch (\Throwable $e) {
            // Deliberately not behind the debug switch: this catch-all is the only
            // place where an exception from the MCP layer becomes visible at all,
            // and handing it to the client alone means it is gone the moment the
            // client discards the 500.
            error_log(sprintf(
                'MCP: Unhandled %s at %s:%d - %s%s%s',
                get_class($e),
                $e->getFile(),
                $e->getLine(),
                $e->getMessage(),
                PHP_EOL,
                $e->getTraceAsString()
            ));

            $stream = new Stream('php://temp', 'rw');
            $stream->write(json_encode([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ]));
            $stream->rewind();

            $response = new Response(
                $stream,
                500,
                ['Content-Type' => 'application/json']
            );

            return $this->addCorsHeaders($response, $request);
        }
    }

    /**
     * How long an idle MCP session is kept alive on the server, in seconds.
     *
     * Only clients using the session-based lifecycle (protocol revisions
     * before 2026-07-28) are affected: once their session expired, the next
     * request is answered with 404 and they have to start over. How well a
     * client recovers from that varies - mcp-remote, for example, does not
     * re-initialize, so the tool call appears to hang. Session files are
     * tiny, so keeping them around longer costs next to nothing.
     */
    private function getSessionTimeout(): int
    {
        try {
            $timeout = (int)GeneralUtility::makeInstance(ExtensionConfiguration::class)
                ->get('mcp_server', 'sessionTimeout');
        } catch (\Exception) {
            // Extension configuration not synchronized yet
            $timeout = 0;
        }

        return $timeout > 0 ? $timeout : self::DEFAULT_SESSION_TIMEOUT;
    }

    /**
     * Extract token from request (Bearer header or query parameter)
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        // Try Authorization header first (preferred method)
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader) && preg_match('/Bearer\s+(.+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        // Try HTTP_AUTHORIZATION from Apache environment (fallback for Apache)
        $serverParams = $request->getServerParams();
        $httpAuth = $serverParams['HTTP_AUTHORIZATION'] ?? '';
        if (!empty($httpAuth) && preg_match('/Bearer\s+(.+)/', $httpAuth, $matches)) {
            return $matches[1];
        }

        // Try REDIRECT_HTTP_AUTHORIZATION (Apache mod_rewrite/mod_auth_form strips and prefixes)
        $redirectAuth = $serverParams['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!empty($redirectAuth) && preg_match('/Bearer\s+(.+)/', $redirectAuth, $matches)) {
            return $matches[1];
        }

        // Fallback to query parameter for backward compatibility
        $queryParams = $request->getQueryParams();
        return $queryParams['token'] ?? null;
    }

    /**
     * Create unauthorized response
     */
    private function createUnauthorizedResponse(string $message, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode([
            'error' => 'Unauthorized',
            'message' => $message
        ]));
        $stream->rewind();

        // Build WWW-Authenticate header with resource_metadata URL (RFC 9728)
        $wwwAuth = 'Bearer';
        if ($request !== null) {
            $resourceMetadataUrl = $this->getRequestBaseUrl($request) . '/.well-known/oauth-protected-resource/mcp';
            $wwwAuth = 'Bearer resource_metadata="' . $resourceMetadataUrl . '"';
        }

        $response = new Response(
            $stream,
            401,
            [
                'Content-Type' => 'application/json',
                'WWW-Authenticate' => $wwwAuth,
            ]
        );

        return $this->addCorsHeaders($response, $request);
    }

    /**
     * Set up backend user context
     */
    private function setupBackendUserContext(int $userId): void
    {
        $userContext = GeneralUtility::makeInstance(BackendUserContextService::class);
        $beUser = $userContext->impersonate($userId);

        // Pick the workspace the MCP tools work in and keep the Context aspect
        // in sync with it.
        $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
        $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);
        $userContext->updateWorkspaceAspect($workspaceId);

        // Set up TYPO3 Context API (following BackendUserAuthenticator pattern)
        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('backend.user', new UserAspect($beUser));
        $context->setAspect('workspace', new WorkspaceAspect($workspaceId));

        // Log workspace selection for debugging
        $this->logDebug("MCP: User {$userId} switched to workspace {$workspaceId}");
    }

    /**
     * Handle auth header test request
     */
    private function handleAuthHeaderTest(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [];
        $receivedAuthHeader = false;

        // Check all possible ways the Authorization header might arrive
        $authHeader = $request->getHeaderLine('Authorization');
        if (!empty($authHeader)) {
            $headers['authorization'] = $authHeader;
            $receivedAuthHeader = true;
        }

        // Check server params for HTTP_AUTHORIZATION
        $serverParams = $request->getServerParams();
        if (isset($serverParams['HTTP_AUTHORIZATION'])) {
            $headers['http_authorization'] = $serverParams['HTTP_AUTHORIZATION'];
            $receivedAuthHeader = true;
        }

        // Also check for redirect env variable (Apache specific)
        if (isset($serverParams['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['redirect_http_authorization'] = $serverParams['REDIRECT_HTTP_AUTHORIZATION'];
            $receivedAuthHeader = true;
        }

        $responseData = [
            'test' => 'auth',
            'headers_received' => $headers,
            'auth_header_detected' => $receivedAuthHeader,
            'server_software' => $serverParams['SERVER_SOFTWARE'] ?? 'unknown',
            'hint' => !$receivedAuthHeader ? 'Authorization header not received. See module page for solutions.' : 'Authorization header received successfully.'
        ];

        $body = GeneralUtility::makeInstance(Stream::class, 'php://temp', 'rw');
        $body->write(json_encode($responseData, JSON_PRETTY_PRINT));

        $response = GeneralUtility::makeInstance(Response::class)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus(200)
            ->withBody($body);

        return $this->addCorsHeaders($response, $request);
    }
}
