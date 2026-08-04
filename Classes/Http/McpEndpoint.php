<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\FileSessionStore;
use Mcp\Server\Transport\Http\HttpMessage;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Hn\McpServer\MCP\McpServerFactory;
use Hn\McpServer\Service\WorkspaceContextService;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Http\CorsHeadersTrait;

/**
 * MCP HTTP Endpoint for remote access
 */
class McpEndpoint
{
    use CorsHeadersTrait;
    use RequestUrlTrait;
    /**
     * eID entry point via __invoke method
     */
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            // Get services through DI container
            $container = GeneralUtility::getContainer();
            $serverFactory = $container->get(McpServerFactory::class);

            // Debug: Log all request details
            $headers = [];
            foreach ($request->getHeaders() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }
            $queryParams = $request->getQueryParams();

            error_log("MCP: Request method: " . $request->getMethod());
            error_log("MCP: Request headers: " . json_encode($headers));
            error_log("MCP: Query params: " . json_encode($queryParams));

            // Check if this is an auth header test request
            if (isset($queryParams['test']) && $queryParams['test'] === 'auth') {
                return $this->handleAuthHeaderTest($request);
            }

            // Authenticate via Bearer token or query parameter
            $token = $this->extractToken($request);

            if (!$token) {
                error_log("MCP: No token found in Authorization header or query params");
                return $this->createUnauthorizedResponse('Missing authentication token', $request);
            }

            // Log token for debugging (first 20 chars only for security)
            error_log("MCP: Received token: " . substr($token, 0, 20) . "...");

            $oauthService = GeneralUtility::makeInstance(OAuthService::class);
            $tokenInfo = $oauthService->validateToken($token, $request);

            if (!$tokenInfo) {
                error_log("MCP: Token validation failed for: " . substr($token, 0, 20) . "...");
                return $this->createUnauthorizedResponse('Invalid or expired token', $request);
            }

            error_log("MCP: Token validation successful for user: " . $tokenInfo['be_user_uid']);

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
                'session_timeout' => 1800, // 30 minutes
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
        $beUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);

        // Load user data
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');

        $queryBuilder = $connection->createQueryBuilder();
        $userData = $queryBuilder
            ->select('*')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId)))
            ->executeQuery()
            ->fetchAssociative();

        if ($userData) {
            $beUser->user = $userData;

            // CRITICAL: Restore the user's stored configuration (uc). The regular
            // authentication flow unserializes it via unpack_uc(), which token auth
            // bypasses. Without this, $beUser->uc stays empty and any writeUC()
            // triggered during request processing (e.g. the update signals fired
            // when the MCP workspace is created below) overwrites the user's
            // stored backend preferences with a nearly empty array. That in turn
            // breaks the backend Setup module, which expects keys like 'titleLen'
            // to exist ("Undefined array key" warning in SetupModuleController).
            $storedUc = unserialize((string)($userData['uc'] ?? ''), ['allowed_classes' => false]);
            if (is_array($storedUc)) {
                $beUser->uc = $storedUc;
            }

            $GLOBALS['BE_USER'] = $beUser;

            // CRITICAL: Initialize an (anonymous) user session.
            // Normal TYPO3 requests go through BackendUserAuthenticator middleware which wires
            // up a real UserSession. Token auth bypasses that, so DataHandler write paths
            // that touch $beUser->setAndSaveSessionData() (FlashMessageQueue, BackendFormProtection)
            // crash with "Call to a member function set() on null" on UPDATE operations.
            // An anonymous in-memory session is discarded at request end — sufficient for stateless MCP.
            $beUser->initializeUserSessionManager();

            // CRITICAL: Fetch group data to populate permissions
            // This computes tables_select, tables_modify, non_exclude_fields, webmounts, etc.
            // Without this, non-admin users have no permissions computed from their groups
            $beUser->fetchGroupData();

            // Apply the uc defaults and TSconfig overrides, exactly like
            // initializeBackendLogin() does after fetchGroupData() on a regular
            // login. This covers users who never logged into the backend: their
            // stored uc is empty, and without the defaults the first writeUC()
            // would persist a nearly empty uc - which core never repairs, since
            // backendSetUC() only fills in the defaults while uc is completely
            // empty.
            $beUser->backendSetUC();

            // Initialize language service (required for DataHandler and other core components)
            $this->initializeLanguageService($beUser);

            // Set up workspace context
            $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
            $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);

            // Set up TYPO3 Context API (following BackendUserAuthenticator pattern)
            $context = GeneralUtility::makeInstance(Context::class);
            $context->setAspect('backend.user', new UserAspect($beUser));
            $context->setAspect('workspace', new WorkspaceAspect($workspaceId));

            // Log workspace selection for debugging
            error_log("MCP: User {$userId} switched to workspace {$workspaceId}");
        }

        // Ensure TCA is loaded using proper TYPO3 core method
        $tcaFactory = GeneralUtility::getContainer()->get(\TYPO3\CMS\Core\Configuration\Tca\TcaFactory::class);
        $GLOBALS['TCA'] = $tcaFactory->get();
    }

    /**
     * Initialize language service for the backend user
     */
    private function initializeLanguageService(BackendUserAuthentication $beUser): void
    {
        // Get user's preferred language or fall back to default
        $userLanguage = $beUser->user['lang'] ?? 'default';

        // Create language service
        $languageServiceFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class);
        $languageService = $languageServiceFactory->createFromUserPreferences($beUser);

        // Set global language service
        $GLOBALS['LANG'] = $languageService;
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
