<?php

declare(strict_types=1);

namespace Hn\McpServer\Hook;

use Hn\McpServer\Service\ParentVersioningService;
use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Versions the parent records of inline children changed in a workspace, see ParentVersioningService.
 *
 * Registered in ext_localconf.php as processDatamapClass and processCmdmapClass, so TYPO3 instantiates it (via DI)
 * for every DataHandler run, in the backend as well as on the CLI. It only collects the records a DataHandler run
 * touched and hands them to ParentVersioningService, which decides whether a parent has to be versioned.
 *
 * Example: an editor in a workspace saves a content element of a news record in the page module.
 * 1. The DataHandler versions the content element (core behaviour) and calls processDatamap_afterAllOperations().
 * 2. This hook passes tt_content:<uid> to the service, which finds the news record as outermost parent
 *    without a workspace version and versions it with a sub DataHandler ("version" command, action "new").
 * 3. That sub DataHandler runs processCmdmap_beforeStart()/processCmdmap_afterFinish() of this hook again;
 *    since it is a "version" command, both only maintain the suspend counter and propagate nothing.
 *
 * Propagation is suspended (self::$suspended > 0)
 * - while a "version" command is processed: publish (swap), stage change, discard (clearWSID/flush) and
 *   versioning itself (new). Those are triggered by the Workspaces module or by the core's own auto-versioning
 *   and start sub DataHandler instances which run the datamap/cmdmap hooks again. They must not create new
 *   parent versions, e.g. publishing a child must not version its parent once more.
 * - while this hook itself runs the service, so its own sub DataHandler does not trigger a propagation.
 * The counter is static so it is shared by all DataHandler instances of the request even if the hook is not
 * instantiated as a shared service.
 *
 * The core's auto-versioning on save (DataHandler::process_datamap() versioning a live record) also runs as a
 * sub DataHandler with a "version" command, so it is covered by the same suspension.
 *
 * TYPO3 does not call processCmdmap_afterFinish() when a command throws, so a suspension can be left behind.
 * Every outermost DataHandler run therefore starts unsuspended (see resetStaleSuspension()): no other
 * DataHandler, and thus no "version" command, can be running around it.
 */
final class PropagateChangesToParentDataHandlerHook
{
    private static int $suspended = 0;

    public function __construct(
        private readonly ParentVersioningService $parentVersioningService,
    ) {}

    /**
     * Called by DataHandler::process_datamap() before any record is written. Only clears a stale suspension.
     */
    public function processDatamap_beforeStart(DataHandler $dataHandler): void
    {
        $this->resetStaleSuspension($dataHandler);
    }

    /**
     * Versions the parents of all records created or updated by this DataHandler run.
     *
     * Collects every record of the datamap as table/uid pair and passes the list to propagate(). For each record
     * that is the child of an inline relation, ParentVersioningService then creates a workspace version of its
     * outermost parent that has none yet (e.g. the news record of a saved content element). Records without
     * a parent are ignored by the service.
     *
     * Called by DataHandler::process_datamap() once all records of the datamap are written, i.e. after every
     * save in FormEngine, the page module, the list module or an API call that creates or updates records.
     *
     * At this point the core has already created the workspace versions of the saved records, and NEW... ids of
     * created records are resolved in substNEWwithIDs. The datamap keys are live uids for existing records
     * (the service looks up their workspace version) and the final uid for new records.
     *
     * Saving a parent together with its children (e.g. the news form with its content elements) versions nothing
     * extra: the parent itself is in the datamap and already got a version, so the service finds no parent left
     * to version.
     */
    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $records = [];
        foreach ($dataHandler->datamap as $table => $recordsById) {
            foreach (array_keys($recordsById) as $id) {
                $uid = is_numeric($id) ? (int)$id : (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
                if ($uid > 0) {
                    $records[] = [(string)$table, $uid];
                }
            }
        }

        $this->propagate($dataHandler, $records);
    }

    /**
     * Called by DataHandler::process_cmdmap() before any command is executed.
     *
     * Suspends propagation if the cmdmap contains a "version" command, so neither this DataHandler run nor
     * any sub DataHandler it starts versions parents. processCmdmap_afterFinish() lifts the suspension again.
     */
    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        $this->resetStaleSuspension($dataHandler);
        if ($this->hasVersionCommand($dataHandler)) {
            self::$suspended++;
        }
    }

    /**
     * Called by DataHandler::process_cmdmap() after all commands are executed, before the outermost DataHandler
     * updates the reference index.
     *
     * - "version" commands: lifts the suspension of processCmdmap_beforeStart(); their records are not passed on.
     * - all other commands (delete, move, copy, localize, copyToLanguage, undelete, ...): passes the records of
     *   the cmdmap to the service, also when they come in one cmdmap together with "version" commands. In a
     *   workspace these commands create versions as well (delete and move placeholders, new records for copies
     *   and translations). For copy/localize/copyToLanguage the created record is passed too
     *   (copyMappingArray_merged), since only the copy belongs to a parent in the workspace.
     */
    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        if ($this->hasVersionCommand($dataHandler)) {
            self::$suspended = max(0, self::$suspended - 1);
        }

        $records = [];
        foreach ($dataHandler->cmdmap as $table => $commandsById) {
            foreach ($commandsById as $id => $commands) {
                if ($this->isVersionCommand($commands)) {
                    continue;
                }
                $records[] = [(string)$table, (int)$id];
                // copy, localize, copyToLanguage: the created record
                $newUid = $dataHandler->copyMappingArray_merged[$table][$id] ?? null;
                if (is_numeric($newUid)) {
                    $records[] = [(string)$table, (int)$newUid];
                }
            }
        }

        $this->propagate($dataHandler, $records);
    }

    /**
     * Runs the service unless propagation is suspended or the user works in the live workspace.
     *
     * Errors of the versioning sub DataHandler (e.g. no permission to edit the parent) are appended to the
     * errorLog of the calling DataHandler. The change of the child itself is kept in that case.
     *
     * @param list<array{0: string, 1: int}> $records table and uid of the touched records
     */
    private function propagate(DataHandler $dataHandler, array $records): void
    {
        if (self::$suspended > 0 || $records === [] || (int)($dataHandler->BE_USER->workspace ?? 0) <= 0) {
            return;
        }

        $errorLog = [];
        self::$suspended++;
        try {
            $this->parentVersioningService->propagate($records, $dataHandler->BE_USER, $errorLog);
        } finally {
            self::$suspended--;
        }
        $dataHandler->errorLog = array_merge($dataHandler->errorLog, $errorLog);
    }

    /**
     * Clears a suspension left behind by a DataHandler run that threw before processCmdmap_afterFinish().
     * Only done for the outermost DataHandler: sub instances run inside a "version" command or inside
     * propagate() and have to stay suspended.
     */
    private function resetStaleSuspension(DataHandler $dataHandler): void
    {
        if ($dataHandler->isOuterMostInstance()) {
            self::$suspended = 0;
        }
    }

    /**
     * Whether the cmdmap contains a workspace command (swap, setStage, clearWSID, flush, new, ...).
     */
    private function hasVersionCommand(DataHandler $dataHandler): bool
    {
        foreach ($dataHandler->cmdmap as $commandsById) {
            foreach ($commandsById as $commands) {
                if ($this->isVersionCommand($commands)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isVersionCommand(mixed $commands): bool
    {
        return is_array($commands) && array_key_exists('version', $commands);
    }
}
