#!/usr/bin/env bash
set -euo pipefail

#
# Run tests in a Docker container with configurable PHP version, database backend,
# and test suite. Supports matrix testing across PHP × DBMS combinations.
#
# Usage:
#   Build/runTests.sh                              # Functional tests, PHP 8.3, SQLite
#   Build/runTests.sh -p 8.4 -d postgres           # PHP 8.4 with PostgreSQL
#   Build/runTests.sh -s lint                       # Lint check
#   Build/runTests.sh --filter=ReadTableTool        # Filter specific tests
#   Build/runTests.sh -h                            # Show help
#

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_VERSION="8.3"
DBMS="sqlite"
TEST_SUITE="functional"
EXTRA_ARGS=()

# Unique prefix for this run (used for container and network names)
RUN_ID="typo3-mcp-tests-$$"
CONTAINER_NAME="${RUN_ID}"
DB_CONTAINER_NAME="${RUN_ID}-db"
NETWORK_NAME="${RUN_ID}-net"

# DB defaults (overridden per DBMS below)
DB_HOST=""
DB_PORT=""
DB_USER="root"
DB_PASSWORD="root"
DB_NAME="typo3_test"
DB_DOCKER_IMAGE=""

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- phpunit-args]

Options:
    -p <version>    PHP version: 8.2, 8.3 (default), 8.4, 8.5
    -d <dbms>       Database backend: sqlite (default), mysql, mariadb, postgres
    -s <suite>      Test suite: functional (default), lint, e2e
    -h, --help      Show this help

Any arguments after -- are passed directly to phpunit.
Short filter syntax (without --) is also supported:
    $(basename "$0") --filter=ReadTableTool

Examples:
    $(basename "$0")                                    # All functional tests, SQLite
    $(basename "$0") -p 8.4                              # PHP 8.4, SQLite
    $(basename "$0") -d mysql                            # PHP 8.3, MySQL
    $(basename "$0") -p 8.2 -d postgres                  # PHP 8.2, PostgreSQL
    $(basename "$0") --filter=ReadTableToolTest           # Filter by class
    $(basename "$0") -s lint                              # Lint all PHP files
    TYPO3_BASE_URL=https://my.ddev.site $(basename "$0") -s e2e  # E2E against running TYPO3
    $(basename "$0") -- --filter=testReadRecordsByPid     # Filter by method
EOF
    exit 0
}

# Parse arguments
while [ $# -gt 0 ]; do
    case "$1" in
        -p)
            PHP_VERSION="$2"
            shift 2
            ;;
        -d)
            DBMS="$2"
            shift 2
            ;;
        -s)
            TEST_SUITE="$2"
            shift 2
            ;;
        -h|--help)
            usage
            ;;
        --)
            shift
            EXTRA_ARGS+=("$@")
            break
            ;;
        *)
            EXTRA_ARGS+=("$1")
            shift
            ;;
    esac
done

IMAGE="php:${PHP_VERSION}-cli"

# Validate inputs
case "${PHP_VERSION}" in
    8.2|8.3|8.4|8.5) ;;
    *) echo "Error: unsupported PHP version '${PHP_VERSION}'. Use 8.2, 8.3, 8.4, or 8.5." >&2; exit 1 ;;
esac

case "${DBMS}" in
    sqlite|mysql|mariadb|postgres) ;;
    *) echo "Error: unsupported DBMS '${DBMS}'. Use sqlite, mysql, mariadb, or postgres." >&2; exit 1 ;;
esac

case "${TEST_SUITE}" in
    functional|lint|e2e) ;;
    *) echo "Error: unsupported test suite '${TEST_SUITE}'. Use functional, lint, or e2e." >&2; exit 1 ;;
esac

# Cleanup function — removes containers and network
cleanup() {
    set +e
    docker rm -f "${CONTAINER_NAME}" "${RUN_ID}-web" "${RUN_ID}-db" "${RUN_ID}-pw" >/dev/null 2>&1
    docker rm -f "${DB_CONTAINER_NAME}" >/dev/null 2>&1
    docker network rm "${NETWORK_NAME}" >/dev/null 2>&1
    set -e
}
trap cleanup EXIT

# Configure DBMS-specific settings
configure_db_env() {
    case "${DBMS}" in
        sqlite)
            # No external container needed
            ;;
        mysql)
            DB_DOCKER_IMAGE="mysql:8.0"
            DB_HOST="${DB_CONTAINER_NAME}"
            DB_PORT="3306"
            DB_USER="root"
            DB_PASSWORD="root"
            ;;
        mariadb)
            DB_DOCKER_IMAGE="mariadb:10.11"
            DB_HOST="${DB_CONTAINER_NAME}"
            DB_PORT="3306"
            DB_USER="root"
            DB_PASSWORD="root"
            ;;
        postgres)
            DB_DOCKER_IMAGE="postgres:15"
            DB_HOST="${DB_CONTAINER_NAME}"
            DB_PORT="5432"
            DB_USER="postgres"
            DB_PASSWORD="postgres"
            ;;
    esac
}

