<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Workspaces\Event\ModifyVersionDifferencesEvent;

/**
 * Removes differences between a workspace version and its live record that are no changes for an editor.
 *
 * GridDataService::versionIsModified() uses these differences too, so children versioned along with their parent
 * would show up as "modified" instead of "unchanged" because of them:
 *
 * - The timestamps (ctrl.crdate and ctrl.tstamp): the core sets both to the time the version was created
 *   (DataHandler::insertNewCopyVersion()). Publishing keeps the live values anyway. They only show up as a
 *   difference at all when they are TCA columns of the table, which georgringer/news makes them for tt_content,
 *   for instance. tstamp matters because ParentVersioningService restores the live value the core overwrites
 *   while versioning (see there): without that restore live and version carry the same new timestamp, with it
 *   an untouched child versioned along with its parent differs in tstamp alone and would be "modified".
 * - Differences without visible change: RemoteServer::getRowDetails() compares the raw values, but versioning
 *   normalizes some of them (e.g. NULL becomes 0 in fields without nullable). The diff of the formatted values then
 *   contains neither <ins> nor <del>. Rich text fields are kept: the diff strips HTML tags, so a markup-only change
 *   (bold, a link target) has no <ins> or <del> either, but is a real change.
 */
#[AsEventListener(identifier: 'mcp-server/ignore-invisible-version-differences')]
final class IgnoreInvisibleVersionDifferencesListener
{
    public function __invoke(ModifyVersionDifferencesEvent $event): void
    {
        $table = (string)($event->getParameters()->table ?? '');
        $timestampFields = array_filter([
            (string)($GLOBALS['TCA'][$table]['ctrl']['crdate'] ?? ''),
            (string)($GLOBALS['TCA'][$table]['ctrl']['tstamp'] ?? ''),
        ]);

        $differences = array_values(array_filter(
            $event->getVersionDifferences(),
            static function (array $difference) use ($table, $timestampFields): bool {
                $field = (string)($difference['field'] ?? '');
                $isRichText = (bool)($GLOBALS['TCA'][$table]['columns'][$field]['config']['enableRichtext'] ?? false);

                return !in_array($field, $timestampFields, true)
                    && ($isRichText || preg_match('/<(ins|del)\b/', (string)($difference['content'] ?? '')) === 1);
            }
        ));

        $event->setVersionDifferences($differences);
    }
}
