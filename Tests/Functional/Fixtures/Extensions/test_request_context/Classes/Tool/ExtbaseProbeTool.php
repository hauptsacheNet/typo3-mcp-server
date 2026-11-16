<?php

declare(strict_types=1);

namespace Hn\TestRequestContext\Tool;

use Hn\McpServer\MCP\Tool\AbstractTool;
use Hn\TestRequestContext\Domain\Repository\PageRepository;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;

/**
 * Stands in for a third-party tool built on an Extbase repository.
 */
class ExtbaseProbeTool extends AbstractTool
{
    public function __construct(private readonly PageRepository $pageRepository)
    {
    }

    public function getSchema(): array
    {
        return [
            'description' => 'Counts pages through an Extbase repository.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => new \stdClass(),
                'required' => [],
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $query = $this->pageRepository->createQuery();
        $query->getQuerySettings()->setRespectStoragePage(false);

        return new CallToolResult([new TextContent((string)$query->execute()->count())]);
    }
}
