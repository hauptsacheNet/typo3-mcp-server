/**
 * Google Apps Script — LLM stats ingest + shields.io endpoint.
 *
 * This file is the source of truth, committed for transparency.
 * To deploy:
 *
 *   1. Create a Google Sheet titled "TYPO3 MCP Server — LLM Stats".
 *      Add tabs named "Runs" and "Summary".
 *   2. Extensions → Apps Script → paste the contents of this file.
 *   3. Project Settings → Script Properties → add INGEST_TOKEN with a
 *      random secret (e.g. `openssl rand -hex 24`).
 *   4. Deploy → New deployment → Web app:
 *        - Execute as: Me
 *        - Who has access: Anyone
 *      Copy the deployment URL ending in /exec.
 *   5. Sheet → Share → "Anyone with the link: Viewer" so README links work.
 *   6. Add GitHub Actions secrets:
 *        - LLM_STATS_SHEET_URL   = the /exec URL
 *        - LLM_STATS_SHEET_TOKEN = the same token as INGEST_TOKEN
 *
 * Endpoints exposed by doGet:
 *   ?model=<key>          → shields.io endpoint JSON (latest pass-rate)
 *   ?format=table         → markdown table of all model summaries
 *   (no params)           → JSON map of model → { passed, total, percent }
 */

const RUNS_SHEET_NAME = 'Runs';
const SUMMARY_SHEET_NAME = 'Summary';

const RUNS_HEADERS = [
  'timestamp', 'commit', 'branch', 'run_url',
  'class', 'test', 'model', 'status',
  'duration', 'llm_calls', 'tool_calls', 'tool_errors',
];

const SUMMARY_HEADERS = ['model', 'passed', 'total', 'percent', 'last_run', 'last_commit'];

/* ------------------------------------------------------------------ Ingest */

function doPost(e) {
  const expected = PropertiesService.getScriptProperties().getProperty('INGEST_TOKEN');
  const auth = (e.parameter && e.parameter.token)
    || (e.postData && e.postData.contents && extractBearer_(e));
  if (!expected || auth !== expected) {
    return jsonResponse_({ error: 'unauthorized' }, 401);
  }

  let payload;
  try {
    payload = JSON.parse(e.postData.contents);
  } catch (err) {
    return jsonResponse_({ error: 'invalid json' }, 400);
  }

  const records = Array.isArray(payload.records) ? payload.records : [];
  if (records.length === 0) {
    return jsonResponse_({ inserted: 0 });
  }

  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const runs = ensureSheet_(ss, RUNS_SHEET_NAME, RUNS_HEADERS);

  const rows = records.map(r => [
    payload.timestamp || new Date().toISOString(),
    payload.commit || '',
    payload.branch || '',
    payload.run_url || '',
    r.class || '',
    r.test || '',
    r.model || '',
    r.status || '',
    r.duration ?? '',
    r.llm_calls ?? '',
    r.tool_calls ?? '',
    r.tool_errors ?? '',
  ]);
  runs.getRange(runs.getLastRow() + 1, 1, rows.length, RUNS_HEADERS.length).setValues(rows);

  recomputeSummary_(ss);

  return jsonResponse_({ inserted: rows.length });
}

function extractBearer_(e) {
  // Apps Script doesn't expose request headers directly; we accept the token
  // either via `?token=` or via a JSON body field `_token` as fallbacks.
  try {
    const body = JSON.parse(e.postData.contents);
    return body._token || null;
  } catch (err) {
    return null;
  }
}

/* ------------------------------------------------------------------ Read */

function doGet(e) {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  const summary = readSummary_(ss);

  if (e.parameter && e.parameter.model) {
    const row = summary[e.parameter.model];
    if (!row) {
      return jsonResponse_({
        schemaVersion: 1,
        label: e.parameter.model,
        message: 'no data',
        color: 'lightgrey',
      });
    }
    return jsonResponse_({
      schemaVersion: 1,
      label: e.parameter.model,
      message: row.percent + '%',
      color: badgeColor_(row.percent),
    });
  }

  if (e.parameter && e.parameter.format === 'table') {
    const lines = ['| Model | Passed | Total | % |', '|---|---|---|---|'];
    Object.keys(summary).sort().forEach(model => {
      const r = summary[model];
      lines.push(`| ${model} | ${r.passed} | ${r.total} | ${r.percent}% |`);
    });
    return ContentService.createTextOutput(lines.join('\n'))
      .setMimeType(ContentService.MimeType.TEXT);
  }

  return jsonResponse_(summary);
}

