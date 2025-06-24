#!/bin/bash

# Test script for the MCP server
# This script pipes the test client to the MCP server command

echo "Starting MCP test..."
echo "===================="

# Run the test client and pipe its output to the MCP server
# Use 'script' to create a pseudo-TTY which helps with stdin/stdout handling
script -q /dev/null php /Volumes/Workspace/fabius/extensions/mcp_server/Resources/Private/Scripts/test-mcp-client.php | docker exec -i fabius-php-1 vendor/bin/typo3 mcp:server

echo "===================="
echo "Test completed."
