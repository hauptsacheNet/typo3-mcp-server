<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\MCP\Tool\AppOnlyToolInterface;
use Mcp\Server\McpServerException;
use Mcp\Server\Server;
use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Types\CallToolResult;
use Mcp\Types\ExtensionIds;
use Mcp\Types\ListResourcesResult;
use Mcp\Types\ListToolsResult;
use Mcp\Types\ReadResourceResult;
use Mcp\Types\Resource;
use Mcp\Types\TextContent;
use Mcp\Types\TextResourceContents;
use Mcp\Types\Tool;
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

        // MCP Apps: views rendered by the host next to tool calls (Documentation/Architecture/McpApps.md)
        $server->declareExtension(ExtensionIds::UI, ['mimeTypes' => [McpApps::MIME_TYPE]]);

        // Register tool/list handler
        $server->registerHandler('tools/list', static function () use ($server, $toolRegistry, $debug) {
            $debug('Handling tools/list request');
            $tools = [];
            $appsSupported = McpApps::isSupportedByClient($server);

            foreach ($toolRegistry->getTools() as $tool) {
                if ($tool instanceof AppOnlyToolInterface && !$appsSupported) {
                    continue;
                }
                // Normalize to plain arrays first: getSchema() may hand back a
                // \stdClass for an empty "properties" object, which
                // ToolInputSchema::fromArray() rejects. The round trip preserves
                // every JSON Schema keyword - unknown keys are carried through by
                // the extraFields handling in Tool, ToolInputSchema and
                // ToolInputProperties.
                $schema = json_decode((string)json_encode($tool->getSchema()), true) ?? [];
                $tools[] = Tool::fromArray([
                    'name' => $tool->getName(),
                    ...$schema
                ]);
            }

            return new ListToolsResult($tools);
        });

        // Register tool/call handler
        $server->registerHandler('tools/call', static function ($params) use ($server, $toolRegistry, $debug) {
            $toolName = $params->name;
            $arguments = $params->arguments;

            $debug('Handling tools/call request for tool: ' . $toolName);

            $tool = $toolRegistry->getTool($toolName);
            if ($tool instanceof AppOnlyToolInterface && !McpApps::isSupportedByClient($server)) {
                $tool = null;
            }
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

        $server->registerHandler('resources/list', static function () {
            $resources = [];
            foreach (McpApps::getViews() as $uri => $view) {
                $resource = new Resource(
                    name: $view['name'],
                    uri: $uri,
                    description: $view['description'],
                    mimeType: McpApps::MIME_TYPE,
                );
                $resource->setExtraField('_meta', McpApps::getViewMeta());
                $resources[] = $resource;
            }
            return new ListResourcesResult($resources);
        });

        $server->registerHandler('resources/read', static function ($params) use ($server, $debug) {
            $uri = (string)$params->uri;
            $debug('Handling resources/read request for: ' . $uri);

            $html = McpApps::getViewHtml($uri);
            if ($html === null) {
                throw McpServerException::unknownResource($uri, $server->clientSupportsFeature('resource_not_found_invalid_params'));
            }
            $contents = new TextResourceContents(text: $html, uri: $uri, mimeType: McpApps::MIME_TYPE);
            $contents->setExtraField('_meta', McpApps::getViewMeta());
            return new ReadResourceResult([$contents]);
        });
    }
}
