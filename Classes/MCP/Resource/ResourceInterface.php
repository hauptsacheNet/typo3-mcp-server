<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Resource;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Interface for MCP resources served by this server.
 *
 * Resources are referenced by tools (via `_meta.ui.resourceUri`) or fetched
 * directly by hosts that enumerate `resources/list` and `resources/read`.
 */
#[AutoconfigureTag('mcp.resource')]
interface ResourceInterface
{
    public function getUri(): string;

    public function getName(): string;

    public function getMimeType(): string;

    public function getDescription(): string;

    /**
     * Return the raw resource content (HTML, JSON, …).
     */
    public function read(): string;
}
