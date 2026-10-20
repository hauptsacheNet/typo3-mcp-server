<?php

declare(strict_types=1);

namespace Hn\McpServer\Relation;

/**
 * A parent record somewhere above a child record.
 */
final readonly class Ancestor
{
    /**
     * @param int $uid uid the child points to: the live uid, or the only uid of a record new in the workspace
     * @param array<string, mixed>|null $workspaceRecord the record's version in the workspace, null if it has none
     */
    public function __construct(
        public string $table,
        public int $uid,
        public ?array $workspaceRecord,
    ) {}

    public function isInWorkspace(): bool
    {
        return $this->workspaceRecord !== null;
    }
}
