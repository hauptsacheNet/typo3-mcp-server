<?php

declare(strict_types=1);

namespace Hn\McpServer\Event;

/**
 * PSR-14 event dispatched after TableAccessService has loaded the field set
 * for a table (and a specific record type, when applicable).
 *
 * Listeners can add, remove, or reconfigure fields. Because every tool
 * (ReadTable, WriteTable, GetTableSchema, SearchTool) routes through
 * TableAccessService::getAvailableFields(), a change here propagates
 * everywhere consistently.
 *
 * Adding a computed read-only field
 * ---------------------------------
 * To advertise an enrichment field that the LLM should be able to discover
 * and opt into via the `fields` parameter, set the marker
 * ['mcp' => ['computed' => true]] on the field configuration. The schema
 * tool renders these in a dedicated section, ReadTable lets them through
 * the type filter, and WriteTable rejects them.
 */
final class AfterSchemaLoadEvent
{
    /**
     * @param string $table The table name
     * @param string $type The record type (e.g. CType value, empty for default)
     * @param array<string, array> $fields Field name => TCA-shaped config array
     * @param int|null $pid Page whose TSconfig applies, null when the schema is not
     *                      resolved for a particular page (then the first site root applies)
     */
    public function __construct(
        private readonly string $table,
        private readonly string $type,
        private array $fields,
        private readonly ?int $pid = null,
    ) {}

    public function getTable(): string
    {
        return $this->table;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    /**
     * @return array<string, array>
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * @param array<string, array> $fields
     */
    public function setFields(array $fields): void
    {
        $this->fields = $fields;
    }

    public function removeField(string $fieldName): void
    {
        unset($this->fields[$fieldName]);
    }

    public function addField(string $fieldName, array $configuration): void
    {
        $this->fields[$fieldName] = $configuration;
    }

    public function hasField(string $fieldName): bool
    {
        return isset($this->fields[$fieldName]);
    }
}
