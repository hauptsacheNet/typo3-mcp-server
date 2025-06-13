# TYPO3 MCP Server Extension

**⚠️ This extension is a work in progress and under active development.**

This extension provides a Model Context Protocol (MCP) server implementation for TYPO3 that allows
AI assistants to safely view and manipulate TYPO3 pages and records through TYPO3's workspace system.

## 🔒 Safe AI Content Management with Workspaces

**All content changes are automatically queued in TYPO3 workspaces**, making it completely safe for AI assistants to create, update, and modify content without immediately affecting your live website. Changes require explicit publishing to become visible to site visitors.

## What is MCP?

The Model Context Protocol (MCP) is a standard protocol for connecting AI systems with external tools and data sources.
It enables AI assistants to interact with systems in a structured way, providing capabilities like:

- Executing commands and tools
- Accessing resources
- Creating and retrieving content

## Features

### Core MCP Capabilities
- CLI command that starts an MCP server
- Tools for listing TYPO3 page trees
- Tools for viewing TYPO3 record details
- Tools for modifying TYPO3 records via DataHandler
- Secure authentication and permission handling

### Workspace Safety Features
- **Automatic workspace selection** - Finds or creates optimal workspace for content changes
- **Workspace-only operations** - Only exposes tables that support safe workspace operations
- **Protected system tables** - Prevents modification of critical system configuration
- **Transparent workspace context** - All read operations respect workspace state
- **Publishing workflow** - Changes require explicit approval before going live

### Publishing Workflow

1. AI creates/modifies content in workspace
2. Content appears in TYPO3 Backend → Workspaces module
3. Editors review and approve changes
4. Content is published to live site
5. Workspace is cleared for next round of changes

This approach combines the power of AI automation with human oversight and TYPO3's proven content management workflows.

## Installation

```bash
composer require hn/mcp-server
```

**Requirements:**
- TYPO3 v12.4+ or v13.4+
- TYPO3 Workspaces extension (automatically installed as dependency)

## Usage

### Quick Start

There are two ways to connect AI assistants like Claude Desktop to your TYPO3 installation:

#### Option 1: Local Command Line Connection

This method gives you admin privileges by default. Add this to your mcp config file of Claude Desktop or whatever client you are using.
```json
{
   "mcpServers": {
      "[your-typo3-name]": {
         "command": "php",
         "args": [
            "vendor/bin/typo3",
            "mcp:server"
         ]
      }
   }
}
```

#### Option 2: Remote MCP Server (Recommended)

For easier setup and remote access, use the built-in TYPO3 backend module:

1. Go to **[Username] → MCP Server** in your TYPO3 backend
2. Copy the configuration from the "MCP Client Configuration" section
3. Add it to your Claude Desktop configuration file

![MCP Server Setup](mcp_setup.png)

The remote setup allows multiple clients to connect simultaneously and provides a web-based interface for token management.

#### Next Steps

3. Begin creating content - all changes will be safely queued in workspaces

4. Review and publish changes through TYPO3 Backend → Workspaces module

## Development

### Adding New Tools

Tools are defined in the `Classes/MCP/Tools` directory. Each tool follows the MCP tool specification and maps to specific TYPO3 functionality.

## License

GPL-2.0-or-later
