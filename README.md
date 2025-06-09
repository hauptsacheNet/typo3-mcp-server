# TYPO3 MCP Server Extension

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

## How Workspace Safety Works

### Automatic Workspace Protection

When an AI assistant connects to your TYPO3 installation through this MCP server:

1. **Workspace Selection**: The system automatically selects or creates a workspace for the user
2. **Safe Operations**: All content modifications (create, update, delete) happen in the workspace context
3. **Live Site Protection**: Changes are completely isolated from your live website
4. **Review Process**: Content must be explicitly published through TYPO3's workspace module

### What This Means for AI Assistants

✅ **Safe to experiment** - AI can try different content approaches without risk  
✅ **Safe to iterate** - Multiple revisions can be made and refined  
✅ **Safe to bulk edit** - Large content operations won't immediately affect visitors  
✅ **Safe to learn** - AI can make mistakes without breaking your site  

### What Tables Are Protected

- **Content tables** (like `tt_content`, `pages`) - ✅ Workspace-protected
- **System tables** (like `be_users`, `sys_template`) - 🚫 Blocked entirely for security
- **File storage** and configuration - 🚫 Requires admin access through proper channels

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

1. Start the MCP server from the command line:
   ```bash
   vendor/bin/typo3 mcp:server
   ```

2. Connect your MCP-compatible client (Claude Desktop, etc.)

3. Begin creating content - all changes will be safely queued in workspaces

4. Review and publish changes through TYPO3 Backend → Workspaces module

### Workspace Behavior

- **First run**: The system will automatically select or create a workspace for content operations
- **Content changes**: All modifications happen in workspace context, not live site
- **Reading content**: Shows both live content and workspace changes in context
- **System tables**: Operations on configuration tables are blocked with helpful guidance

This approach ensures your live website stays stable while AI assistants work with content.

## Technical Architecture

### Core Components

1. **CLI Command** - Entry point that initializes and runs the MCP server
2. **MCP Server** - Implementation of the MCP protocol using stdio transport
3. **Workspace Context Service** - Manages automatic workspace selection and context switching
4. **TYPO3 Tools** - Set of tools that expose TYPO3 functionality:
   - Page tree navigation
   - Record viewing (workspace-aware)
   - Record editing via DataHandler (workspace-protected)
   - Table listing (workspace-capable tables only)
   - Schema information (with workspace capability details)

### MCP Protocol Implementation

This extension implements the stdio transport version of the MCP protocol, which uses standard input/output for communication. The server:

1. Reads JSON messages from STDIN
2. Processes requests according to the MCP specification
3. Writes JSON responses to STDOUT
4. Provides tools that map to TYPO3 DataHandler operations

### Security & Safety

The MCP server implements multiple layers of protection:

**Workspace Isolation:**
- All content changes are isolated in TYPO3 workspaces
- Live website remains unaffected by AI operations
- Changes require explicit publishing approval

**Permission System:**
- Inherits TYPO3's robust permission model
- Backend user authentication and authorization
- Workspace-level access controls

**Table Protection:**
- Only workspace-capable tables are exposed for modification
- System configuration tables are completely blocked
- Clear error messages explain limitations and alternatives

## Development

### Adding New Tools

Tools are defined in the `Classes/MCP/Tools` directory. Each tool follows the MCP tool specification and maps to specific TYPO3 functionality.

### Testing

```bash
composer test
```

## License

GPL-2.0-or-later
