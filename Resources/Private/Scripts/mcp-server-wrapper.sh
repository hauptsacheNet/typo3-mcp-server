#!/bin/bash
# Wrapper script to filter out Docker OCI runtime messages
# and ensure clean MCP protocol communication

# Run the command and filter out any lines that start with "OCI runtime"
docker exec -i fabius-php-1 php vendor/bin/typo3 mcp:server 2> >(grep -v "^OCI runtime" >&2)