# Start database container and wait for it to be ready
start_db_container() {
    if [ "${DBMS}" = "sqlite" ]; then
        return
    fi

    echo "Creating Docker network ${NETWORK_NAME}..."
    docker network create "${NETWORK_NAME}" >/dev/null

    echo "Starting ${DBMS} container (${DB_DOCKER_IMAGE})..."
    case "${DBMS}" in
        mysql|mariadb)
            docker run -d --rm \
                --name "${DB_CONTAINER_NAME}" \
                --network "${NETWORK_NAME}" \
                -e "MYSQL_ROOT_PASSWORD=${DB_PASSWORD}" \
                -e "MYSQL_DATABASE=${DB_NAME}" \
                "${DB_DOCKER_IMAGE}" >/dev/null
            ;;
        postgres)
            docker run -d --rm \
                --name "${DB_CONTAINER_NAME}" \
                --network "${NETWORK_NAME}" \
                -e "POSTGRES_USER=${DB_USER}" \
                -e "POSTGRES_PASSWORD=${DB_PASSWORD}" \
                -e "POSTGRES_DB=${DB_NAME}" \
                "${DB_DOCKER_IMAGE}" >/dev/null
            ;;
    esac

    echo -n "Waiting for ${DBMS} to be ready..."
    local retries=30
    local i=0
    while [ $i -lt $retries ]; do
        case "${DBMS}" in
            mysql|mariadb)
                if docker exec "${DB_CONTAINER_NAME}" mysqladmin ping -h 127.0.0.1 --silent >/dev/null 2>&1; then
                    echo " ready."
                    return
                fi
                ;;
            postgres)
                if docker exec "${DB_CONTAINER_NAME}" pg_isready -q >/dev/null 2>&1; then
                    echo " ready."
                    return
                fi
                ;;
        esac
        echo -n "."
        sleep 1
        i=$((i + 1))
    done
    echo " TIMEOUT"
    echo "Error: ${DBMS} did not become ready within ${retries} seconds." >&2
    exit 1
}

# Build the env flags and install commands for the PHP container
build_docker_env_args() {
    local -n _env_args=$1
    local -n _install_cmds=$2

    case "${DBMS}" in
        sqlite)
            _env_args=(
                -e "typo3DatabaseDriver=pdo_sqlite"
                -e "typo3DatabaseName=${DB_NAME}"
            )
            _install_cmds="apt-get update -qq >/dev/null 2>&1 && \
apt-get install -y -qq libsqlite3-dev libicu-dev libzip-dev unzip git >/dev/null 2>&1 && \
docker-php-ext-install pdo_sqlite intl zip >/dev/null 2>&1"
            ;;
        mysql|mariadb)
            _env_args=(
                -e "typo3DatabaseDriver=pdo_mysql"
                -e "typo3DatabaseHost=${DB_HOST}"
                -e "typo3DatabasePort=${DB_PORT}"
                -e "typo3DatabaseUsername=${DB_USER}"
                -e "typo3DatabasePassword=${DB_PASSWORD}"
                -e "typo3DatabaseName=${DB_NAME}"
            )
            _install_cmds="apt-get update -qq >/dev/null 2>&1 && \
apt-get install -y -qq libicu-dev libzip-dev unzip git >/dev/null 2>&1 && \
docker-php-ext-install pdo_mysql intl zip >/dev/null 2>&1"
            ;;
        postgres)
            _env_args=(
                -e "typo3DatabaseDriver=pdo_pgsql"
                -e "typo3DatabaseHost=${DB_HOST}"
                -e "typo3DatabasePort=${DB_PORT}"
                -e "typo3DatabaseUsername=${DB_USER}"
                -e "typo3DatabasePassword=${DB_PASSWORD}"
                -e "typo3DatabaseName=${DB_NAME}"
            )
            _install_cmds="apt-get update -qq >/dev/null 2>&1 && \
apt-get install -y -qq libpq-dev libicu-dev libzip-dev unzip git >/dev/null 2>&1 && \
docker-php-ext-install pdo_pgsql intl zip >/dev/null 2>&1"
            ;;
    esac
}

# ---- Main ----

configure_db_env

echo "Running ${TEST_SUITE} tests with PHP ${PHP_VERSION}, DBMS ${DBMS}..."

