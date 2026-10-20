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
 * - The creation date (ctrl.crdate): the core sets crdate of every new version to the time it was created
 *   (DataHandler::insertNewCopyVersion()). Publishing keeps the crdate of the live record anyway.
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
        $creationDateField = (string)($GLOBALS['TCA'][$table]['ctrl']['crdate'] ?? '');

        $differences = array_values(array_filter(
            $event->getVersionDifferences(),
            static function (array $difference) use ($table, $creationDateField): bool {
                $field = (string)($difference['field'] ?? '');
                $isRichText = (bool)($GLOBALS['TCA'][$table]['columns'][$field]['config']['enableRichtext'] ?? false);

                return ($creationDateField === '' || $field !== $creationDateField)
                    && ($isRichText || preg_match('/<(ins|del)\b/', (string)($difference['content'] ?? '')) === 1);
            }
        ));

        $event->setVersionDifferences($differences);
    }
}
