#!/usr/bin/env bash
set -euo pipefail

# This script bootstraps a minimal TYPO3 installation for running tests.
# It uses an SQLite database stored under var/sqlite.db and creates
# a basic site configuration pointing to http://localhost.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BIN="$ROOT_DIR/vendor/bin/typo3"
DB_PATH="$ROOT_DIR/var/sqlite.db"

$BIN setup \
  --driver=sqlite \
  --dbname="$DB_PATH" \
  --admin-username=admin \
  --admin-user-password=Admin123! \
  --admin-email=admin@example.com \
  --project-name="TYPO3 MCP Server" \
  --create-site=http://localhost \
  --server-type=other \
  --no-interaction \
  --force

if [ ! -f "$ROOT_DIR/public/index.php" ]; then
  cat > "$ROOT_DIR/public/index.php" <<'PHP'
<?php
call_user_func(static function () {
    $classLoader = require __DIR__ . '/../vendor/autoload.php';
    \TYPO3\CMS\Core\Core\SystemEnvironmentBuilder::run(1);
    \TYPO3\CMS\Core\Core\Bootstrap::init($classLoader, true)->get(\TYPO3\CMS\Core\Http\Application::class)->run();
});
PHP
fi
