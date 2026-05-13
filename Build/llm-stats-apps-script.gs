/**
 * Google Apps Script — LLM benchmark ingest + shields.io endpoint.
 *
 * Schema: one row per CI run.
 *   timestamp | branch | commit | run_url | total | <model_1> | <model_2> | ...
 *
 * Model columns are added on demand: when a payload references a model
 * not yet in the header row, a new column is appended. Each model column
 * holds that model's PASS count for the run; `total` is the number of
 * distinct test methods executed (skipped-by-all are excluded).
 *
 * Deployment:
 *   1. Create a Google Sheet titled "TYPO3 MCP Server — LLM Benchmark".
 *   2. Extensions → Apps Script → paste this file.
 *   3. Project Settings → Script Properties → INGEST_TOKEN = <random secret>.
 *   4. Deploy → New deployment → Web app, "Execute as: Me",
 *      "Who has access: Anyone". Copy the /exec URL.
 *   5. Sheet → Share → "Anyone with the link: Viewer".
 *   6. GitHub Actions secrets:
 *        LLM_STATS_SHEET_URL   = the /exec URL
 *        LLM_STATS_SHEET_TOKEN = INGEST_TOKEN value
 */

const SHEET_NAME = 'Runs';
const BASE_HEADERS = ['timestamp', 'branch', 'commit', 'run_url', 'total'];

function doPost(e) {
  const expected = PropertiesService.getScriptProperties().getProperty('INGEST_TOKEN');
  let payload;
  try {
    payload = JSON.parse(e.postData.contents);
  } catch (err) {
    return jsonResponse_({ error: 'invalid json' });
  }
  if (!expected || payload._token !== expected) {
    return jsonResponse_({ error: 'unauthorized' });
  }

  const sheet = ensureSheet_();
  const headers = readHeaders_(sheet);

  const models = payload.models || {};
  for (const name of Object.keys(models)) {
    if (headers.indexOf(name) === -1) {
      sheet.getRange(1, headers.length + 1)
        .setValue(name)
        .setFontWeight('bold');
      headers.push(name);
    }
  }

  const row = new Array(headers.length).fill('');
  row[0] = payload.timestamp ? new Date(payload.timestamp) : new Date();
  row[1] = payload.branch || '';
  row[2] = payload.commit || '';
  row[3] = payload.run_url || '';
  row[4] = Number(payload.total || 0);
  for (const [name, passed] of Object.entries(models)) {
    row[headers.indexOf(name)] = Number(passed);
  }
  sheet.appendRow(row);

  return jsonResponse_({ ok: true, row: sheet.getLastRow() });
}

function doGet(e) {
  const sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(SHEET_NAME);
  if (!sheet || sheet.getLastRow() < 2) {
    return jsonResponse_({ error: 'no data' });
  }
  const headers = readHeaders_(sheet);
  const lastRow = sheet.getRange(sheet.getLastRow(), 1, 1, headers.length).getValues()[0];
  const total = Number(lastRow[headers.indexOf('total')] || 0);

  if (e && e.parameter && e.parameter.model) {
    const model = e.parameter.model;
    const idx = headers.indexOf(model);
    if (idx === -1 || lastRow[idx] === '') {
      return jsonResponse_({
        schemaVersion: 1, label: model, message: 'no data', color: 'lightgrey',
      });
    }
    const passed = Number(lastRow[idx]);
    const percent = total === 0 ? 0 : Math.round((passed / total) * 100);
    return jsonResponse_({
      schemaVersion: 1,
      label: model,
      message: `${passed}/${total}`,
      color: badgeColor_(percent),
    });
  }

  const out = {
    timestamp: lastRow[0],
    branch: lastRow[1],
    commit: lastRow[2],
    run_url: lastRow[3],
    total,
    models: {},
    percentages: {},
  };
  for (let i = BASE_HEADERS.length; i < headers.length; i++) {
    if (lastRow[i] === '') continue;
    const passed = Number(lastRow[i]);
    out.models[headers[i]] = passed;
    out.percentages[headers[i]] = total === 0 ? 0 : Math.round((passed / total) * 100);
  }
  return jsonResponse_(out);
}

function ensureSheet_() {
  const ss = SpreadsheetApp.getActiveSpreadsheet();
  let sheet = ss.getSheetByName(SHEET_NAME);
  if (!sheet) sheet = ss.insertSheet(SHEET_NAME);
  if (sheet.getLastRow() === 0) {
    sheet.getRange(1, 1, 1, BASE_HEADERS.length)
      .setValues([BASE_HEADERS])
      .setFontWeight('bold');
    sheet.setFrozenRows(1);
  }
  return sheet;
}

function readHeaders_(sheet) {
  return sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0].map(String);
}

function badgeColor_(p) {
  if (p >= 90) return 'brightgreen';
  if (p >= 75) return 'green';
  if (p >= 60) return 'yellow';
  if (p >= 40) return 'orange';
  return 'red';
}

function jsonResponse_(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}

function testIngest_() {
  const sample = {
    postData: { contents: JSON.stringify({
      _token: PropertiesService.getScriptProperties().getProperty('INGEST_TOKEN'),
      timestamp: new Date().toISOString(),
      branch: 'main', commit: 'deadbeef', run_url: 'https://example.invalid/run/1',
      total: 20,
      models: { 'haiku-4.5': 18, 'gpt-5.4-mini': 14 },
    })},
    parameter: {},
  };
  Logger.log(doPost(sample).getContent());
}
