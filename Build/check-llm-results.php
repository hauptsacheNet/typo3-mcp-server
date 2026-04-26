#!/usr/bin/env php
<?php

/**
 * Aggregate LLM test results across models and apply majority-pass rule.
 *
 * Parses JUnit XML from paratest, groups test cases by method name
 * (stripping the model data-set suffix), and fails if any test case
 * passed in fewer than the required number of models.
 *
 * Usage: php Build/check-llm-results.php [--min-pass=3] [--xml=.Build/llm-results.xml]
 */

$minPass = 3;
$xmlPath = __DIR__ . '/../.Build/llm-results.xml';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--min-pass=')) {
        $minPass = (int)substr($arg, strlen('--min-pass='));
    }
    if (str_starts_with($arg, '--xml=')) {
        $xmlPath = substr($arg, strlen('--xml='));
    }
}

if (!file_exists($xmlPath)) {
    fwrite(STDERR, "JUnit XML not found: $xmlPath\n");
    fwrite(STDERR, "Run the LLM tests first: composer test:llm\n");
    exit(2);
}

$xml = simplexml_load_file($xmlPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse JUnit XML: $xmlPath\n");
    exit(2);
}

// Collect results grouped by base test name (without model suffix)
$testCases = []; // baseName => ['total' => N, 'passed' => N, 'models' => [...]]

// Handle both <testsuites><testsuite>... and root <testsuite>... JUnit formats
if ($xml->getName() === 'testsuite') {
    collectFromSuite($xml, $testCases);
} else {
    foreach ($xml->testsuite as $suite) {
        collectFromSuite($suite, $testCases);
    }
}

function collectFromSuite(SimpleXMLElement $suite, array &$testCases): void
{
    foreach ($suite->testsuite as $child) {
        collectFromSuite($child, $testCases);
    }
    foreach ($suite->testcase as $testcase) {
        $name = (string)$testcase['name'];
        $class = (string)$testcase['class'];

        // Extract model key from dataset suffix: "testMethod#model-key" or "testMethod with data set "model-key""
        $model = 'unknown';
        $baseName = $name;
        if (preg_match('/^(.+)#(.+)$/', $name, $m)) {
            $baseName = $m[1];
            $model = $m[2];
        } elseif (preg_match('/^(.+) with data set "(.+)"$/', $name, $m)) {
            $baseName = $m[1];
            $model = $m[2];
        }

        $key = $class . '::' . $baseName;

        if (!isset($testCases[$key])) {
            $testCases[$key] = ['total' => 0, 'passed' => 0, 'models' => []];
        }

        $testCases[$key]['total']++;

        $failed = isset($testcase->failure) || isset($testcase->error);
        $skipped = isset($testcase->skipped);

        $status = $failed ? 'FAIL' : ($skipped ? 'SKIP' : 'PASS');
        $testCases[$key]['models'][$model] = $status;
        $testCases[$key]['stats'][$model] = loadTestStats($class, $baseName, $model);

        if (!$failed && !$skipped) {
            $testCases[$key]['passed']++;
        }
    }
}

function loadTestStats(string $class, string $baseName, string $model): ?array
{
    $statsDir = __DIR__ . '/../.Build/llm-stats';
    $modelKey = $model === 'unknown' ? '' : $model;
    $file = $statsDir . '/' . sha1($class . '::' . $baseName . '::' . $modelKey) . '.json';
    if (!file_exists($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function formatStats(?array $stats): string
{
    if ($stats === null) {
        return '';
    }
    $calls = (int)($stats['llm_calls'] ?? 0);
    $toolCalls = (int)($stats['tool_calls'] ?? 0);
    $errors = (int)($stats['tool_errors'] ?? 0);
    $errColor = $errors > 0 ? "\033[31m" : "\033[2m";
    return " \033[2m[" . $calls . " calls, " . $toolCalls . " tools, "
        . $errColor . $errors . " err\033[2m]\033[0m";
}

// Evaluate results
$degraded = [];
$allPassed = true;

echo "LLM Test Results — Majority Pass Rule (min $minPass/" . count($testCases ? reset($testCases)['models'] : []) . " models)\n";
echo str_repeat('=', 80) . "\n\n";

ksort($testCases);
foreach ($testCases as $name => $result) {
    $shortName = preg_replace('/^.*\\\\/', '', $name);
    $passCount = $result['passed'];
    $totalCount = $result['total'];

    // Skip test cases where all runs were skipped
    $allSkipped = !array_diff($result['models'], ['SKIP']);
    if ($allSkipped) {
        echo "\033[33m-\033[0m $shortName (skipped)\n";
        continue;
    }

    $ok = $passCount >= $minPass;

    $modelDetails = [];
    foreach ($result['models'] as $model => $status) {
        $icon = match ($status) {
            'PASS' => "\033[32m✓\033[0m",
            'FAIL' => "\033[31m✗\033[0m",
            'SKIP' => "\033[33m-\033[0m",
        };
        $modelDetails[] = "$icon $model" . formatStats($result['stats'][$model] ?? null);
    }

    $statusIcon = $ok ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
    echo "$statusIcon $shortName ($passCount/$totalCount)\n";
    foreach ($modelDetails as $detail) {
        echo "  $detail\n";
    }

    if (!$ok) {
        $degraded[] = ['name' => $shortName, 'passed' => $passCount, 'total' => $totalCount, 'models' => $result['models']];
        $allPassed = false;
    }
}

echo "\n" . str_repeat('=', 80) . "\n";

// Distinguish "no API key available" (expected on fork PRs — GitHub does not share
// secrets with workflows triggered from forks) from "API key set but nothing ran"
// (real CI/test problem). Only the latter should fail the job.
$executedCount = count(array_filter($testCases, fn($r) => array_diff($r['models'], ['SKIP'])));
if ($executedCount === 0) {
    if (getenv('OPENROUTER_API_KEY') === false || getenv('OPENROUTER_API_KEY') === '') {
        fwrite(STDERR, "\033[33mNo LLM tests were executed (no OPENROUTER_API_KEY available — fork PR or local run without secret). Skipping majority-pass check.\033[0m\n");
        exit(0);
    }
    fwrite(STDERR, "\033[31mNo LLM tests were executed (all skipped) despite OPENROUTER_API_KEY being set. Check test runner.\033[0m\n");
    exit(1);
}

if ($allPassed) {
    echo "\033[32mAll test cases pass majority rule ($minPass+ models per case)\033[0m\n";
    exit(0);
}

echo "\n\033[31mDEGRADED test cases (fewer than $minPass models passed):\033[0m\n\n";
foreach ($degraded as $d) {
    echo "  {$d['name']} — {$d['passed']}/{$d['total']} passed\n";
    foreach ($d['models'] as $model => $status) {
        if ($status === 'FAIL') {
            echo "    \033[31m✗ $model\033[0m\n";
        }
    }
}
echo "\n";
exit(1);
