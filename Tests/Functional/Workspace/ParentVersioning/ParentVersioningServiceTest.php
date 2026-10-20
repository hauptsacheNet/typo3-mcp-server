<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace\ParentVersioning;

use Hn\McpServer\Service\ParentVersioningService;

final class ParentVersioningServiceTest extends AbstractParentVersioningTestCase
{
    public function testTheParentIsVersionedInTheStageOfTheChild(): void
    {
        $results = $this->get(ParentVersioningService::class)->propagate([['tx_testrel_child', 52]], $GLOBALS['BE_USER']);

        $parentVersion = $this->getWorkspaceVersion('tx_testrel_parent', 5);
        self::assertNotNull($parentVersion);
        self::assertSame((int)$parentVersion['uid'], $results[0]['versionUid']);
        self::assertSame(-10, (int)$parentVersion['t3ver_stage']);
        // the existing child version is reused, not duplicated
        self::assertSame(52, (int)$this->getWorkspaceVersion('tx_testrel_child', 51)['uid']);
    }

    public function testNothingHappensWithoutWorkspace(): void
    {
        $GLOBALS['BE_USER']->setTemporaryWorkspace(0);

        self::assertSame([], $this->get(ParentVersioningService::class)->propagate([['tx_testrel_child', 52]], $GLOBALS['BE_USER']));
    }
}
