<?php

declare(strict_types=1);

namespace Hn\McpServer\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\HtmlResponse;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\OAuthService;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Backend module controller for MCP Server configuration
 */
class McpServerModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly PageRenderer $pageRenderer,
        private readonly OAuthService $oauthService
    ) {}

    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        
        // Get current user
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new HtmlResponse('Access denied', 403);
        }
        
        // Get user's OAuth tokens
        $userId = (int)$backendUser->user['uid'];
        $tokens = $this->oauthService->getUserTokens($userId);
        
        // Get base URL for endpoint
        $baseUrl = $this->getBaseUrl($request);
        
        // Generate OAuth authorization URL
        $authUrl = $this->oauthService->generateAuthorizationUrl($baseUrl, 'Claude Desktop');
        
        // Check .well-known file status
        $wellKnownStatus = $this->checkWellKnownFile($baseUrl);
        
        // Get available tools
        $tools = [];
        foreach ($this->toolRegistry->getTools() as $tool) {
            $schema = $tool->getSchema();
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $schema['description'] ?? '',
            ];
        }
        
        
        // OAuth configuration for Claude Desktop
        $oauthConfigData = $this->generateOAuthConfig($baseUrl, $this->getSiteName());
        $oauthConfig = json_encode(
            $oauthConfigData,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // Extract just the server configuration part
        $serverKey = array_keys($oauthConfigData['mcpServers'])[0];
        $serverConfig = json_encode(
            $oauthConfigData['mcpServers'][$serverKey],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        
        // Prepare template variables
        $templateVariables = [
            'tokens' => $tokens,
            'authUrl' => $authUrl,
            'baseUrl' => $baseUrl,
            'tools' => $tools,
            'username' => $backendUser->user['username'],
            'userId' => $userId,
            'isAdmin' => (bool)($backendUser->user['admin'] ?? false),
            'oauthConfig' => $oauthConfig,
            'oauthConfigLines' => substr_count($oauthConfig, "\n") + 1,
            'serverConfig' => $serverConfig,
            'serverKey' => $serverKey,
            'wellKnownStatus' => $wellKnownStatus,
        ];
        
        // Include JavaScript for copy functionality
        $this->pageRenderer->addJsFile('EXT:mcp_server/Resources/Public/JavaScript/mcp-module.js');
        
        // Assign variables to ModuleTemplate and render
        $moduleTemplate->assignMultiple($templateVariables);
        $moduleTemplate->setTitle('MCP Server Configuration');
        
        return $moduleTemplate->renderResponse('McpServerModule');
    }
    
    /**
     * Revoke a specific token
     */
    public function revokeTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $parsedBody = $request->getParsedBody();
        $tokenId = (int)($parsedBody['tokenId'] ?? 0);
        $userId = (int)$backendUser->user['uid'];

        if ($tokenId <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid token ID'], 400);
        }

        try {
            $success = $this->oauthService->revokeToken($tokenId, $userId);
            
            if ($success) {
                return new JsonResponse([
                    'success' => true,
                    'message' => 'Token revoked successfully'
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Token not found or access denied'
                ], 404);
            }
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error revoking token: ' . $e->getMessage()
            ], 500);
        }
    }
    
    private function getBaseUrl(ServerRequestInterface $request): string
    {
        // Try to get from TYPO3 configuration first
        $baseUrl = $GLOBALS['TYPO3_CONF_VARS']['SYS']['reverseProxyBaseUrl'] ?? '';
        
        if (empty($baseUrl)) {
            // Fallback to request-based detection
            $scheme = $request->getUri()->getScheme();
            $host = $request->getUri()->getHost();
            $port = $request->getUri()->getPort();
            
            $baseUrl = $scheme . '://' . $host;
            if ($port && !in_array($port, [80, 443])) {
                $baseUrl .= ':' . $port;
            }
        }
        
        return rtrim($baseUrl, '/');
    }
    
    private function generateOAuthConfig(string $baseUrl, string $siteName): array
    {
        // Create a safe identifier from the site name
        $serverKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $siteName));
        $serverKey = preg_replace('/--+/', '-', $serverKey); // Remove multiple dashes
        $serverKey = trim($serverKey, '-'); // Remove leading/trailing dashes
        
        if (empty($serverKey)) {
            $serverKey = 'typo3-mcp';
        }
        
        $endpointUrl = $baseUrl . '/index.php?eID=mcp_server';
        $authUrl = $baseUrl . '/index.php?eID=mcp_server_oauth_authorize';
        $tokenUrl = $baseUrl . '/index.php?eID=mcp_server_oauth_token';
        
        return [
            'mcpServers' => [
                $serverKey => [
                    'command' => 'npx',
                    'args' => [
                        'mcp-remote',
                        $endpointUrl,
                        '--transport',
                        'http-only'
                    ],
                    'auth' => [
                        'authorization_url' => $authUrl,
                        'token_url' => $tokenUrl,
                        'client_id' => 'typo3-mcp-server'
                    ]
                ]
            ]
        ];
    }
    
    private function getSiteName(): string
    {
        // Try to get site name from TYPO3 site configuration
        try {
            $siteConfiguration = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Site\SiteFinder::class);
            $sites = $siteConfiguration->getAllSites();
            
            if (!empty($sites)) {
                $firstSite = array_values($sites)[0];
                $siteName = $firstSite->getConfiguration()['websiteTitle'] ?? '';
                if (!empty($siteName)) {
                    return $siteName;
                }
            }
        } catch (\Exception $e) {
            // Fallback if site configuration fails
        }
        
        // Fallback to global TYPO3 site name
        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'TYPO3 MCP Server';
    }
    
    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
    
    /**
     * Check .well-known OAuth discovery file status and try to create if missing
     */
    private function checkWellKnownFile(string $baseUrl): array
    {
        $webRoot = Environment::getPublicPath();
        $wellKnownDir = $webRoot . '/.well-known';
        $wellKnownFile = $wellKnownDir . '/oauth-authorization-server';
        
        $status = [
            'exists' => false,
            'writable' => false,
            'created' => false,
            'error' => null,
            'path' => $wellKnownFile,
            'url' => $baseUrl . '/.well-known/oauth-authorization-server'
        ];
        
        // Check if file exists
        if (file_exists($wellKnownFile)) {
            $status['exists'] = true;
            $status['writable'] = is_writable($wellKnownFile);
            return $status;
        }
        
        // Try to create the directory if it doesn't exist
        if (!is_dir($wellKnownDir)) {
            if (!@mkdir($wellKnownDir, 0755, true)) {
                $status['error'] = 'Cannot create .well-known directory. Please create it manually: ' . $wellKnownDir;
                return $status;
            }
        }
        
        // Check if directory is writable
        if (!is_writable($wellKnownDir)) {
            $status['error'] = 'Directory .well-known is not writable. Please check permissions: ' . $wellKnownDir;
            return $status;
        }
        
        // Generate OAuth metadata content
        $metadata = $this->oauthService->getMetadata($baseUrl);
        $content = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        // Try to create the file
        if (@file_put_contents($wellKnownFile, $content) !== false) {
            $status['created'] = true;
            $status['exists'] = true;
            $status['writable'] = true;
        } else {
            $status['error'] = 'Failed to write .well-known file. Please create it manually: ' . $wellKnownFile;
        }
        
        return $status;
    }
    
    /**
     * AJAX endpoint to manually create .well-known file
     */
    public function createWellKnownFileAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }
        
        $baseUrl = $this->getBaseUrl($request);
        $status = $this->checkWellKnownFile($baseUrl);
        
        if ($status['exists']) {
            return new JsonResponse([
                'success' => true,
                'message' => 'OAuth discovery file already exists',
                'status' => $status
            ]);
        }
        
        if ($status['error']) {
            return new JsonResponse([
                'success' => false,
                'message' => $status['error'],
                'status' => $status
            ], 500);
        }
        
        if ($status['created']) {
            return new JsonResponse([
                'success' => true,
                'message' => 'OAuth discovery file created successfully',
                'status' => $status
            ]);
        }
        
        return new JsonResponse([
            'success' => false,
            'message' => 'Unknown error creating .well-known file',
            'status' => $status
        ], 500);
    }

    /**
     * Get user tokens via AJAX for dynamic updates
     */
    public function getUserTokensAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $userId = (int)$backendUser->user['uid'];
            $tokens = $this->oauthService->getUserTokens($userId);
            
            // Format tokens for frontend display
            $formattedTokens = array_map(function($token) {
                return [
                    'uid' => $token['uid'],
                    'client_name' => $token['client_name'],
                    'created' => date('Y-m-d H:i:s', $token['crdate']),
                    'expires' => date('Y-m-d H:i:s', $token['expires']),
                    'last_used' => $token['last_used'] > 0 ? date('Y-m-d H:i:s', $token['last_used']) : 'Never',
                    'token_preview' => substr($token['token'], 0, 20) . '...',
                ];
            }, $tokens);
            
            return new JsonResponse([
                'success' => true,
                'tokens' => $formattedTokens
            ]);
            
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error retrieving tokens: ' . $e->getMessage()
            ], 500);
        }
    }
}