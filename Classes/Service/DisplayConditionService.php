<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowDateTimeFields;
use TYPO3\CMS\Backend\Form\FormDataProvider\EvaluateDisplayConditions;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Describes and evaluates TCA "displayCond" settings of record fields.
 *
 * A field with a displayCond is only shown in the backend form when its condition
 * matches, so a [Required] setting only applies then. GetTableSchema uses describe()
 * to tell the client when a field is required, WriteTable uses evaluate() to check
 * required fields only when the backend form would show them.
 *
 * Evaluation is done by TYPO3's own EvaluateDisplayConditions form data provider.
 * It is limited to conditions that can be judged on raw record values: FIELD
 * conditions on plain columns, REC:NEW and HIDE_FOR_NON_ADMINS. USER and VERSION
 * conditions, and FIELD conditions on relation columns whose form value is built
 * from other tables (inline, file, group, MM), are reported as not evaluable.
 */
class DisplayConditionService
{
    /**
     * Column types whose form value differs from the raw database value in a way
     * that changes the outcome of FIELD conditions.
     */
    private const NOT_EVALUABLE_FIELD_TYPES = ['inline', 'file', 'group', 'folder', 'flex', 'user', 'none', 'imageManipulation'];

    /**
     * Readable form of a displayCond, e.g. "stage_type = video-stage".
     *
     * @param string|array $displayCond The displayCond as configured in TCA
     * @return string|null The readable condition, '' if the condition always matches
     *                     for a user who sees the field, null if it cannot be described
     */
    public function describe(string|array $displayCond): ?string
    {
        $description = $this->describeRecursive($displayCond);
        return $description === null ? null : $description['text'];
    }

    /**
     * Whether the backend form shows a field with the given displayCond for a record.
     *
     * @param string $table Table of the record
     * @param string|array $displayCond The displayCond as configured in TCA
     * @param array $row Raw values of the record (after the write)
     * @param bool $isNew Whether the record is about to be created
     * @return bool|null True if shown, false if hidden, null if the condition cannot be evaluated
     */
    public function evaluate(string $table, string|array $displayCond, array $row, bool $isNew): ?bool
    {
        $conditions = $this->collectConditionStrings($displayCond);
        if ($conditions === null) {
            return null;
        }

        foreach ($conditions as $condition) {
            $parts = GeneralUtility::trimExplode(':', $condition, false, 4);
            switch ($parts[0]) {
                case 'FIELD':
                    $fieldName = $parts[1] ?? '';
                    $formValue = $this->getFormValue($table, $fieldName, $row);
                    if ($formValue === false) {
                        return null;
                    }
                    $formValue = $formValue['value'];
                    $row[$fieldName] = $formValue;
                    break;
                case 'REC':
                case 'HIDE_FOR_NON_ADMINS':
                    break;
                default:
                    // USER conditions may depend on anything, VERSION on the workspace overlay
                    return null;
            }
        }

        // The uid tells REC:NEW conditions whether the record exists
        $row['uid'] = $isNew ? 'NEW' . bin2hex(random_bytes(4)) : (int)($row['uid'] ?? 0);

        $fieldName = '__mcp_display_condition';
        $formData = [
            'tableName' => $table,
            'databaseRow' => $row,
            'processedTca' => [
                'columns' => [
                    $fieldName => [
                        'displayCond' => $displayCond,
                        'config' => ['type' => 'none'],
                    ],
                ],
            ],
        ];

        try {
            $formData = GeneralUtility::makeInstance(EvaluateDisplayConditions::class)->addData($formData);
        } catch (\Throwable) {
            // Malformed condition: the backend form would fail as well
            return null;
        }

        return isset($formData['processedTca']['columns'][$fieldName]);
    }

    /**
     * @return array{text: string}|null text is '' when the condition always matches
     */
    private function describeRecursive(mixed $condition): ?array
    {
        if (is_string($condition)) {
            $text = $this->describeSingleCondition($condition);
            return $text === null ? null : ['text' => $text];
        }
        if (!is_array($condition) || count($condition) !== 1) {
            return null;
        }

        $operator = strtoupper((string)array_key_first($condition));
        $subConditions = reset($condition);
        if (($operator !== 'AND' && $operator !== 'OR') || !is_array($subConditions) || $subConditions === []) {
            return null;
        }

        $parts = [];
        foreach ($subConditions as $key => $subCondition) {
            $subKey = strtoupper((string)$key);
            if (($subKey === 'AND' || $subKey === 'OR') && is_array($subCondition)) {
                $subCondition = [$subKey => $subCondition];
            }
            $description = $this->describeRecursive($subCondition);
            if ($description === null) {
                return null;
            }
            if ($description['text'] === '') {
                if ($operator === 'OR') {
                    // One alternative always matches, so the whole group does
                    return ['text' => ''];
                }
                continue;
            }
            $parts[] = ['text' => $description['text'], 'isGroup' => is_array($subCondition)];
        }

        if ($parts === []) {
            return ['text' => ''];
        }
        if (count($parts) === 1) {
            return ['text' => $parts[0]['text']];
        }
        // Nested groups get parentheses so "a and (b or c)" stays unambiguous
        $texts = array_map(
            static fn(array $part): string => $part['isGroup'] ? '(' . $part['text'] . ')' : $part['text'],
            $parts
        );
        return ['text' => implode($operator === 'AND' ? ' and ' : ' or ', $texts)];
    }