function badgeColor_(percent) {
  if (percent >= 90) return 'brightgreen';
  if (percent >= 75) return 'green';
  if (percent >= 60) return 'yellow';
  if (percent >= 40) return 'orange';
  return 'red';
}

/* ------------------------------------------------------------------ Summary */

function recomputeSummary_(ss) {
  const runs = ss.getSheetByName(RUNS_SHEET_NAME);
  if (!runs || runs.getLastRow() < 2) return;

  const data = runs.getRange(2, 1, runs.getLastRow() - 1, RUNS_HEADERS.length).getValues();

  // Find the most recent timestamp; the "current" pass-rate uses only the
  // latest run (so a flaky historical model doesn't poison the badge).
  let latestRun = '';
  for (const row of data) {
    const ts = String(row[0]);
    if (ts > latestRun) latestRun = ts;
  }

  const perModel = {}; // model => { passed, total, last_commit }
  for (const row of data) {
    if (String(row[0]) !== latestRun) continue;
    const model = String(row[6]);
    const status = String(row[7]);
    if (status === 'SKIP') continue;
    if (!perModel[model]) {
      perModel[model] = { passed: 0, total: 0, last_commit: String(row[1]) };
    }
    perModel[model].total += 1;
    if (status === 'PASS') perModel[model].passed += 1;
  }

  const summary = ensureSheet_(ss, SUMMARY_SHEET_NAME, SUMMARY_HEADERS);
  if (summary.getLastRow() > 1) {
    summary.getRange(2, 1, summary.getLastRow() - 1, SUMMARY_HEADERS.length).clearContent();
  }

  const rows = Object.keys(perModel).sort().map(model => {
    const r = perModel[model];
    const percent = r.total === 0 ? 0 : Math.round((r.passed / r.total) * 100);
    return [model, r.passed, r.total, percent, latestRun, r.last_commit];
  });
  if (rows.length > 0) {
    summary.getRange(2, 1, rows.length, SUMMARY_HEADERS.length).setValues(rows);
  }
}

function readSummary_(ss) {
  const sheet = ss.getSheetByName(SUMMARY_SHEET_NAME);
  if (!sheet || sheet.getLastRow() < 2) return {};
  const data = sheet.getRange(2, 1, sheet.getLastRow() - 1, SUMMARY_HEADERS.length).getValues();
  const out = {};
  for (const row of data) {
    out[String(row[0])] = {
      passed: Number(row[1]),
      total: Number(row[2]),
      percent: Number(row[3]),
      last_run: String(row[4]),
      last_commit: String(row[5]),
    };
  }
  return out;
}

/* ------------------------------------------------------------------ Helpers */

function ensureSheet_(ss, name, headers) {
  let sheet = ss.getSheetByName(name);
  if (!sheet) {
    sheet = ss.insertSheet(name);
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]).setFontWeight('bold');
    sheet.setFrozenRows(1);
  } else if (sheet.getLastRow() === 0) {
    sheet.getRange(1, 1, 1, headers.length).setValues([headers]).setFontWeight('bold');
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function jsonResponse_(obj, _status) {
  // Apps Script web apps can't set HTTP status, but shields.io and the
  // ingester only care about the body. Errors are conveyed in `error`.
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

/* ------------------------------------------------------------------ Tests */

function testIngest_() {
  const sample = {
    postData: {
      contents: JSON.stringify({
        _token: PropertiesService.getScriptProperties().getProperty('INGEST_TOKEN'),
        timestamp: new Date().toISOString(),
        commit: 'deadbeef',
        branch: 'main',
        run_url: 'https://example.invalid/run/1',
        records: [
          { class: 'X', test: 'testA', model: 'haiku-4.5', status: 'PASS', llm_calls: 3, tool_calls: 5, tool_errors: 0 },
          { class: 'X', test: 'testA', model: 'gpt-5.4-mini', status: 'FAIL', llm_calls: 4, tool_calls: 6, tool_errors: 1 },
        ],
      }),
    },
    parameter: {},
  };
  Logger.log(doPost(sample).getContent());
}
