<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\Fixtures;

use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SysLog\Action\Database as SystemLogDatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;

/**
 * Test-only DataHandler hook that injects a cascade-style error into the
 * error log AFTER the primary delete has already succeeded, mirroring the
 * production scenario where DataHandler logs "you wanted to versionize was
 * already a version in archive" while the parent record itself ends up
 * removed in the workspace.
 */
final class CascadeErrorInjectingHook
{
    public static string $injectForTable = '';
    public static string $message = 'Simulated cascade error: child was already a version in archive';

    public static function reset(): void
    {
        self::$injectForTable = '';
        self::$message = 'Simulated cascade error: child was already a version in archive';
    }

    public function processCmdmap_postProcess(
        string $command,
        string $table,
        $id,
        $value,
        DataHandler &$dataHandler,
        &$pasteUpdate,
        &$pasteDatamap
    ): void {
        if ($command !== 'delete') {
            return;
        }
        if (self::$injectForTable === '' || self::$injectForTable !== $table) {
            return;
        }

        $dataHandler->log(
            $table,
            (int)$id,
            SystemLogDatabaseAction::DELETE,
            null,
            SystemLogErrorClassification::SYSTEM_ERROR,
            self::$message
        );
    }
}
