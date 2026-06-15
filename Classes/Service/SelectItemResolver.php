<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the full list of select field items using TYPO3's FormDataCompiler.
 *
 * This runs the same pipeline as the backend FormEngine, including:
 * - Static TCA items
 * - foreign_table items
 * - itemsProcFunc / itemsProcessors callbacks
 * - TSconfig addItems / removeItems / keepItems
 * - authMode filtering
 * - Language and doktype restrictions
 */
class SelectItemResolver
{
    /**
     * Runtime cache for compiled form data, keyed by "table:pid"
     * @var array<string, array>
     */
    private array $cache = [];

    /**
     * Resolve the fully processed list of select items for a field.
     *
     * @param string $table Table name
     * @param string $field Field name
     * @param array $record Record context (used for pid resolution and itemsProcFunc context).
     *                      For updates: merge existing DB record with submitted data.
     *                      For creates: submitted data with pid.
     *                      For schema display: empty array (pid defaults to 0).
     * @return array|null Array with 'values' and 'labels' keys, or null on failure
     */
    public function resolveSelectItems(string $table, string $field, array $record = []): ?array
    {
        if (empty($table) || empty($field)) {
            return null;
        }

        $fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field]['config'] ?? null;
        if (!$fieldConfig || ($fieldConfig['type'] ?? '') !== 'select') {
            return null;
        }

        try {
            $formData = $this->compileFormData($table, $record);
        } catch (\Throwable $e) {
            // FormDataCompiler can fail for various reasons (missing DB records, etc.)
            // Return null to signal callers to use their fallback logic
            return null;
        }

        $items = $formData['processedTca']['columns'][$field]['config']['items'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        return $this->parseItems($items);
    }

    /**
     * Compile form data using TYPO3's FormDataCompiler.
     *
     * The record's field values are fed into the compiler as "defaultValues"
     * (the same mechanism the backend uses for &defVals[...] when opening a new
     * record). DatabaseRowInitializeNew copies these into the databaseRow, which
     * is what itemsProcFunc callbacks receive as $parameters['row']. Without this,
     * dynamic select fields whose items depend on sibling field values cannot be
     * resolved — e.g. b13/container's tt_content.colPos itemsProcFunc reads
     * tx_container_parent (and the current colPos) off the row to expose the
     * container's grid columns.
     *
     * @param string $table Table name
     * @param array $record Record context for databaseRow and pid resolution
     * @return array The compiled form data
     */
    private function compileFormData(string $table, array $record): array
    {
        $pid = (int)($record['pid'] ?? 0);
        $defaultValues = $this->extractDefaultValues($table, $record);

        // The cache must key on the full record context, not just table:pid:
        // itemsProcFunc results can differ for two records on the same page
        // (e.g. depending on tx_container_parent or CType), so a table:pid key
        // would hand back a stale item list for a different record.
        $cacheKey = $table . ':' . $pid . ':' . md5(serialize($defaultValues));

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $request = $this->createMinimalServerRequest($pid);

        $formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        $formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class);

        $input = [
            'request' => $request,
            'tableName' => $table,
            'vanillaUid' => $pid,
            'command' => 'new',
            'defaultValues' => $defaultValues === [] ? [] : [$table => $defaultValues],
        ];

        // Because we seed the record's own field values (above), FormEngine would
        // otherwise echo any seeded select value back as a "[ invalid value ]"
        // pseudo-item (TcaSelectItems::addInvalidItemsFromDatabase) so the backend
        // form does not silently drop an out-of-range stored value. For *validation*
        // that is exactly wrong: it would make every submitted value resolve as
        // "allowed". We want the canonical item set, so suppress that affordance.
        $restoreTca = $this->disableNoMatchingValueElement($table);
        try {
            $result = $formDataCompiler->compile($input, $formDataGroup);
        } finally {
            $restoreTca();
        }
        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Temporarily set disableNoMatchingValueElement on every select column of the
     * table so the compiler does not add the seeded value back as an invalid
     * pseudo-item. Returns a restore closure to revert the TCA afterwards.
     *
     * @return callable(): void
     */
    private function disableNoMatchingValueElement(string $table): callable
    {
        $columns = &$GLOBALS['TCA'][$table]['columns'];
        if (!is_array($columns)) {
            return static function (): void {};
        }

        $originals = [];
        foreach ($columns as $fieldName => $fieldConfig) {
            if (($fieldConfig['config']['type'] ?? '') !== 'select') {
                continue;
            }
            $originals[$fieldName] = $columns[$fieldName]['config']['disableNoMatchingValueElement'] ?? null;
            $columns[$fieldName]['config']['disableNoMatchingValueElement'] = true;
        }

        return static function () use ($table, $originals): void {
            foreach ($originals as $fieldName => $original) {
                if ($original === null) {
                    unset($GLOBALS['TCA'][$table]['columns'][$fieldName]['config']['disableNoMatchingValueElement']);
                } else {
                    $GLOBALS['TCA'][$table]['columns'][$fieldName]['config']['disableNoMatchingValueElement'] = $original;
                }
            }
        };
    }

    /**
     * Reduce the record context to the scalar field values that can be seeded as
     * FormEngine default values. Non-scalar values (inline/file collections, etc.)
     * and the pid (handled separately via vanillaUid) are dropped: they are never
     * itemsProcFunc inputs and could trip up the compiler pipeline.
     *
     * @param string $table Table name
     * @param array $record Record context
     * @return array<string, scalar> Field name => value
     */
    private function extractDefaultValues(string $table, array $record): array
    {
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $defaultValues = [];
        foreach ($record as $fieldName => $value) {
            if ($fieldName === 'pid') {
                continue;
            }
            if (!is_scalar($value)) {
                continue;
            }
            if (!isset($columns[$fieldName])) {
                continue;
            }
            $defaultValues[$fieldName] = $value;
        }

        return $defaultValues;
    }

    /**
     * Create a minimal PSR-7 ServerRequest with site context for TSconfig resolution.
     */
    private function createMinimalServerRequest(int $pid): \Psr\Http\Message\ServerRequestInterface
    {
        $serverParams = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'HTTP_HOST' => 'localhost',
            'HTTPS' => 'off',
            'SERVER_PORT' => 80,
        ];

        $request = new ServerRequest('http://localhost/', 'GET', 'php://input', [], $serverParams);

        // Try to attach site context for proper TSconfig resolution
        try {
            $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
            $site = $siteFinder->getSiteByPageId($pid);
            $request = $request->withAttribute('site', $site);
            $request = $request->withAttribute('language', $site->getDefaultLanguage());
        } catch (\Throwable $e) {
            // No site found for this pid — proceed without site context.
            // TSconfig resolution will still work for global TSconfig.
        }

        $normalizedParams = NormalizedParams::createFromServerParams($serverParams);
        $request = $request->withAttribute('normalizedParams', $normalizedParams);

        return $request;
    }

    /**
     * Parse resolved items into the values/labels structure.
     *
     * @param array $items Resolved items from FormDataCompiler
     * @return array Array with 'values' and 'labels' keys
     */
    private function parseItems(array $items): array
    {
        $result = [
            'values' => [],
            'labels' => [],
        ];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $value = $item['value'] ?? ($item[1] ?? '');
            $label = $item['label'] ?? ($item[0] ?? '');

            // Skip dividers
            if ((string)$value === '--div--') {
                continue;
            }

            $stringValue = (string)$value;
            if ($stringValue !== '') {
                $result['values'][] = $stringValue;
                $result['labels'][$stringValue] = $label;
            }
        }

        return $result;
    }
}
