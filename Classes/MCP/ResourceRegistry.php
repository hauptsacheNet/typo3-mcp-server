<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Hn\McpServer\MCP\Resource\ResourceInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registry for MCP resources
 */
class ResourceRegistry
{
    /**
     * @var ResourceInterface[] Registered resources, keyed by URI
     */
    protected array $resources = [];

    public function __construct(
        #[AutowireIterator('mcp.resource')]
        iterable $resources
    ) {
        foreach ($resources as $resource) {
            $this->resources[$resource->getUri()] = $resource;
        }
    }

    /**
     * @return ResourceInterface[]
     */
    public function getResources(): array
    {
        return $this->resources;
    }

    public function getResource(string $uri): ?ResourceInterface
    {
        return $this->resources[$uri] ?? null;
    }
}
