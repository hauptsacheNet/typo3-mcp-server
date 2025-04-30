# TYPO3 MCP Server Extension

This extension provides a Model Context Protocol (MCP) server implementation for TYPO3 that allows
AI assistants to view and manipulate TYPO3 pages and records through the TYPO3 DataHandler.

## What is MCP?

The Model Context Protocol (MCP) is a standard protocol for connecting AI systems with external tools and data sources.
It enables AI assistants to interact with systems in a structured way, providing capabilities like:

- Executing commands and tools
- Accessing resources
- Creating and retrieving content

## Features

- CLI command that starts an MCP server
- Tools for listing TYPO3 page trees
- Tools for viewing TYPO3 record details
- Tools for modifying TYPO3 records via DataHandler
- Secure authentication and permission handling

## Installation

```bash
composer require hn/mcp-server
```

## Usage

Start the MCP server from the command line:

```bash
vendor/bin/typo3 mcp:server
```

This will start an MCP server using stdio transport, allowing MCP-compatible clients to connect and interact with your TYPO3 installation.

## Technical Architecture

### Core Components

1. **CLI Command** - Entry point that initializes and runs the MCP server
2. **MCP Server** - Implementation of the MCP protocol using stdio transport
3. **TYPO3 Tools** - Set of tools that expose TYPO3 functionality:
   - Page tree navigation
   - Record viewing
   - Record editing via DataHandler

### MCP Protocol Implementation

This extension implements the stdio transport version of the MCP protocol, which uses standard input/output for communication. The server:

1. Reads JSON messages from STDIN
2. Processes requests according to the MCP specification
3. Writes JSON responses to STDOUT
4. Provides tools that map to TYPO3 DataHandler operations

### Security

The MCP server inherits TYPO3's security model:
- CLI commands run with backend admin privileges
- All operations respect TYPO3's permission system
- Access control is managed through TYPO3's backend user system

## Development

### Adding New Tools

Tools are defined in the `Classes/MCP/Tools` directory. Each tool follows the MCP tool specification and maps to specific TYPO3 functionality.

### Testing

```bash
composer test
```

## License

GPL-2.0-or-later