case "${TEST_SUITE}" in
    functional)
        start_db_container

        declare -a ENV_ARGS=()
        INSTALL_CMDS=""
        build_docker_env_args ENV_ARGS INSTALL_CMDS

        NETWORK_ARGS=()
        if [ "${DBMS}" != "sqlite" ]; then
            NETWORK_ARGS=(--network "${NETWORK_NAME}")
        fi

        docker run --rm \
            --name "${CONTAINER_NAME}" \
            "${NETWORK_ARGS[@]}" \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${ENV_ARGS[@]}" \
            "${IMAGE}" \
            sh -c "
                set -e
                ${INSTALL_CMDS} && \
                curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1 && \
                composer install --no-interaction --prefer-dist -q && \
                vendor/bin/phpunit -c phpunit.xml.dist \"\$@\"
            " sh "${EXTRA_ARGS[@]}"
        ;;
    lint)
        docker run --rm \
            --name "${CONTAINER_NAME}" \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            "${IMAGE}" \
            sh -c "
                find Classes Tests -name '*.php' -print0 | xargs -0 -n1 php -l
            "
        ;;
    e2e)
        echo "Running E2E tests (Docker: MySQL + PHP web server + Playwright)..."

        # Clean up stale state from previous runs (may be root-owned from Docker)
        docker run --rm -v "${ROOT_DIR}:/app" -w /app alpine sh -c \
            'rm -rf var/cache var/log config/system/settings.php config/system/additional.php var/*.db' 2>/dev/null || true

        # Create Docker network
        docker network create "${NETWORK_NAME}" >/dev/null 2>&1

        # Start MySQL container with tmpfs for speed
        echo "Starting MySQL..."
        docker run --rm -d \
            --name "${RUN_ID}-db" \
            --network "${NETWORK_NAME}" \
            --network-alias db \
            -e MYSQL_ROOT_PASSWORD=root \
            -e MYSQL_DATABASE=typo3 \
            --tmpfs /var/lib/mysql:rw,noexec,nosuid \
            mysql:8.0 >/dev/null

        for i in $(seq 1 30); do
            if docker exec "${RUN_ID}-db" mysqladmin ping -h localhost --silent 2>/dev/null; then
                echo "MySQL is ready."
                break
            fi
            [ "$i" -eq 30 ] && { echo "MySQL timeout" >&2; exit 1; }
            sleep 1
        done

        # Start TYPO3 web server in Docker (PHP 8.4 with MySQL extensions + native lazy objects)
        echo "Starting TYPO3 web server..."
        docker run --rm -d \
            --name "${RUN_ID}-web" \
            --network "${NETWORK_NAME}" \
            --network-alias web \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            -e "TYPO3_CONTEXT=Development" \
            "chialab/php:8.4" \
            bash -c 'set -e && rm -rf var/cache var/log && composer install --no-interaction --prefer-dist --no-scripts -q && mkdir -p public && test -f public/index.php || bash Build/setup-typo3.sh && vendor/bin/typo3 setup --driver=mysqli --host=db --port=3306 --dbname=typo3 --username=root --password=root --admin-username=admin --admin-user-password=Admin123! --admin-email=admin@example.com --project-name=mcp-server-e2e --server-type=other --no-interaction --force && php -r '"'"'$s=include"config/system/settings.php";$s["SYS"]["trustedHostsPattern"]=".*";$s["SYS"]["devIPmask"]="*";file_put_contents("config/system/settings.php","<?php\nreturn ".var_export($s,true).";\n");'"'"' && rm -rf var/cache && exec php -S 0.0.0.0:8080 -t public/'

        echo "Waiting for TYPO3..."
        for i in $(seq 1 120); do
            # Check if container is still running
            if ! docker ps --format '{{.Names}}' | grep -q "${RUN_ID}-web"; then
                echo "Web container exited. Logs:" >&2
                docker logs "${RUN_ID}-web" 2>&1 | tail -30
                exit 1
            fi
            if docker exec "${RUN_ID}-web" curl -sf http://localhost:8080/typo3/ -o /dev/null 2>&1; then
                echo "TYPO3 is ready."
                break
            fi
            if [ "$i" -eq 120 ]; then
                echo "TYPO3 web server timeout. Logs:" >&2
                docker logs "${RUN_ID}-web" 2>&1 | tail -30
                exit 1
            fi
            sleep 2
        done

        # Run Playwright tests
        echo "Running Playwright tests..."
        mkdir -p "${ROOT_DIR}/Build/node_modules"
        docker run --rm \
            --name "${RUN_ID}-pw" \
            --network "${NETWORK_NAME}" \
            -v "${ROOT_DIR}/Build:/app" \
            -w /app \
            -e "TYPO3_BASE_URL=http://web:8080" \
            -e "CI=${CI:-}" \
            "mcr.microsoft.com/playwright:v1.52.0-noble" \
            /bin/bash -c "npm ci 2>/dev/null && npx playwright test ${EXTRA_ARGS[@]+"${EXTRA_ARGS[*]}"}"
        ;;
    *)
        echo "Unknown test suite: ${TEST_SUITE}" >&2
        exit 1
        ;;
esac
