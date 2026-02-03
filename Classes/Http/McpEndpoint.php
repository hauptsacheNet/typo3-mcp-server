<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\StandardPhpAdapter;
use Mcp\Server\Transport\Http\FileSessionStore;
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

/**
 * MCP HTTP Endpoint for remote access
 */
class McpEndpoint
{
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
                return $this->createUnauthorizedResponse('Missing authentication token');
            }

            // Log token for debugging (first 20 chars only for security)
            error_log("MCP: Received token: " . substr($token, 0, 20) . "...");

            $oauthService = GeneralUtility::makeInstance(OAuthService::class);
            $tokenInfo = $oauthService->validateToken($token, $request);

            if (!$tokenInfo) {
                error_log("MCP: Token validation failed for: " . substr($token, 0, 20) . "...");
                return $this->createUnauthorizedResponse('Invalid or expired token');
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

            // Handle the request and capture output
            ob_start();

            // Suppress warnings/notices from MCP SDK to prevent deprecation issues
            $oldErrorReporting = error_reporting(E_ERROR | E_PARSE);

            try {
                $adapter = new StandardPhpAdapter($runner);
                $adapter->handle();
            } finally {
                // Restore error reporting
                error_reporting($oldErrorReporting);
            }

            $output = ob_get_clean();

            // Get the status code set by the adapter
            $statusCode = http_response_code() ?: 200;

            // Try to decode as JSON, fallback to plain text
            $decodedOutput = json_decode($output, true);
            $contentType = $decodedOutput !== null ? 'application/json' : 'text/plain';

            // Create proper stream for response
            $stream = new Stream('php://temp', 'rw');
            $stream->write($output);
            $stream->rewind();

            return new Response(
                $stream,
                $statusCode,
                ['Content-Type' => $contentType]
            );

        } catch (\Throwable $e) {
            $stream = new Stream('php://temp', 'rw');
            $stream->write(json_encode([
                'error' => 'Internal Server Error',
                'message' => $e->getMessage()
            ]));
            $stream->rewind();

            return new Response(
                $stream,
                500,
                ['Content-Type' => 'application/json']
            );
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

        // Fallback to query parameter for backward compatibility
        $queryParams = $request->getQueryParams();
        return $queryParams['token'] ?? null;
    }

    /**
     * Create unauthorized response
     */
    private function createUnauthorizedResponse(string $message): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode([
            'error' => 'Unauthorized',
            'message' => $message
        ]));
        $stream->rewind();

        return new Response(
            $stream,
            401,
            ['Content-Type' => 'application/json']
        );
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
            $GLOBALS['BE_USER'] = $beUser;

            // CRITICAL: Fetch group data to populate permissions
            // This computes tables_select, tables_modify, non_exclude_fields, webmounts, etc.
            // Without this, non-admin users have no permissions computed from their groups
            $beUser->fetchGroupData();

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

        $response = GeneralUtility::makeInstance(Response::class)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->withStatus(200);

        $responseData = [
            'test' => 'auth',
            'headers_received' => $headers,
            'auth_header_detected' => $receivedAuthHeader,
            'server_software' => $serverParams['SERVER_SOFTWARE'] ?? 'unknown',
            'hint' => !$receivedAuthHeader ? 'Authorization header not received. See module page for solutions.' : 'Authorization header received successfully.'
        ];

        $body = GeneralUtility::makeInstance(Stream::class, 'php://temp', 'rw');
        $body->write(json_encode($responseData, JSON_PRETTY_PRINT));

        return $response->withBody($body);
    }
}
