#!/usr/bin/env bash
set -euo pipefail

#
# Run tests in a Docker container with the correct PHP version and extensions.
# Mirrors the CI environment (PHP 8.3, SQLite, no extra services).
#
# Usage:
#   Build/runTests.sh                          # Run all functional tests
#   Build/runTests.sh --filter=ReadTableTool   # Run specific tests
#   Build/runTests.sh -p 8.4                   # Use different PHP version
#   Build/runTests.sh --help                   # Show help
#

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PHP_VERSION="8.3"
TEST_SUITE="functional"
EXTRA_ARGS=()
CONTAINER_NAME="typo3-mcp-tests-$$"
IMAGE=""

usage() {
    cat <<EOF
Usage: $(basename "$0") [options] [-- phpunit-args]

Options:
    -p <version>    PHP version (default: 8.3)
    -s <suite>      Test suite: functional (default), lint
    -h, --help      Show this help

Any arguments after -- are passed directly to phpunit/paratest.
Short filter syntax (without --) is also supported:
    $(basename "$0") --filter=ReadTableTool

Examples:
    $(basename "$0")                                    # All functional tests
    $(basename "$0") --filter=ReadTableToolTest          # Filter by class
    $(basename "$0") -p 8.4                              # PHP 8.4
    $(basename "$0") -- --filter=testReadRecordsByPid    # Filter by method
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

cleanup() {
    docker rm -f "${CONTAINER_NAME}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

echo "Running ${TEST_SUITE} tests with PHP ${PHP_VERSION}..."

case "${TEST_SUITE}" in
    functional)
        docker run --rm \
            --name "${CONTAINER_NAME}" \
            -v "${ROOT_DIR}:/app" \
            -w /app \
            -e "typo3DatabaseDriver=pdo_sqlite" \
            -e "typo3DatabaseName=typo3_test" \
            "${IMAGE}" \
            sh -c '
                set -e
                apt-get update -qq >/dev/null 2>&1 && \
                apt-get install -y -qq libsqlite3-dev libicu-dev libzip-dev unzip git >/dev/null 2>&1 && \
                docker-php-ext-install pdo_sqlite intl zip >/dev/null 2>&1 && \
                curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null 2>&1 && \
                composer install --no-interaction --prefer-dist -q && \
                vendor/bin/phpunit -c phpunit.xml.dist "$@"
            ' sh "${EXTRA_ARGS[@]}"
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
    *)
        echo "Unknown test suite: ${TEST_SUITE}" >&2
        exit 1
        ;;
esac
