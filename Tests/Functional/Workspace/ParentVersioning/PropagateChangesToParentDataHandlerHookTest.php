<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace\ParentVersioning;

use Hn\McpServer\Hook\PropagateChangesToParentDataHandlerHook;

final class PropagateChangesToParentDataHandlerHookTest extends AbstractParentVersioningTestCase
{
    public function testChangingAChildVersionsItsParent(): void
    {
        $this->processDataMap(['tx_testrel_child' => [11 => ['title' => 'Changed child']]]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
        self::assertSame('Changed child', $this->getWorkspaceVersion('tx_testrel_child', 11)['title'] ?? null);
        // the core versions all children along with the parent
        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_child', 12));
    }

    public function testVersioningTheParentLeavesTheLiveSortingOfItsChildrenAlone(): void
    {
        // the core renumbers the sorting of the live children while versioning their parent
        // (RelationHandler::writeForeignField()); ParentVersioningService puts the live values back
        $this->processDataMap(['tx_testrel_child' => [11 => ['title' => 'Changed child']]]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
        self::assertSame(256, (int)$this->getLiveRecord('tx_testrel_child', 11)['sorting']);
        self::assertSame(512, (int)$this->getLiveRecord('tx_testrel_child', 12)['sorting']);
    }

    public function testNewGrandchildVersionsChildAndParent(): void
    {
        $this->processDataMap(['tx_testrel_grandchild' => ['NEW1' => [
            'pid' => 1,
            'title' => 'New grandchild',
            'parent_uid' => 11,
            'parent_table' => 'tx_testrel_child',
            'parent_field' => 'grandchildren',
        ]]]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_child', 11));
        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
    }

    public function testDeletingAChildVersionsItsParent(): void
    {
        $this->processCommandMap(['tx_testrel_child' => [11 => ['delete' => 1]]]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
    }

    public function testChangingARecordWithoutParentVersionsNothingElse(): void
    {
        $this->processDataMap(['tx_testrel_child' => [30 => ['title' => 'Changed']]]);

        self::assertSame([3, 8], $this->getWorkspaceUids('tx_testrel_parent'));
    }

    public function testRelationsCanOptOut(): void
    {
        $this->processDataMap(['tx_testrel_optout' => [41 => ['title' => 'Changed']]]);

        self::assertNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
    }

    public function testOnlyTheOutermostAncestorWithoutVersionIsVersioned(): void
    {
        // parent 2 already has a version, its child 21 has none
        $this->processDataMap(['tx_testrel_grandchild' => ['NEW1' => [
            'pid' => 1,
            'title' => 'New grandchild',
            'parent_uid' => 21,
            'parent_table' => 'tx_testrel_child',
            'parent_field' => 'grandchildren',
        ]]]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_child', 21));
        self::assertSame([3, 8], $this->getWorkspaceUids('tx_testrel_parent'));
    }

    public function testPublishingTheParentPublishesTheChildren(): void
    {
        $this->processDataMap([
            'tx_testrel_child' => [11 => ['title' => 'Changed child']],
            'tx_testrel_grandchild' => ['NEW1' => [
                'pid' => 1,
                'title' => 'New grandchild',
                'parent_uid' => 11,
                'parent_table' => 'tx_testrel_child',
                'parent_field' => 'grandchildren',
            ]],
        ]);
        $newGrandchildUid = $this->getWorkspaceUids('tx_testrel_grandchild');
        $newGrandchildUid = (int)end($newGrandchildUid);
        $parentVersion = $this->getWorkspaceVersion('tx_testrel_parent', 1);
        self::assertNotNull($parentVersion);

        $this->processCommandMap(['tx_testrel_parent' => [1 => ['version' => [
            'action' => 'swap',
            'swapWith' => $parentVersion['uid'],
            'notificationAlternativeRecipients' => [],
        ]]]]);

        self::assertSame('Changed child', $this->getLiveRecord('tx_testrel_child', 11)['title'] ?? null);
        self::assertSame(0, (int)($this->getLiveRecord('tx_testrel_grandchild', $newGrandchildUid)['t3ver_wsid'] ?? -1));
        // nothing of the family is left in the workspace, publishing did not create new parent versions
        self::assertNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
        self::assertNull($this->getWorkspaceVersion('tx_testrel_child', 11));
        self::assertNull($this->getWorkspaceVersion('tx_testrel_child', 12));
    }

    public function testNewGrandchildBelowVersionedAncestorsIsPublishedWithTheParent(): void
    {
        // parent 2 (version 3) and its child 23 (version 24) are already in the workspace, nothing gets versioned
        $this->processDataMap(['tx_testrel_grandchild' => ['NEW1' => [
            'pid' => 1,
            'title' => 'New grandchild',
            'parent_uid' => 23,
            'parent_table' => 'tx_testrel_child',
            'parent_field' => 'grandchildren',
        ]]]);
        $newGrandchildUid = $this->getWorkspaceUids('tx_testrel_grandchild');
        $newGrandchildUid = (int)end($newGrandchildUid);

        $this->processCommandMap(['tx_testrel_parent' => [2 => ['version' => [
            'action' => 'swap',
            'swapWith' => 3,
            'notificationAlternativeRecipients' => [],
        ]]]]);

        self::assertSame(0, (int)($this->getLiveRecord('tx_testrel_grandchild', $newGrandchildUid)['t3ver_wsid'] ?? -1));
    }

    public function testStageChangeOfTheParentAppliesToTheChildren(): void
    {
        $this->processDataMap(['tx_testrel_child' => [11 => ['title' => 'Changed child']]]);
        $parentVersion = $this->getWorkspaceVersion('tx_testrel_parent', 1);
        self::assertNotNull($parentVersion);

        $this->processCommandMap(['tx_testrel_parent' => [$parentVersion['uid'] => ['version' => [
            'action' => 'setStage',
            'stageId' => -10,
            'comment' => '',
            'notificationAlternativeRecipients' => [],
        ]]]]);

        self::assertSame(-10, (int)$this->getWorkspaceVersion('tx_testrel_child', 11)['t3ver_stage']);
        self::assertSame(-10, (int)$this->getWorkspaceVersion('tx_testrel_parent', 1)['t3ver_stage']);
    }

    public function testDiscardingTheParentDiscardsTheChildren(): void
    {
        $this->processDataMap(['tx_testrel_child' => [11 => ['title' => 'Changed child']]]);
        $parentVersion = $this->getWorkspaceVersion('tx_testrel_parent', 1);
        self::assertNotNull($parentVersion);

        $this->processCommandMap(['tx_testrel_parent' => [$parentVersion['uid'] => ['version' => ['action' => 'clearWSID']]]]);

        self::assertNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
        self::assertNull($this->getWorkspaceVersion('tx_testrel_child', 11));
        self::assertSame('Child of live-only parent', $this->getLiveRecord('tx_testrel_child', 11)['title'] ?? null);
    }

    public function testChangingAUidListChildVersionsItsParent(): void
    {
        $this->processDataMap(['tx_testrel_listchild' => [61 => ['title' => 'Changed list child']]]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_parent', 6));
        self::assertSame('Changed list child', $this->getWorkspaceVersion('tx_testrel_listchild', 61)['title'] ?? null);
        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_listchild', 62));
    }

    public function testDeletingAUidListChildVersionsItsParent(): void
    {
        $this->processCommandMap(['tx_testrel_listchild' => [62 => ['delete' => 1]]]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_parent', 6));
    }

    public function testChangingAUidListRecordWithoutParentVersionsNothingElse(): void
    {
        $this->processDataMap(['tx_testrel_listchild' => [63 => ['title' => 'Changed']]]);

        self::assertNull($this->getWorkspaceVersion('tx_testrel_parent', 6));
    }

    public function testPublishingTheParentPublishesItsUidListChildren(): void
    {
        $this->processDataMap(['tx_testrel_listchild' => [61 => ['title' => 'Changed list child']]]);
        $parentVersion = $this->getWorkspaceVersion('tx_testrel_parent', 6);
        self::assertNotNull($parentVersion);

        $this->processCommandMap(['tx_testrel_parent' => [6 => ['version' => [
            'action' => 'swap',
            'swapWith' => $parentVersion['uid'],
            'notificationAlternativeRecipients' => [],
        ]]]]);

        self::assertSame('Changed list child', $this->getLiveRecord('tx_testrel_listchild', 61)['title'] ?? null);
        self::assertSame('61,62', $this->getLiveRecord('tx_testrel_parent', 6)['list_children'] ?? null);
        self::assertNull($this->getWorkspaceVersion('tx_testrel_parent', 6));
        self::assertNull($this->getWorkspaceVersion('tx_testrel_listchild', 61));
    }

    public function testOtherCommandsInACmdmapWithAVersionCommandArePropagated(): void
    {
        $this->processCommandMap([
            'tx_testrel_parent' => [2 => ['version' => [
                'action' => 'swap',
                'swapWith' => 3,
                'notificationAlternativeRecipients' => [],
            ]]],
            'tx_testrel_child' => [11 => ['delete' => 1]],
        ]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
    }

    public function testASuspensionLeftBehindByAFailedRunIsCleared(): void
    {
        // processCmdmap_afterFinish() is not called when a "version" command throws
        $suspended = new \ReflectionProperty(PropagateChangesToParentDataHandlerHook::class, 'suspended');
        $suspended->setValue(null, 1);

        $this->processDataMap(['tx_testrel_child' => [11 => ['title' => 'Changed child']]]);

        self::assertNotNull($this->getWorkspaceVersion('tx_testrel_parent', 1));
        self::assertSame(0, $suspended->getValue());
    }
}
