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
use Hn\McpServer\MCP\ToolRegistry;

/**
 * Backend module controller for MCP Server configuration
 */
class McpServerModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly PageRenderer $pageRenderer
    ) {}

    public function mainAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        
        // Get current user
        $backendUser = $this->getBackendUser();
        if (!$backendUser) {
            return new HtmlResponse('Access denied', 403);
        }
        
        // Get user's token information
        $tokenInfo = $this->getUserTokenInfo($backendUser->user['uid']);
        
        // Get base URL for endpoint
        $baseUrl = $this->getBaseUrl($request);
        $endpointUrl = $tokenInfo['token'] ? 
            $baseUrl . '/index.php?eID=mcp_server&token=' . urlencode($tokenInfo['token']) : 
            '';
        
        // Get available tools
        $tools = [];
        foreach ($this->toolRegistry->getTools() as $tool) {
            $schema = $tool->getSchema();
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $schema['description'] ?? '',
            ];
        }
        
        
        $mcpConfigData = $this->generateMcpConfig($endpointUrl, $this->getSiteName());
        $mcpConfig = json_encode(
            $mcpConfigData,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        
        // Extract just the server configuration part
        $serverKey = array_keys($mcpConfigData['mcpServers'])[0];
        $serverConfig = json_encode(
            $mcpConfigData['mcpServers'][$serverKey],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        
        // Prepare template variables
        $templateVariables = [
            'tokenInfo' => $tokenInfo,
            'endpointUrl' => $endpointUrl,
            'baseUrl' => $baseUrl,
            'tools' => $tools,
            'username' => $backendUser->user['username'],
            'mcpConfig' => $mcpConfig,
            'mcpConfigLines' => substr_count($mcpConfig, "\n") + 1,
            'serverConfig' => $serverConfig,
            'serverKey' => $serverKey,
        ];
        
        // Include JavaScript for copy functionality
        $this->pageRenderer->addJsFile('EXT:mcp_server/Resources/Public/JavaScript/mcp-module.js');
        
        // Assign variables to ModuleTemplate and render
        $moduleTemplate->assignMultiple($templateVariables);
        $moduleTemplate->setTitle('MCP Server Configuration');
        
        return $moduleTemplate->renderResponse('McpServerModule');
    }
    
    private function getUserTokenInfo(int $userId): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users');
            
        $queryBuilder = $connection->createQueryBuilder();
        $result = $queryBuilder
            ->select('mcp_token', 'mcp_token_expires')
            ->from('be_users')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId)))
            ->executeQuery()
            ->fetchAssociative();
        
        if (!$result) {
            return ['token' => '', 'expires' => 0, 'isValid' => false];
        }
        
        $isValid = !empty($result['mcp_token']) && 
                   !empty($result['mcp_token_expires']) && 
                   $result['mcp_token_expires'] > time();
        
        return [
            'token' => $result['mcp_token'] ?? '',
            'expires' => (int)($result['mcp_token_expires'] ?? 0),
            'isValid' => $isValid,
        ];
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
    
    private function generateMcpConfig(string $endpointUrl, string $siteName): array
    {
        if (empty($endpointUrl)) {
            return [];
        }
        
        // Create a safe identifier from the site name
        $serverKey = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $siteName));
        $serverKey = preg_replace('/--+/', '-', $serverKey); // Remove multiple dashes
        $serverKey = trim($serverKey, '-'); // Remove leading/trailing dashes
        
        if (empty($serverKey)) {
            $serverKey = 'typo3-mcp';
        }
        
        return [
            'mcpServers' => [
                $serverKey => [
                    'command' => 'npx',
                    'args' => [
                        'mcp-remote',
                        $endpointUrl,
                        '--transport',
                        'http-only'
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
}