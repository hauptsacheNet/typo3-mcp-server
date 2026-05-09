#!/usr/bin/env php
<?php

/**
 * Aggregate LLM test results into a one-row-per-run benchmark record and
 * post it to a Google Sheet via an Apps Script web app.
 *
 * The sheet schema is: timestamp, branch, commit, run_url, total, <one
 * column per model with that model's pass count>. Model columns are added
 * dynamically as new models appear. Per-test detail is intentionally not
 * stored — drill into the GitHub Actions run for that.
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

$tests        = []; // baseName => true if executed in at least one model
$modelPasses  = []; // model => int (PASS count)

if ($xml->getName() === 'testsuite') {
    collectFromSuite($xml, $tests, $modelPasses);
} else {
    foreach ($xml->testsuite as $suite) {
        collectFromSuite($suite, $tests, $modelPasses);
    }
}

$total = count(array_filter($tests));
if ($total === 0) {
    fwrite(STDERR, "No executed tests found in JUnit XML — nothing to post.\n");
    exit(0);
}

ksort($modelPasses);

$payload = [
    'timestamp' => gmdate('c'),
    'branch'    => getenv('GITHUB_REF_NAME') ?: '',
    'commit'    => getenv('GITHUB_SHA') ?: '',
    'run_url'   => getenv('GITHUB_RUN_URL') ?: '',
    'total'     => $total,
    'models'    => $modelPasses,
];

if ($dryRun) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$url   = getenv('LLM_STATS_SHEET_URL');
$token = getenv('LLM_STATS_SHEET_TOKEN');
if (!$url || !$token) {
    fwrite(STDERR, "LLM_STATS_SHEET_URL or LLM_STATS_SHEET_TOKEN not set — skipping publish.\n");
    exit(0);
}

// Apps Script web apps can't read request headers, so the token rides in
// the JSON body. /exec returns 302 to googleusercontent for the response,
// hence FOLLOWLOCATION.
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

// Apps Script always responds 200 even for auth/parse failures, so the body
// is the real signal. Require an explicit `ok: true` to declare success.
$decoded = json_decode((string)$response, true);
if (!is_array($decoded) || empty($decoded['ok'])) {
    $detail = is_array($decoded) && isset($decoded['error'])
        ? $decoded['error']
        : substr((string)$response, 0, 200);
    fwrite(STDERR, "Apps Script reported failure: $detail\n");
    exit(1);
}

echo "Published run with $total tests across " . count($modelPasses) . " models (row {$decoded['row']}).\n";
exit(0);

function collectFromSuite(SimpleXMLElement $suite, array &$tests, array &$modelPasses): void
{
    foreach ($suite->testsuite as $child) {
        collectFromSuite($child, $tests, $modelPasses);
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

        $key = $class . '::' . $baseName;
        $tests[$key] = ($tests[$key] ?? false) || !$skipped;

        $modelPasses[$model] = $modelPasses[$model] ?? 0;
        if (!$failed && !$skipped) {
            $modelPasses[$model]++;
        }
    }
}
