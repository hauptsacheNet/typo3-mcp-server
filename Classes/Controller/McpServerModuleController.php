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
        
        // Get available tools
        $tools = [];
        foreach ($this->toolRegistry->getTools() as $tool) {
            $schema = $tool->getSchema();
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $schema['description'] ?? '',
            ];
        }
        
        
        // Generate mcp-remote token URL (for clients that don't support auth headers)
        $mcpRemoteUrl = $this->generateMcpRemoteUrl($baseUrl, $tokens);

        // Generate token info for n8n and manus
        $n8nTokenInfo = $this->getClientTokenInfo($tokens, 'n8n token');
        $manusTokenInfo = $this->getClientTokenInfo($tokens, 'manus token');

        // Prepare template variables
        $templateVariables = [
            'tokens' => $tokens,
            'authUrl' => $authUrl,
            'baseUrl' => $baseUrl,
            'tools' => $tools,
            'username' => $backendUser->user['username'],
            'userId' => $userId,
            'mcpRemoteUrl' => $mcpRemoteUrl,
            'n8nTokenInfo' => $n8nTokenInfo,
            'manusTokenInfo' => $manusTokenInfo,
            'siteName' => $this->getSiteName(),
        ];
        
        // Include JavaScript for copy functionality
        $this->pageRenderer->addJsFile('EXT:mcp_server/Resources/Public/JavaScript/mcp-module.js');
        
        // Include CSS for endpoint status indicators
        $this->pageRenderer->addCssFile('EXT:mcp_server/Resources/Public/Css/mcp-module.css');
        
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

        $rawBody = $request->getBody()->getContents();
        
        // Reset body stream position for further processing
        $request->getBody()->rewind();
        
        $parsedBody = $request->getParsedBody();
        
        // If parsedBody is null, try to decode JSON manually
        if ($parsedBody === null && !empty($rawBody)) {
            $jsonData = json_decode($rawBody, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $parsedBody = $jsonData;
            }
        }
        
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
    
    /**
     * Revoke all tokens for the current user
     */
    public function revokeAllTokensAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        $userId = (int)$backendUser->user['uid'];

        try {
            $revokedCount = $this->oauthService->revokeAllUserTokens($userId);
            
            if ($revokedCount > 0) {
                return new JsonResponse([
                    'success' => true,
                    'message' => sprintf('Successfully revoked %d token%s', $revokedCount, $revokedCount === 1 ? '' : 's')
                ]);
            } else {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'No tokens found to revoke'
                ], 404);
            }
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error revoking tokens: ' . $e->getMessage()
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
    
    
    private function getSiteName(): string
    {
        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'TYPO3 MCP Server';
    }
    
    private function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
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

    /**
     * Generate mcp-remote URL with token parameter for clients that don't support auth headers
     */
    private function generateMcpRemoteUrl(string $baseUrl, array $tokens): array
    {
        $endpointUrl = $baseUrl . '/mcp';

        // Filter tokens to only include mcp-remote tokens
        $mcpRemoteTokens = array_filter($tokens, function($token) {
            return $token['client_name'] === 'mcp-remote token';
        });

        return [
            'baseUrl' => $endpointUrl,
            'hasTokens' => !empty($mcpRemoteTokens),
            'tokenUrl' => !empty($mcpRemoteTokens) ? $endpointUrl . '?token=' . array_values($mcpRemoteTokens)[0]['token'] : null,
            'description' => 'For MCP clients that don\'t support Authorization headers (like mcp-remote without auth)',
        ];
    }

    /**
     * Get token info for a specific client type
     */
    private function getClientTokenInfo(array $tokens, string $clientName): array
    {
        $clientTokens = array_filter($tokens, function($token) use ($clientName) {
            return $token['client_name'] === $clientName;
        });

        $hasToken = !empty($clientTokens);
        $token = $hasToken ? array_values($clientTokens)[0] : null;

        return [
            'hasToken' => $hasToken,
            'token' => $token['token'] ?? null,
            'expires' => $token['expires'] ?? null,
            'clientName' => $clientName,
        ];
    }

    /**
     * Create an access token for MCP clients via AJAX
     * Supports different client types (mcp-remote, n8n, manus)
     */
    public function createTokenAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new JsonResponse(['success' => false, 'message' => 'Access denied'], 403);
        }

        try {
            $userId = (int)$backendUser->user['uid'];

            // Get client type from POST body (default to mcp-remote for backward compatibility)
            $rawBody = $request->getBody()->getContents();
            $request->getBody()->rewind();
            $parsedBody = $request->getParsedBody();

            if ($parsedBody === null && !empty($rawBody)) {
                $jsonData = json_decode($rawBody, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $parsedBody = $jsonData;
                }
            }

            $clientType = $parsedBody['clientType'] ?? 'mcp-remote token';

            // Validate client type
            $allowedClientTypes = ['mcp-remote token', 'n8n token', 'manus token'];
            if (!in_array($clientType, $allowedClientTypes, true)) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Invalid client type'
                ], 400);
            }

            // Check if user already has a token for this client type
            $existingTokens = $this->oauthService->getUserTokens($userId);
            $tokenExists = false;
            foreach ($existingTokens as $token) {
                if ($token['client_name'] === $clientType) {
                    $tokenExists = true;
                    break;
                }
            }

            if ($tokenExists) {
                return new JsonResponse([
                    'success' => false,
                    'message' => sprintf('You already have a %s. Please revoke it first if you want to create a new one.', $clientType)
                ], 400);
            }

            // Create new token for the specified client type
            $token = $this->oauthService->createDirectAccessToken($userId, $clientType, $request);

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('%s created successfully', $clientType),
                'token' => $token
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Error creating token: ' . $e->getMessage()
            ], 500);
        }
    }

}