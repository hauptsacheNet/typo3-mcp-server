<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves a plausible-but-wrong field name to the real field it was meant to be.
 *
 * LLM clients regularly invent field names that read naturally but do not exist.
 * The most expensive one by far is `tt_content_type` / `content_type` instead of
 * `CType`: it costs a full extra round trip on nearly every content element
 * creation. Plain Levenshtein cannot bridge that gap (`tt_content_type` vs
 * `CType` is 11 edits apart), so matching runs in layers:
 *
 *   1. case-insensitive exact match      (`ctype`            -> `CType`)
 *   2. normalized match                  (`body_text`        -> `bodytext`)
 *   3. semantic aliases from TCA ctrl    (`tt_content_type`  -> `CType`,
 *                                         `page_type`        -> `doktype`,
 *                                         `title` on tt_content -> `header`)
 *   4. Levenshtein, as a last resort     (`bodytxt`          -> `bodytext`)
 *
 * Layer 3 is driven entirely by TCA ctrl, so it works for every table including
 * third-party ones — there is no per-table hardcoded map.
 */
class FieldNameSuggestionService
{
    /**
     * Semantic keys (normalized, table prefix already stripped) mapped to the
     * TableAccessService accessor that resolves the real column from TCA ctrl.
     */
    protected const CTRL_ALIASES = [
        'type' => 'getTypeFieldName',
        'recordtype' => 'getTypeFieldName',
        'contenttype' => 'getTypeFieldName',
        'elementtype' => 'getTypeFieldName',
        'doctype' => 'getTypeFieldName',
        'pagetype' => 'getTypeFieldName',
        'title' => 'getLabelFieldName',
        'label' => 'getLabelFieldName',
        'name' => 'getLabelFieldName',
        'heading' => 'getLabelFieldName',
        'headline' => 'getLabelFieldName',
        'header' => 'getLabelFieldName',
        'subject' => 'getLabelFieldName',
        'hidden' => 'getHiddenFieldName',
        'disabled' => 'getHiddenFieldName',
        'disable' => 'getHiddenFieldName',
    ];
    // Deliberately no sorting aliases: ctrl.sortby is control-only and rejected
    // by WriteTable, so pointing at it would just buy a second failed call.

    /**
     * Language-related aliases. Kept separate because they must stay invisible
     * on installations without language support.
     */
    protected const LANGUAGE_ALIASES = [
        'language' => 'getLanguageFieldName',
        'lang' => 'getLanguageFieldName',
        'locale' => 'getLanguageFieldName',
        'languagecode' => 'getLanguageFieldName',
        'syslanguage' => 'getLanguageFieldName',
        'translationparent' => 'getTranslationParentFieldName',
        'translationorigin' => 'getTranslationParentFieldName',
        'translationof' => 'getTranslationParentFieldName',
    ];

    /**
     * Keys that mean "the page this record lives on". `pid` is a DataHandler
     * control field rather than a TCA column, so it needs its own mapping.
     */
    protected const PID_ALIASES = [
        'page' => 'pid',
        'pageid' => 'pid',
        'pageuid' => 'pid',
        'parentpage' => 'pid',
        'parentid' => 'pid',
        'targetpage' => 'pid',
    ];

    /**
     * Tokens that carry no meaning of their own and only wrap the real name.
     */
    protected const NOISE_TOKENS = ['record', 'element', 'item', 'entry', 'the'];

    /**
     * Tokens that may be dropped from the end of a name without changing it.
     */
    protected const TRAILING_NOISE_TOKENS = ['field', 'value', 'id', 'uid'];

    protected TableAccessService $tableAccessService;
    protected LanguageService $languageService;

    public function __construct(
        ?TableAccessService $tableAccessService = null,
        ?LanguageService $languageService = null
    ) {
        $this->tableAccessService = $tableAccessService ?? GeneralUtility::makeInstance(TableAccessService::class);
        $this->languageService = $languageService ?? GeneralUtility::makeInstance(LanguageService::class);
    }

    /**
     * Build the hint appended to "field does not exist" errors.
     *
     * Always returns something actionable: either the field the caller most
     * likely meant, or a pointer at the tool that lists the real ones. The
     * leading space lets callers concatenate it onto a finished sentence.
     */
    public function getUnknownFieldHint(string $table, string $fieldName): string
    {
        $suggestion = $this->suggest($table, $fieldName);

        if ($suggestion !== null) {
            return " Did you mean '" . $suggestion . "'?";
        }

        return " Use GetTableSchema for table '" . $table . "' to see the available fields.";
    }

    /**
     * Find the real field name the caller most likely meant.
     *
     * @return string|null The suggested field name, or null if nothing matches closely enough
     */
    public function suggest(string $table, string $fieldName): ?string
    {
        $fieldName = trim($fieldName);
        if ($fieldName === '') {
            return null;
        }

        $candidates = $this->getCandidateFields($table);
        if (empty($candidates)) {
            return null;
        }

        // Layer 1: only the casing is wrong (the classic `ctype` -> `CType`).
        foreach ($candidates as $candidate) {
            if (strcasecmp($candidate, $fieldName) === 0) {
                return $candidate;
            }
        }

        $keys = $this->buildLookupKeys($table, $fieldName);
        if (empty($keys)) {
            return null;
        }

        // Layer 2: same name after normalization, e.g. `body_text` -> `bodytext`.
        // Runs before the alias layer so a table that really does own a column
        // named `type` wins over its ctrl type field.
        $normalizedCandidates = [];
        foreach ($candidates as $candidate) {
            $normalizedCandidates[$candidate] = $this->normalize($candidate);
        }

        foreach ($keys as $key) {
            foreach ($normalizedCandidates as $candidate => $normalizedCandidate) {
                if ($normalizedCandidate === $key) {
                    return $candidate;
                }
            }
        }

        // Layer 3: semantic aliases resolved through TCA ctrl.
        foreach ($keys as $key) {
            $resolved = $this->resolveAlias($table, $key);
            if ($resolved !== null && in_array($resolved, $candidates, true)) {
                return $resolved;
            }
        }

        // Layer 4: genuine typos.
        return $this->findByEditDistance($keys, $normalizedCandidates);
    }

