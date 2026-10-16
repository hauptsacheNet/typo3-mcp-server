<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Traits;

use Hn\McpServer\Service\TableAccessService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Helpers for tests that need to insert plugin content elements.
 *
 * TYPO3 13 stores plugins as `CType=list, list_type=<plugin>`; TYPO3 14
 * stores them as `CType=<plugin>` directly. The trait abstracts the version
 * difference so tests can stay version-agnostic.
 */
trait PluginContentTrait
{
    /**
     * Build the data array describing a plugin record. Useful for tests that
     * pass the data through WriteTableTool::execute().
     *
     * @param string $pluginIdentifier The plugin identifier (e.g. `news_pi1`).
     * @param array<string, mixed> $extra Fields to merge on top.
     * @return array<string, mixed>
     */
    protected function buildPluginContentRow(string $pluginIdentifier, array $extra = []): array
    {
        if (TableAccessService::hasPluginSubtypes()) {
            $base = ['CType' => 'list', 'list_type' => $pluginIdentifier];
        } else {
            $base = ['CType' => $pluginIdentifier];
        }

        return array_merge($base, $extra);
    }

    /**
     * Insert a plugin record directly into tt_content with the shape the
     * running TYPO3 version expects.
     */
    protected function insertPluginContentElement(int $uid, int $pid, string $pluginIdentifier, array $extra = []): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tt_content');

        $row = $this->buildPluginContentRow($pluginIdentifier, $extra) + [
            'uid' => $uid,
            'pid' => $pid,
            'header' => 'Plugin ' . $pluginIdentifier,
            'colPos' => 0,
            'sorting' => 256,
            'hidden' => 0,
            'deleted' => 0,
            'tstamp' => 1734875000,
            'crdate' => 1734875000,
        ];

        $connection->insert('tt_content', $row);
    }

    /**
     * Plugin identifier (e.g. `news_pi1`) — version independent. Used both as
     * `CType` (v14) and as `list_type` (v13) value for plugin records.
     */
    protected function pluginIdentifier(string $pluginIdentifier): string
    {
        return $pluginIdentifier;
    }

    /**
     * The FlexForm DataStructure identifier used in TCA for a plugin. TYPO3
     * 13 keys DS entries via `*,<list_type>`; TYPO3 14 keys them by CType
     * directly.
     */
    protected function pluginFlexFormIdentifier(string $pluginIdentifier): string
    {
        return TableAccessService::hasPluginSubtypes()
            ? '*,' . $pluginIdentifier
            : $pluginIdentifier;
    }
}
