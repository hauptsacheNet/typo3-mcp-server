<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\Utility\BackendUtility;
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
     * Runtime cache for compiled form data, keyed by "table:pid:hash(defaults)"
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

        $resolved = $this->parseItems($items);

        // FormDataCompiler runs the field's itemsProcFunc which is free to
        // REPLACE the items array entirely (e.g. tt_content.colPos's
        // BackendLayoutView::colPosListItemProcFunc replaces with the page's
        // backend layout columns; b13/container's colPos itemsProcFunc replaces
        // with the active container's grid column). For permissive validation
        // we additionally accept anything that the static TCA items list +
        // page TSconfig addItems advertise, so dynamic colPos values stay
        // valid even if the row doesn't currently match the itemsProcFunc's
        // narrow filter (e.g. a schema lookup with no row context).
        $augment = $this->getStaticAndTsconfigItems($table, $field, (int)($record['pid'] ?? 0));
        foreach ($augment['values'] as $value) {
            if (!in_array($value, $resolved['values'], true)) {
                $resolved['values'][] = $value;
                $resolved['labels'][$value] = $augment['labels'][$value] ?? $value;
            }
        }

        return $resolved;
    }

    /**
     * Read the field's static TCA items and merge in TSconfig addItems for
     * the page so the result mirrors what TcaSelectItems would produce
     * BEFORE itemsProcFunc gets a chance to replace them.
     *
     * @return array{values: array<int,string>, labels: array<string,string>}
     */
    private function getStaticAndTsconfigItems(string $table, string $field, int $pid): array
    {
        $items = (array)($GLOBALS['TCA'][$table]['columns'][$field]['config']['items'] ?? []);

        $tsConfig = BackendUtility::getPagesTSconfig($pid);
        $addItems = $tsConfig['TCEFORM.'][$table . '.'][$field . '.']['addItems.'] ?? null;
        if (is_array($addItems)) {
            foreach ($addItems as $value => $label) {
                // Skip nested entries like "34." that carry icon/group sub-config
                if (str_ends_with((string)$value, '.')) {
                    continue;
                }
                $items[] = ['label' => (string)$label, 'value' => (string)$value];
            }
        }

        return $this->parseItems($items);
    }

    /**
     * Compile form data using TYPO3's FormDataCompiler.
     *
     * The submitted record values are forwarded to FormDataCompiler via the
     * `defaultValues` input — TYPO3's DatabaseRowInitializeNew picks them up
     * for `command=new` and seeds them into databaseRow before TcaSelectItems
     * runs. This is the only way we can give itemsProcFunc callbacks the row
     * context they need (e.g. b13/container's colPos itemsProcFunc keys off
     * `tx_container_parent` to expose the container's grid columns).
     *
     * @param string $table Table name
     * @param array $record Record context for databaseRow and pid resolution
     * @return array The compiled form data
     */
    private function compileFormData(string $table, array $record): array
    {
        $pid = (int)($record['pid'] ?? 0);
        $defaultValues = $this->buildDefaultValues($table, $record);
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

        $result = $formDataCompiler->compile($input, $formDataGroup);
        $this->cache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Reduce the submitted record to scalar TCA columns suitable for seeding
     * databaseRow on a `command=new` compilation. uid/pid and non-scalar
     * payloads (inline child arrays, …) are dropped — we only care about
     * giving itemsProcFunc callbacks enough context to choose the right
     * items list.
     */
    private function buildDefaultValues(string $table, array $record): array
    {
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        $defaults = [];
        foreach ($record as $field => $value) {
            if ($field === 'uid' || $field === 'pid') {
                continue;
            }
            if (!isset($columns[$field])) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $defaults[$field] = $value;
            }
        }
        return $defaults;
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

            // Items synthesized by TcaSelectItems::addInvalidItemsFromDatabase()
            // for non-matching databaseRow values carry a magic group="none"
            // marker. They exist so the form can still render the bad value;
            // for our validation purposes they would let any bogus value
            // validate against itself, so drop them.
            if (($item['group'] ?? null) === 'none') {
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
