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
        private readonly ToolRegistry $toolRegistry,
        private readonly ResourceRegistry $resourceRegistry
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
        $resourceRegistry = $this->resourceRegistry;
        $debug = $debugLogger ?? static fn($msg) => null;

        // Register tool/list handler
        // Tools annotated with _meta.ui.visibility=["app"] (SEP-1865) are
        // hidden from the LLM's tool list here — they remain executable via
        // tools/call so the embedded MCP App widget can still invoke them.
        // Defense-in-depth: spec-compliant hosts already filter on visibility,
        // but filtering server-side too ensures the LLM cannot discover them
        // on hosts that ignore the annotation.
        $server->registerHandler('tools/list', static function () use ($toolRegistry, $debug) {
            $debug('Handling tools/list request');
            $tools = [];

            foreach ($toolRegistry->getTools() as $tool) {
                $schema = $tool->getSchema();
                $visibility = $schema['_meta']['ui']['visibility'] ?? null;
                if (is_array($visibility) && !in_array('model', $visibility, true)) {
                    continue;
                }
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

        // Register resources/list handler
        $server->registerHandler('resources/list', static function () use ($resourceRegistry, $debug) {
            $debug('Handling resources/list request');
            $resources = [];
            foreach ($resourceRegistry->getResources() as $resource) {
                $resources[] = [
                    'uri' => $resource->getUri(),
                    'name' => $resource->getName(),
                    'description' => $resource->getDescription(),
                    'mimeType' => $resource->getMimeType(),
                ];
            }
            return ['resources' => $resources];
        });

        // Register resources/read handler
        $server->registerHandler('resources/read', static function ($params) use ($resourceRegistry, $debug) {
            $uri = is_object($params) ? ($params->uri ?? null) : ($params['uri'] ?? null);
            $debug('Handling resources/read request for URI: ' . (string)$uri);

            $resource = $uri ? $resourceRegistry->getResource((string)$uri) : null;
            if (!$resource) {
                throw new \InvalidArgumentException('Resource not found: ' . (string)$uri);
            }

            return [
                'contents' => [
                    [
                        'uri' => $resource->getUri(),
                        'mimeType' => $resource->getMimeType(),
                        'text' => $resource->read(),
                    ],
                ],
            ];
        });
    }
}
