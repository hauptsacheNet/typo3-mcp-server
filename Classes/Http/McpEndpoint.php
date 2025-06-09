<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Mcp\Server\Server;
use Mcp\Server\HttpServerRunner;
use Mcp\Server\Transport\Http\StandardPhpAdapter;
use Mcp\Server\Transport\Http\FileSessionStore;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\WorkspaceContextService;

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
            // Get tool registry through DI container
            $container = GeneralUtility::getContainer();
            $toolRegistry = $container->get(ToolRegistry::class);
            
            // Authenticate via query parameter
            $queryParams = $request->getQueryParams();
            $token = $queryParams['token'] ?? '';
            
            if (!$this->validateToken($token)) {
                $stream = new Stream('php://temp', 'rw');
                $stream->write(json_encode([
                    'error' => 'Unauthorized',
                    'message' => 'Invalid or missing token'
                ]));
                $stream->rewind();
                
                return new Response(
                    $stream,
                    401,
                    ['Content-Type' => 'application/json']
                );
            }

            // Set up TYPO3 backend context for the authenticated user
            $userId = $this->getUserIdFromToken($token);
            $this->setupBackendUserContext($userId);

            // Create MCP server instance
            $server = new Server('typo3-mcp-server');
            
            // Register handlers
            $this->registerHandlers($server, $toolRegistry);
            
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
            
            // Create runner and adapter
            $runner = new HttpServerRunner(
                $server, 
                $server->createInitializationOptions(), 
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
     * Register MCP handlers
     */
    private function registerHandlers(Server $server, ToolRegistry $toolRegistry): void
    {
        // Register tool/list handler
        $server->registerHandler('tools/list', function() use ($toolRegistry) {
            $tools = [];
            
            foreach ($toolRegistry->getTools() as $tool) {
                $schema = $tool->getSchema();
                $properties = $schema['parameters']['properties'] ?? [];
                $required = $schema['parameters']['required'] ?? [];
                
                // Ensure properties is an object, not an array (MCP requires object)
                if (empty($properties)) {
                    $properties = new \stdClass();
                } else {
                    // Convert associative array to object for JSON encoding
                    $properties = (object) $properties;
                }
                
                $tools[] = [
                    'name' => $tool->getName(),
                    'description' => $schema['description'] ?? '',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => $properties,
                        'required' => $required,
                    ],
                ];
            }
            
            return ['tools' => $tools];
        });
        
        // Register tool/call handler
        $server->registerHandler('tools/call', function($params) use ($toolRegistry) {
            $toolName = $params->name;
            $arguments = $params->arguments;
            
            $tool = $toolRegistry->getTool($toolName);
            if (!$tool) {
                throw new \InvalidArgumentException('Tool not found: ' . $toolName);
            }
            
            return $tool->execute($arguments);
        });
    }

    /**
     * Validate authentication token
     */
    private function validateToken(string $token): bool
    {
        if (empty($token)) {
            return false;
        }

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');
            
        $queryBuilder = $connection->createQueryBuilder();
        $result = $queryBuilder
            ->select('uid', 'username', 'mcp_token_expires')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('mcp_token', $queryBuilder->createNamedParameter($token)),
                $queryBuilder->expr()->gt('mcp_token_expires', $queryBuilder->createNamedParameter(time())),
                $queryBuilder->expr()->eq('disable', $queryBuilder->createNamedParameter(0))
            )
            ->executeQuery()
            ->fetchAssociative();

        return $result !== false;
    }

    /**
     * Get user ID from valid token
     */
    private function getUserIdFromToken(string $token): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');
            
        $queryBuilder = $connection->createQueryBuilder();
        $result = $queryBuilder
            ->select('uid')
            ->from('be_users')
            ->where(
                $queryBuilder->expr()->eq('mcp_token', $queryBuilder->createNamedParameter($token)),
                $queryBuilder->expr()->gt('mcp_token_expires', $queryBuilder->createNamedParameter(time()))
            )
            ->executeQuery()
            ->fetchAssociative();

        return (int)($result['uid'] ?? 0);
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
            
            // Set up workspace context
            $workspaceService = GeneralUtility::makeInstance(WorkspaceContextService::class);
            $workspaceId = $workspaceService->switchToOptimalWorkspace($beUser);
            
            // Log workspace selection for debugging
            error_log("MCP: User {$userId} switched to workspace {$workspaceId}");
        }
        
        // Ensure TCA is loaded
        $this->ensureTcaLoaded();
    }

    /**
     * Ensure TCA is loaded (copied from CLI command)
     */
    private function ensureTcaLoaded(): void
    {
        if (empty($GLOBALS['TCA']) || empty($GLOBALS['TCA']['tt_content']['columns']['pi_flexform'])) {
            // Load the TCA directly
            $tcaPath = Environment::getPublicPath() . '/typo3/sysext/core/Configuration/TCA/';
            if (is_dir($tcaPath)) {
                $files = glob($tcaPath . '*.php');
                foreach ($files as $file) {
                    require_once $file;
                }
            }
            
            // Load extension TCA
            $extTcaPath = Environment::getPublicPath() . '/typo3conf/ext/*/Configuration/TCA/';
            $extFiles = glob($extTcaPath . '*.php');
            if (is_array($extFiles)) {
                foreach ($extFiles as $file) {
                    require_once $file;
                }
            }
            
            // Load TCA overrides
            $overridePath = Environment::getPublicPath() . '/typo3conf/ext/*/Configuration/TCA/Overrides/';
            $overrideFiles = glob($overridePath . '*.php');
            if (is_array($overrideFiles)) {
                foreach ($overrideFiles as $file) {
                    require_once $file;
                }
            }
        }
    }
}