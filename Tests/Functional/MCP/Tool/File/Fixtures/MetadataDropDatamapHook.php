<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File\Fixtures;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Test-only DataHandler hook that strips fields from sys_file_metadata writes
 * without setting an error. Reproduces the silent-drop scenario observed in
 * production where DataHandler returns success and errorLog stays empty
 * (typically because the active backend user lacks workspace write permission
 * on sys_file_metadata) yet the row remains unchanged.
 */
final class MetadataDropDatamapHook
{
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        $id,
        array &$fieldArray,
        DataHandler $dataHandler
    ): void {
        if ($table === 'sys_file_metadata') {
            $fieldArray = [];
        }
    }
}
