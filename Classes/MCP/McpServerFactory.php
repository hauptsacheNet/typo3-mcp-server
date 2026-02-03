<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Mcp\Server\Server;
use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Factory for creating and configuring MCP Server instances
 */
class McpServerFactory
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry
    ) {}

    /**
     * Create a fully configured MCP Server instance
     *
     * @param callable|null $debugLogger Optional debug logger function
     */
    public function createServer(?callable $debugLogger = null): Server
    {
        $serverName = $this->getServerName();
        $server = new Server($serverName);

        $this->registerHandlers($server, $debugLogger);

        return $server;
    }

    /**
     * Create InitializationOptions with proper version information
     */
    public function createInitializationOptions(Server $server): InitializationOptions
    {
        $notificationOptions = new NotificationOptions();
        $capabilities = $server->getCapabilities($notificationOptions, []);

        return new InitializationOptions(
            serverName: $this->getServerName(),
            serverVersion: $this->getServerVersion(),
            capabilities: $capabilities
        );
    }

    /**
     * Get the server name from TYPO3 configuration
     */
    public function getServerName(): string
    {
        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'TYPO3 MCP Server';
    }

    /**
     * Get the server version string including extension and TYPO3 versions
     */
    public function getServerVersion(): string
    {
        $extVersion = ExtensionManagementUtility::getExtensionVersion('mcp_server');
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class)->getVersion();

        return $extVersion . ' (TYPO3 ' . $typo3Version . ')';
    }

    /**
     * Register MCP handlers on the server
     */
    private function registerHandlers(Server $server, ?callable $debugLogger): void
    {
        $toolRegistry = $this->toolRegistry;
        $debug = $debugLogger ?? static fn($msg) => null;

        // Register tool/list handler
        $server->registerHandler('tools/list', static function () use ($toolRegistry, $debug) {
            $debug('Handling tools/list request');
            $tools = [];

            foreach ($toolRegistry->getTools() as $tool) {
                $schema = $tool->getSchema();
                $tools[] = [
                    'name' => $tool->getName(),
                    ...$schema
                ];
            }

            return ['tools' => $tools];
        });

        // Register tool/call handler
        $server->registerHandler('tools/call', static function ($params) use ($toolRegistry, $debug) {
            $toolName = $params->name;
            $arguments = $params->arguments;

            $debug('Handling tools/call request for tool: ' . $toolName);

            $tool = $toolRegistry->getTool($toolName);
            if (!$tool) {
                throw new \InvalidArgumentException('Tool not found: ' . $toolName);
            }

            try {
                return $tool->execute($arguments);
            } catch (\Throwable $e) {
                $debug('Error executing tool ' . $toolName . ': ' . $e->getMessage());
                return new CallToolResult(
                    [new TextContent($e->getMessage())],
                    true
                );
            }
        });
    }
}