    /**
     * Fields the caller could legitimately have meant.
     *
     * Existence is decided by the TCA columns list (that is what the callers'
     * validation checks), but anything MCP deliberately hides — excluded
     * fields, TSconfig-disabled fields, translation handling on single-language
     * installations — must not be suggested either.
     *
     * @return string[]
     */
    protected function getCandidateFields(string $table): array
    {
        $columns = array_keys($GLOBALS['TCA'][$table]['columns'] ?? []);
        if (empty($columns)) {
            return [];
        }

        $hasLanguageSupport = $this->hasLanguageSupport();
        $languageFields = $hasLanguageSupport ? [] : array_filter([
            $this->tableAccessService->getLanguageFieldName($table),
            $this->tableAccessService->getTranslationParentFieldName($table),
            $this->tableAccessService->getTranslationSourceFieldName($table),
        ]);

        $candidates = [];
        foreach ($columns as $column) {
            if (in_array($column, $languageFields, true)) {
                continue;
            }
            if (!$this->tableAccessService->canAccessField($table, $column)) {
                continue;
            }
            $candidates[] = $column;
        }

        // `pid` is a DataHandler control field, not a TCA column, but it is a
        // valid (and frequently mis-named) write target.
        $candidates[] = 'pid';

        return $candidates;
    }

    /**
     * Build the lookup keys for a field name, most specific first.
     *
     * `tt_content_type` yields ['ttcontenttype', 'type'], which is what lets
     * the alias layer reach `CType` even though the strings share almost
     * nothing.
     *
     * @return string[]
     */
    protected function buildLookupKeys(string $table, string $fieldName): array
    {
        $tokens = $this->tokenize($fieldName);
        if (empty($tokens)) {
            return [];
        }

        $tableTokens = $this->tokenize($table);

        // Drop a leading table name ("tt_content_type", "news_title") and
        // meaningless wrappers ("record_type").
        $stripped = $tokens;
        while (count($stripped) > 1 && $this->isPrefixToken($stripped[0], $tableTokens)) {
            array_shift($stripped);
        }

        $keys = [];
        foreach ([$tokens, $stripped] as $set) {
            $keys[] = implode('', $set);

            $trimmed = $set;
            while (count($trimmed) > 1 && in_array(end($trimmed), self::TRAILING_NOISE_TOKENS, true)) {
                array_pop($trimmed);
                $keys[] = implode('', $trimmed);
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * Split a name into lowercase words across snake_case and camelCase.
     *
     * @return string[]
     */
    protected function tokenize(string $name): array
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name) ?? $name;
        $spaced = str_replace(['_', '-', '.', ' '], ' ', $spaced);
        $parts = preg_split('/\s+/', strtolower($spaced)) ?: [];

        return array_values(array_filter($parts, static fn($part) => $part !== ''));
    }

    protected function normalize(string $name): string
    {
        return implode('', $this->tokenize($name));
    }

    /**
     * @param string[] $tableTokens
     */
    protected function isPrefixToken(string $token, array $tableTokens): bool
    {
        if (in_array($token, self::NOISE_TOKENS, true)) {
            return true;
        }

        $singular = rtrim($token, 's');
        foreach ($tableTokens as $tableToken) {
            if ($token === $tableToken || $singular === rtrim($tableToken, 's')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a semantic key to a real column via TCA ctrl.
     */
    protected function resolveAlias(string $table, string $key): ?string
    {
        if (isset(self::PID_ALIASES[$key])) {
            return self::PID_ALIASES[$key];
        }

        $aliases = self::CTRL_ALIASES;
        if ($this->hasLanguageSupport()) {
            $aliases += self::LANGUAGE_ALIASES;
        }

        if (!isset($aliases[$key])) {
            return null;
        }

        $accessor = $aliases[$key];
        $resolved = $this->tableAccessService->$accessor($table);

        return is_string($resolved) && $resolved !== '' ? $resolved : null;
    }

    /**
     * Last resort for real typos. Only returns a match when it is unambiguous —
     * two candidates at the same distance mean we would be guessing.
     *
     * @param string[] $keys
     * @param array<string, string> $normalizedCandidates Field name => normalized name
     */
    protected function findByEditDistance(array $keys, array $normalizedCandidates): ?string
    {
        foreach ($keys as $key) {
            $maxDistance = max(1, min(3, intdiv(strlen($key), 4)));
            $best = $maxDistance + 1;
            $matches = [];

            foreach ($normalizedCandidates as $candidate => $normalizedCandidate) {
                $distance = levenshtein($key, $normalizedCandidate);
                if ($distance > $maxDistance) {
                    continue;
                }
                if ($distance < $best) {
                    $best = $distance;
                    $matches = [$candidate];
                } elseif ($distance === $best) {
                    $matches[] = $candidate;
                }
            }

            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return null;
    }

    /**
     * Translation is hidden entirely on installations with a single language,
     * so language field names must not leak through suggestions either.
     */
    protected function hasLanguageSupport(): bool
    {
        return count($this->languageService->getAvailableIsoCodes()) > 1;
    }
}
