#!/usr/bin/env php
<?php

/**
 * Post LLM test results to a Google Sheet via an Apps Script web app.
 *
 * Reads .Build/llm-results.xml plus per-test stats from .Build/llm-stats/,
 * builds a flat list of { run × test × model } records, and POSTs them as
 * JSON. The Apps Script (see Build/llm-stats-apps-script.gs) appends rows
 * and recomputes a per-model summary used by the README badge endpoint.
 *
 * Required env vars:
 *   LLM_STATS_SHEET_URL    Apps Script web app deployment URL (.../exec)
 *   LLM_STATS_SHEET_TOKEN  Shared bearer token; must match INGEST_TOKEN
 *
 * Optional env vars (set automatically by GitHub Actions):
 *   GITHUB_SHA, GITHUB_REF_NAME, GITHUB_RUN_URL
 *
 * Usage:
 *   php Build/post-llm-stats.php
 *   php Build/post-llm-stats.php --dry-run
 *   php Build/post-llm-stats.php --xml=path/to/results.xml
 */

$xmlPath = __DIR__ . '/../.Build/llm-results.xml';
$dryRun  = false;

foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
    if (str_starts_with($arg, '--xml=')) {
        $xmlPath = substr($arg, strlen('--xml='));
    }
}

if (!file_exists($xmlPath)) {
    fwrite(STDERR, "JUnit XML not found: $xmlPath\n");
    exit(2);
}

$xml = simplexml_load_file($xmlPath);
if ($xml === false) {
    fwrite(STDERR, "Failed to parse JUnit XML: $xmlPath\n");
    exit(2);
}

$records = [];
if ($xml->getName() === 'testsuite') {
    collectFromSuite($xml, $records);
} else {
    foreach ($xml->testsuite as $suite) {
        collectFromSuite($suite, $records);
    }
}

if ($records === []) {
    fwrite(STDERR, "No test cases found in JUnit XML — nothing to post.\n");
    exit(0);
}

$payload = [
    'timestamp' => gmdate('c'),
    'commit'    => getenv('GITHUB_SHA') ?: '',
    'branch'    => getenv('GITHUB_REF_NAME') ?: '',
    'run_url'   => getenv('GITHUB_RUN_URL') ?: '',
    'records'   => $records,
];

if ($dryRun) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    fwrite(STDERR, sprintf("[dry-run] %d records would be posted.\n", count($records)));
    exit(0);
}

$url   = getenv('LLM_STATS_SHEET_URL');
$token = getenv('LLM_STATS_SHEET_TOKEN');
if (!$url || !$token) {
    fwrite(STDERR, "LLM_STATS_SHEET_URL or LLM_STATS_SHEET_TOKEN not set — skipping publish.\n");
    exit(0);
}

// Apps Script web apps can't read request headers, so the token rides in
// the JSON body. Apps Script also follows a 302 from /exec → googleusercontent
// for the actual response, hence FOLLOWLOCATION.
$payload['_token'] = $token;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fwrite(STDERR, "curl error: $err\n");
    exit(1);
}

if ($status < 200 || $status >= 300) {
    fwrite(STDERR, "Apps Script returned HTTP $status: $response\n");
    exit(1);
}

echo "Published " . count($records) . " records to Google Sheet (HTTP $status).\n";
exit(0);

function collectFromSuite(SimpleXMLElement $suite, array &$records): void
{
    foreach ($suite->testsuite as $child) {
        collectFromSuite($child, $records);
    }
    foreach ($suite->testcase as $testcase) {
        $name  = (string)$testcase['name'];
        $class = (string)$testcase['class'];

        $model    = 'unknown';
        $baseName = $name;
        if (preg_match('/^(.+)#(.+)$/', $name, $m)) {
            $baseName = $m[1];
            $model    = $m[2];
        } elseif (preg_match('/^(.+) with data set "(.+)"$/', $name, $m)) {
            $baseName = $m[1];
            $model    = $m[2];
        }

        $failed  = isset($testcase->failure) || isset($testcase->error);
        $skipped = isset($testcase->skipped);
        $status  = $failed ? 'FAIL' : ($skipped ? 'SKIP' : 'PASS');

        $stats = loadTestStats($class, $baseName, $model);

        $records[] = [
            'class'       => $class,
            'test'        => $baseName,
            'model'       => $model,
            'status'      => $status,
            'duration'    => (float)($testcase['time'] ?? 0),
            'llm_calls'   => $stats['llm_calls']   ?? null,
            'tool_calls'  => $stats['tool_calls']  ?? null,
            'tool_errors' => $stats['tool_errors'] ?? null,
        ];
    }
}

function loadTestStats(string $class, string $baseName, string $model): ?array
{
    $statsDir = __DIR__ . '/../.Build/llm-stats';
    $modelKey = $model === 'unknown' ? '' : $model;
    $file     = $statsDir . '/' . sha1($class . '::' . $baseName . '::' . $modelKey) . '.json';
    if (!file_exists($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}
