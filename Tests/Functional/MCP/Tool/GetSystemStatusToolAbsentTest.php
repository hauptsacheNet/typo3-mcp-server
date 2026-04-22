<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool;

use Hn\McpServer\MCP\ToolRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Verifies that GetSystemStatusTool stays unregistered when EXT:reports is not
 * installed, so the optional dependency is truly optional.
 */
class GetSystemStatusToolAbsentTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    public function testToolIsNotRegisteredWhenReportsExtensionIsMissing(): void
    {
        $registry = GeneralUtility::makeInstance(ToolRegistry::class);

        self::assertNull(
            $registry->getTool('GetSystemStatus'),
            'GetSystemStatus must be absent when EXT:reports is not installed'
        );

        // Sanity check: other tools are still registered.
        self::assertNotNull($registry->getTool('GetPageTree'));
    }
}
