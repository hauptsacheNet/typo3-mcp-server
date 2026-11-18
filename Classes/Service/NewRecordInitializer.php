<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\OrderedProviderList;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowDateTimeFields;
use TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseUserPermissionCheck;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Initializes a new record the way the backend form does when an editor clicks "new".
 *
 * Runs TYPO3's FormDataCompiler with the regular record pipeline (tcaDatabaseRecord),
 * so the row gets the same initial values as in the form: TCA defaults, user and page
 * TSconfig TCAdefaults (also type-specific ones), the given values (the &defVals
 * mechanism) and whatever extensions add, e.g. news fills "datetime" with "now".
 *
 * Only the providers that run before DatabaseRowDateTimeFields are used: from there
 * on the providers turn the row into form values (DateTime objects, select arrays,
 * relation lists), while everything before works on raw database values.
 */
class NewRecordInitializer
{
    /**
     * @param array $data Values the record is created with, keyed by field name
     * @return array|null The initialized row with raw database values, null if the
     *                    form pipeline could not be run for this record
     */
    public function initialize(string $table, int $pid, array $data): ?array
    {
        $defaultValues = [];
        $columns = $GLOBALS['TCA'][$table]['columns'] ?? [];
        foreach ($data as $fieldName => $value) {
            if ($fieldName !== 'pid' && is_scalar($value) && isset($columns[$fieldName])) {
                $defaultValues[$fieldName] = $value;
            }
        }

        // The form needs the page the record goes to. DataHandler lets an admin create
        // records on a page id that does not exist; there is no page TSconfig to
        // apply then, so the record is initialized like one on the root level.
        if ($pid > 0 && BackendUtility::getRecord('pages', $pid, 'uid') === null) {
            $pid = 0;
        }

        try {
            $formDataGroup = GeneralUtility::makeInstance(OrderedProviderList::class);
            $formDataGroup->setProviderList($this->getProviderList());
            $result = GeneralUtility::makeInstance(FormDataCompiler::class)->compile([
                'request' => GeneralUtility::makeInstance(SelectItemResolver::class)->createMinimalServerRequest($pid),
                'tableName' => $table,
                'vanillaUid' => $pid,
                'command' => 'new',
                'defaultValues' => $defaultValues === [] ? [] : [$table => $defaultValues],
            ], $formDataGroup);
        } catch (\Throwable) {
            return null;
        }

        return is_array($result['databaseRow'] ?? null) ? $result['databaseRow'] : null;
    }

    /**
     * The tcaDatabaseRecord providers that run before DatabaseRowDateTimeFields:
     * the ones it depends on and the ones that declare to run before it (like news'
     * NewsRowInitializeNew), each with everything they depend on in turn.
     */
    protected function getProviderList(): array
    {
        $providers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']['tcaDatabaseRecord'];

        $needed = [];
        $pending = [DatabaseRowDateTimeFields::class];
        while ($pending !== []) {
            $target = array_pop($pending);
            $predecessors = $providers[$target]['depends'] ?? [];
            foreach ($providers as $providerClassName => $providerConfig) {
                if (in_array($target, $providerConfig['before'] ?? [], true)) {
                    $predecessors[] = $providerClassName;
                }
            }
            foreach ($predecessors as $predecessor) {
                if (isset($providers[$predecessor]) && !isset($needed[$predecessor])) {
                    $needed[$predecessor] = true;
                    $pending[] = $predecessor;
                }
            }
        }

        foreach (array_keys($providers) as $providerClassName) {
            if (!isset($needed[$providerClassName])) {
                $providers[$providerClassName]['disabled'] = true;
            }
        }

        // Access is checked by the write itself (page access, DataHandler). The form's
        // check would only add ways for the initialization to fail.
        $providers[DatabaseUserPermissionCheck::class]['disabled'] = true;

        return $providers;
    }
}
