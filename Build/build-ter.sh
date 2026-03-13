#!/bin/bash
#
# Build script for creating a TER-ready extension zip
# This bundles the required PHP libraries for non-composer installations
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
EXTENSION_KEY="mcp_server"

# Get version from ext_emconf.php
VERSION=$(php -r "
    \$_EXTKEY = '$EXTENSION_KEY';
    include '$PROJECT_DIR/ext_emconf.php';
    echo \$EM_CONF[\$_EXTKEY]['version'];
")

if [ -z "$VERSION" ]; then
    echo "ERROR: Could not determine version from ext_emconf.php" >&2
    exit 1
fi

BUILD_DIR="$PROJECT_DIR/.build"
DIST_DIR="$PROJECT_DIR/dist"
EXTENSION_DIR="$BUILD_DIR/$EXTENSION_KEY"

echo "Building TER package for $EXTENSION_KEY version $VERSION"

# Clean up previous builds
rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
mkdir -p "$DIST_DIR"
mkdir -p "$EXTENSION_DIR"

# Copy only the files/directories needed for distribution
# This avoids issues with symlinks, cycles, and dev files
echo "Copying extension files..."

# Copy specific directories that belong in the extension
for dir in Classes Configuration Documentation Resources; do
    if [ -d "$PROJECT_DIR/$dir" ]; then
        cp -R "$PROJECT_DIR/$dir" "$EXTENSION_DIR/"
    fi
done

# Copy specific files from root
for file in ext_emconf.php ext_localconf.php ext_tables.php ext_tables.sql composer.json README.md LICENSE; do
    if [ -f "$PROJECT_DIR/$file" ]; then
        cp "$PROJECT_DIR/$file" "$EXTENSION_DIR/"
    fi
done

# Clean up any dev files that might have been copied
rm -rf "$EXTENSION_DIR/Resources/Private/PHP/vendor" 2>/dev/null || true
rm -f "$EXTENSION_DIR/Resources/Private/PHP/composer.lock" 2>/dev/null || true

# Install bundled libraries
echo "Installing bundled libraries..."
cd "$EXTENSION_DIR/Resources/Private/PHP"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

# Remove packages that TYPO3 already provides (they are marked as "replace" but composer still downloads them)
echo "Cleaning up redundant packages..."
rm -rf vendor/psr/log 2>/dev/null || true

# Remove unnecessary files from vendored packages (tests, webclient, docs)
echo "Removing unnecessary vendor files..."
rm -rf vendor/logiscape/mcp-sdk-php/tests 2>/dev/null || true
rm -rf vendor/logiscape/mcp-sdk-php/webclient 2>/dev/null || true

# Regenerate autoloader after removing packages
composer dump-autoload --optimize --classmap-authoritative --no-interaction

cd "$EXTENSION_DIR"

# Create the zip file (files directly in zip root, not in a subdirectory)
ZIP_FILE="$DIST_DIR/${EXTENSION_KEY}_${VERSION}.zip"
rm -f "$ZIP_FILE"
echo "Creating zip file: $ZIP_FILE"
zip -r "$ZIP_FILE" . -x "*.git*" -x "*.DS_Store"

echo ""
echo "TER package created successfully!"
echo "  File: $ZIP_FILE"
echo "  Size: $(du -h "$ZIP_FILE" | cut -f1)"
echo ""
echo "You can upload this file to the TYPO3 Extension Repository."
