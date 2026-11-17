<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Hn\McpServer\Utility\RecordFormattingUtility;
use TYPO3\CMS\Backend\View\BackendLayout\BackendLayout;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Columns (colPos) of a page's backend layout and the content types they accept
 * (allowedContentTypes / disallowedContentTypes, TYPO3 14.1+), checked like
 * DataHandlerContentElementRestrictionHook does.
 */
class ContentColumnService
{
    /**
     * @return list<array{colPos: int, name: string, allowed: list<string>, disallowed: list<string>}>
     */
    public function getColumns(int $pageId): array
    {
        if ($pageId <= 0) {
            return [];
        }

        try {
            $backendLayoutView = GeneralUtility::makeInstance(BackendLayoutView::class);
            $backendLayout = $backendLayoutView->getBackendLayoutForPage($pageId);
            $structure = $backendLayout->getStructure();
        } catch (\Throwable) {
            return [];
        }

        $columns = [];
        foreach ($structure['__items'] ?? [] as $item) {
            $value = $item['value'] ?? null;
            if ($value === null || $value === '' || !is_numeric($value)) {
                continue;
            }
            $colPos = (int)$value;
            $configuration = $this->getColumnConfiguration($backendLayoutView, $backendLayout, $structure, $colPos, $pageId);
            $label = (string)($item['label'] ?? '');
            $columns[] = [
                'colPos' => $colPos,
                'name' => $label !== '' ? TableAccessService::translateLabel($label) : 'Column ' . $colPos,
                'allowed' => GeneralUtility::trimExplode(',', (string)($configuration['allowedContentTypes'] ?? ''), true),
                'disallowed' => GeneralUtility::trimExplode(',', (string)($configuration['disallowedContentTypes'] ?? ''), true),
            ];
        }

        return $columns;
    }

    /**
     * @param array{allowed: list<string>, disallowed: list<string>} $column
     */
    public function acceptsType(array $column, string $cType): bool
    {
        if ($column['allowed'] !== [] && !in_array($cType, $column['allowed'], true)) {
            return false;
        }
        return !in_array($cType, $column['disallowed'], true);
    }

    /**
     * @param array{colPos: int, name: string} $column
     */
    public function formatColumnName(array $column): string
    {
        return sprintf('colPos %d "%s"', $column['colPos'], $column['name']);
    }

    /**
     * @param array{colPos: int, name: string, allowed: list<string>, disallowed: list<string>} $column
     */
    public function formatColumn(array $column): string
    {
        if ($column['allowed'] !== []) {
            $types = 'only ' . $this->formatTypes(array_values(array_diff($column['allowed'], $column['disallowed'])));
        } elseif ($column['disallowed'] !== []) {
            $types = 'all types except ' . $this->formatTypes($column['disallowed']);
        } else {
            $types = 'all types';
        }
        return $this->formatColumnName($column) . ': ' . $types;
    }

    /**
     * @param list<string> $cTypes
     */
    private function formatTypes(array $cTypes): string
    {
        if ($cTypes === []) {
            return 'no type';
        }
        return implode(', ', array_map(static function (string $cType): string {
            $label = RecordFormattingUtility::getContentTypeLabel($cType);
            return $label !== '' && $label !== ucfirst(str_replace('_', ' ', $cType))
                ? $cType . ' (' . $label . ')'
                : $cType;
        }, $cTypes));
    }

    /**
     * The fallback for TYPO3 13 mirrors getColPosConfigurationForPage() of TYPO3 14.
     */
    private function getColumnConfiguration(
        BackendLayoutView $backendLayoutView,
        BackendLayout $backendLayout,
        array $structure,
        int $colPos,
        int $pageId
    ): array {
        if (method_exists($backendLayoutView, 'getColPosConfigurationForPage')) {
            return $backendLayoutView->getColPosConfigurationForPage($backendLayout, $colPos, $pageId);
        }

        foreach ($structure['__config']['backend_layout.']['rows.'] ?? [] as $row) {
            foreach ($row['columns.'] ?? [] as $column) {
                if (!isset($column['colPos']) || $column['colPos'] === '' || (int)$column['colPos'] !== $colPos) {
                    continue;
                }
                if (!empty($column['allowed.']['CType'] ?? '') && !isset($column['allowedContentTypes'])) {
                    $column['allowedContentTypes'] = $column['allowed.']['CType'];
                }
                if (!empty($column['disallowed.']['CType'] ?? '') && !isset($column['disallowedContentTypes'])) {
                    $column['disallowedContentTypes'] = $column['disallowed.']['CType'];
                }
                return $column;
            }
        }
        return [];
    }
}
