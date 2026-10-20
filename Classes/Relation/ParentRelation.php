<?php

declare(strict_types=1);

namespace Hn\McpServer\Relation;

/**
 * An inline/file TCA column of $parentTable that owns its child records.
 *
 * Two ways to store the relation are supported (see DataHandler::getRelationFieldType()):
 * - "field": the child points back to its parent via $foreignField (tt_content -> news, sys_file_reference -> tt_content)
 * - "list": the parent column holds a comma-separated list of child uids
 */
final readonly class ParentRelation
{
    public const TYPE_FIELD = 'field';
    public const TYPE_LIST = 'list';

    /**
     * @param array<string, string> $matchFields foreign_match_fields of the column
     * @param string|null $sortByField the field the children are sorted by (foreign_sortby, else ctrl.sortby)
     */
    public function __construct(
        public string $parentTable,
        public string $parentField,
        public string $childTable,
        public string $type,
        public ?string $foreignField = null,
        public ?string $foreignTableField = null,
        public array $matchFields = [],
        public ?string $sortByField = null,
    ) {}

    /**
     * Returns the uid of the parent record if $childRow belongs to this "field" relation, null otherwise.
     *
     * @param array<string, mixed> $childRow
     */
    public function resolveParentUidFromChild(array $childRow): ?int
    {
        if ($this->type !== self::TYPE_FIELD || $this->foreignField === null) {
            return null;
        }
        $parentUid = (int)($childRow[$this->foreignField] ?? 0);
        if ($parentUid <= 0) {
            return null;
        }
        if ($this->foreignTableField !== null && (string)($childRow[$this->foreignTableField] ?? '') !== $this->parentTable) {
            return null;
        }
        foreach ($this->matchFields as $field => $value) {
            if ((string)($childRow[$field] ?? '') !== (string)$value) {
                return null;
            }
        }

        return $parentUid;
    }
}
