<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Workspace\ParentVersioning;

use Hn\McpServer\Relation\InlineParentResolver;

final class InlineParentResolverTest extends AbstractParentVersioningTestCase
{
    public function testUidListChildOfALiveOnlyParentResolvesToTheParent(): void
    {
        self::assertSame(['tx_testrel_parent', 6], $this->resolveListChildParent(61));
    }

    public function testUidListChildKeptInTheParentVersionResolvesToTheParent(): void
    {
        self::assertSame(['tx_testrel_parent', 7], $this->resolveListChildParent(72));
    }

    public function testUidListChildRemovedFromTheParentVersionHasNoParent(): void
    {
        self::assertNull($this->resolveListChildParent(71));
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    private function resolveListChildParent(int $uid): ?array
    {
        $resolver = $this->get(InlineParentResolver::class);

        return $resolver->resolveParent('tx_testrel_listchild', $resolver->getRecord('tx_testrel_listchild', $uid), self::WORKSPACE_ID);
    }
}