    private function describeSingleCondition(string $condition): ?string
    {
        $parts = GeneralUtility::trimExplode(':', $condition, false, 4);
        switch ($parts[0]) {
            case 'FIELD':
                return $this->describeFieldCondition($parts[1] ?? '', $parts[2] ?? '', $parts[3] ?? null);
            case 'HIDE_FOR_NON_ADMINS':
                // Always true for admins; non-admins never see the field
                return ($GLOBALS['BE_USER'] ?? null)?->isAdmin() ? '' : null;
            case 'REC':
                $operand = strtolower($parts[2] ?? '');
                if (($parts[1] ?? '') !== 'NEW' || !in_array($operand, ['true', 'false'], true)) {
                    return null;
                }
                return $operand === 'true' ? 'the record is new' : 'the record already exists';
            default:
                return null;
        }
    }

    private function describeFieldCondition(string $fieldName, string $operator, ?string $operand): ?string
    {
        if ($fieldName === '' || $operand === null) {
            return null;
        }
        $list = static fn(string $value): string => implode(', ', GeneralUtility::trimExplode(',', $value, true));
        switch ($operator) {
            case '=':
                return $operand === '' ? $fieldName . ' is empty' : $fieldName . ' = ' . $operand;
            case '!=':
                return $operand === '' ? $fieldName . ' is not empty' : $fieldName . ' != ' . $operand;
            case 'IN':
                return $fieldName . ' is one of ' . $list($operand);
            case '!IN':
                return $fieldName . ' is none of ' . $list($operand);
            case 'REQ':
                $operand = strtolower($operand);
                if ($operand === 'true') {
                    return $fieldName . ' is set';
                }
                return $operand === 'false' ? $fieldName . ' is not set' : null;
            case '>':
            case '<':
            case '>=':
            case '<=':
                return $fieldName . ' ' . $operator . ' ' . $operand;
            case '-':
            case '!-':
                $range = GeneralUtility::trimExplode('-', $operand);
                if (count($range) !== 2) {
                    return null;
                }
                return $fieldName . ($operator === '-' ? ' is between ' : ' is not between ') . $range[0] . ' and ' . $range[1];
            case 'BIT':
                return $fieldName . ' has bit ' . $operand . ' set';
            case '!BIT':
                return $fieldName . ' does not have bit ' . $operand . ' set';
            default:
                return null;
        }
    }

    /**
     * All single condition strings of a displayCond, null if it is malformed.
     *
     * @return string[]|null
     */
    private function collectConditionStrings(mixed $condition): ?array
    {
        if (is_string($condition)) {
            return [$condition];
        }
        if (!is_array($condition)) {
            return null;
        }
        $conditions = [];
        foreach ($condition as $subCondition) {
            $subConditions = $this->collectConditionStrings($subCondition);
            if ($subConditions === null) {
                return null;
            }
            $conditions = array_merge($conditions, $subConditions);
        }
        return $conditions;
    }

    /**
     * The value a FIELD condition sees for a field, the way the backend form has it:
     * the raw value, select values as the list of selected items, datetime values as
     * TYPO3's DatabaseRowDateTimeFields provider prepares them (on TYPO3 14 a
     * DateTimeImmutable or null, depending on the field's nullable/dbType settings).
     *
     * @return array{value: mixed}|false false if the value cannot be judged on the raw record
     */
    private function getFormValue(string $table, string $fieldName, array $row): array|false
    {
        // Sheet references only exist within flex forms
        if ($fieldName === '' || str_contains($fieldName, '.')) {
            return false;
        }
        $value = $row[$fieldName] ?? null;
        $config = $GLOBALS['TCA'][$table]['columns'][$fieldName]['config'] ?? null;
        if (!is_array($config)) {
            // Control columns like pid or uid are compared as they are
            return ['value' => $value];
        }

        $type = (string)($config['type'] ?? '');
        if (in_array($type, self::NOT_EVALUABLE_FIELD_TYPES, true) || !empty($config['MM'])) {
            return false;
        }
        if (in_array($type, ['select', 'category'], true)) {
            if ($value === null || $value === '') {
                return ['value' => []];
            }
            return ['value' => is_array($value) ? array_values($value) : GeneralUtility::trimExplode(',', (string)$value, true)];
        }
        if (is_bool($value)) {
            $value = (int)$value;
        }
        if ($type === 'datetime') {
            return $this->getDateTimeFormValue($table, $fieldName, $config, $value);
        }
        return ['value' => $value];
    }

    /**
     * @return array{value: mixed}|false
     */
    private function getDateTimeFormValue(string $table, string $fieldName, array $config, mixed $value): array|false
    {
        if ($value !== null && !is_int($value) && !is_string($value)) {
            return false;
        }
        try {
            $formData = GeneralUtility::makeInstance(DatabaseRowDateTimeFields::class)->addData([
                'tableName' => $table,
                'databaseRow' => [$fieldName => $value],
                'processedTca' => ['columns' => [$fieldName => ['config' => $config]]],
            ]);
        } catch (\Throwable) {
            return false;
        }
        return ['value' => $formData['databaseRow'][$fieldName] ?? null];
    }
}
