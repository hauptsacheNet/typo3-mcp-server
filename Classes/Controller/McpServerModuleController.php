<?php

declare(strict_types=1);

namespace Hn\McpServer\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\HtmlResponse;
use Hn\McpServer\MCP\ToolRegistry;
use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Service\WorkspaceContextService;

/**
 * Backend module controller for MCP Server configuration
 */
class McpServerModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly PageRenderer $pageRenderer,
        private readonly OAuthService $oauthService,
        private readonly WorkspaceContextService $workspaceContextService,
        private readonly UriBuilder $uriBuilder
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
        
        

        // Check if any workspace exists
        $hasWorkspace = $this->hasAnyWorkspace();

        // Detect if the server is running on localhost
        $isLocalhost = $this->isLocalhostUrl($baseUrl);

        // Generate URL to create a new workspace record (pid 0 = root level)
        $createWorkspaceUrl = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['sys_workspace' => [0 => 'new']],
            'returnUrl' => (string)$request->getUri(),
        ]);

        // Format tokens for template display (same shape as AJAX response)
        $formattedTokens = array_map(function ($token) {
            return [
                'uid' => $token['uid'],
                'client_name' => $token['client_name'],
                'created' => date('Y-m-d H:i:s', $token['crdate']),
                'last_used' => $token['last_used'] > 0 ? date('Y-m-d H:i:s', $token['last_used']) : 'Never',
                'expires' => date('Y-m-d H:i:s', $token['expires']),
            ];
        }, $tokens);

        // Prepare template variables
        $templateVariables = [
            'tokens' => $formattedTokens,
            'authUrl' => $authUrl,
            'baseUrl' => $baseUrl,
            'tools' => $tools,
            'username' => $backendUser->user['username'],
            'userId' => $userId,
            'siteName' => $this->getSiteName(),
            'hasWorkspace' => $hasWorkspace,
            'isLocalhost' => $isLocalhost,
            'createWorkspaceUrl' => $createWorkspaceUrl,
        ];
        
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
     * Check if any TYPO3 workspace exists
     */
    private function hasAnyWorkspace(): bool
    {
        try {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $queryBuilder = $connectionPool->getQueryBuilderForTable('sys_workspace');
            $count = $queryBuilder
                ->count('uid')
                ->from('sys_workspace')
                ->where(
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \TYPO3\CMS\Core\Database\Connection::PARAM_INT))
                )
                ->executeQuery()
                ->fetchOne();
            return (int)$count > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if the base URL resolves to a private/non-routable network address.
     * Catches localhost, DDEV domains (*.ddev.site), Docker networks, etc.
     * Checks both IPv4 (A) and IPv6 (AAAA) records to avoid false positives
     * on IPv6-only hosts.
     */
    private function isLocalhostUrl(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if ($host === null || $host === '') {
            return false;
        }
        $host = strtolower($host);

        // Quick check for obvious literals
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || str_ends_with($host, '.localhost')) {
            return true;
        }

        // Resolve both A and AAAA records
        $ips = $this->resolveHostIps($host);
        if ($ips === []) {
            // Cannot resolve at all — don't assume private, could be a DNS issue
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                // At least one public IP → not localhost-only
                return false;
            }
        }

        // All resolved IPs are private/reserved
        return true;
    }

    /**
     * Resolve a hostname to all its IPv4 and IPv6 addresses.
     *
     * @return string[]
     */
    private function resolveHostIps(string $host): array
    {
        $ips = [];

        // IPv4 A records
        $ipv4 = gethostbynamel($host);
        if ($ipv4 !== false) {
            $ips = $ipv4;
        }

        // IPv6 AAAA records
        $records = @dns_get_record($host, DNS_AAAA);
        if ($records !== false) {
            foreach ($records as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return $ips;
    }

    /**
     * Create an access token for MCP clients via AJAX.
     * Accepts a free-form client name (non-empty, max 100 chars).
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

            $clientName = trim($parsedBody['clientName'] ?? $parsedBody['clientType'] ?? '');

            // Validate client name
            if ($clientName === '') {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Token name is required'
                ], 400);
            }
            if (mb_strlen($clientName) > 100) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Token name must not exceed 100 characters'
                ], 400);
            }

            // Create new token
            $token = $this->oauthService->createDirectAccessToken($userId, $clientName, $request);

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Token "%s" created successfully', $clientName),
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