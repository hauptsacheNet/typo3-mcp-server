<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace\ParentVersioning;

use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Workspaces\Event\ModifyVersionDifferencesEvent;

final class IgnoreInvisibleVersionDifferencesListenerTest extends AbstractParentVersioningTestCase
{
    public function testTheCreationDateIsRemovedFromTheDifferences(): void
    {
        $differences = $this->dispatch('tx_testrel_child', [
            ['field' => 'crdate', 'label' => 'Creation date', 'content' => '<del>old</del><ins>new</ins>'],
            ['field' => 'title', 'label' => 'Title', 'content' => '<del>old</del><ins>new</ins>'],
        ]);

        self::assertSame(['title'], array_column($differences, 'field'));
    }

    public function testOnlyCreationDateDifferencesLeaveNoDifferences(): void
    {
        $differences = $this->dispatch('tx_testrel_child', [
            ['field' => 'crdate', 'label' => 'Creation date', 'content' => '<del>old</del><ins>new</ins>'],
        ]);

        self::assertSame([], $differences);
    }

    public function testTablesWithoutCreationDateKeepAColumnOfThatName(): void
    {
        // tx_testrel_parent has no ctrl.crdate, a column of that name is an ordinary field there
        $differences = $this->dispatch('tx_testrel_parent', [
            ['field' => 'crdate', 'label' => 'Creation date', 'content' => '<del>old</del><ins>new</ins>'],
        ]);

        self::assertSame(['crdate'], array_column($differences, 'field'));
    }

    public function testDifferencesWithoutVisibleChangeAreRemoved(): void
    {
        // e.g. NULL in live and 0 in the version: both are formatted the same, the diff marks nothing
        $differences = $this->dispatch('tx_testrel_parent', [
            ['field' => 'categories', 'label' => 'Categories', 'content' => ''],
            ['field' => 'title', 'label' => 'Title', 'content' => 'same text'],
            ['field' => 'added', 'label' => 'Added', 'content' => 'text <ins>added</ins>'],
            ['field' => 'removed', 'label' => 'Removed', 'content' => 'text <del>removed</del>'],
        ]);

        self::assertSame(['added', 'removed'], array_column($differences, 'field'));
    }

    public function testRichTextDifferencesWithoutVisibleChangeAreKept(): void
    {
        // The diff strips HTML tags, so a markup-only change (e.g. a new link target) marks nothing
        $differences = $this->dispatch('tx_testrel_child', [
            ['field' => 'bodytext', 'label' => 'Text', 'content' => 'same text'],
            ['field' => 'title', 'label' => 'Title', 'content' => 'same text'],
        ]);

        self::assertSame(['bodytext'], array_column($differences, 'field'));
    }

    /**
     * @param list<array{field: string, label: string, content: string}> $differences
     * @return list<array{field: string, label: string, content: string}>
     */
    private function dispatch(string $table, array $differences): array
    {
        $parameters = new \stdClass();
        $parameters->table = $table;

        return $this->get(EventDispatcherInterface::class)
            ->dispatch(new ModifyVersionDifferencesEvent($differences, [], $parameters))
            ->getVersionDifferences();
    }
}
