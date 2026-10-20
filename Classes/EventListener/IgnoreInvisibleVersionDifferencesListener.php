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
 *   (DataHandler::insertNewCopyVersion()). They show up as a difference only where they are TCA columns of the
 *   table, which georgringer/news makes them for tt_content, for instance.
 *   crdate never reaches live: DataHandlerHook::version_swap() keeps the live value ("Do not update the
 *   creation date of the live record while publishing").
 *   tstamp does reach live, it is swapped along with the record, so after publishing even an untouched child
 *   carries the time its version was written. It is filtered all the same: it says nothing about the content,
 *   and it differs here only because ParentVersioningService restores the live tstamp the core overwrites while
 *   versioning (see there) — without that restore, live and version carry the same new timestamp and the
 *   module sees no difference either.
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
