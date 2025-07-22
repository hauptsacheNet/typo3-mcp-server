# TYPO3 MCP Server Extension

**⚠️ This extension is a work in progress and under active development.**

This extension provides a Model Context Protocol (MCP) server implementation for TYPO3 that allows
AI assistants to safely view and manipulate TYPO3 pages and records through TYPO3's workspace system.

## 🔒 Safe AI Content Management with Workspaces

**All content changes are automatically queued in TYPO3 workspaces**, making it completely safe for AI assistants to create, update, and modify content without immediately affecting your live website. Changes require explicit publishing to become visible to site visitors.

## What Can You Do?

With the TYPO3 MCP Server, your AI assistant can help you:

### 📝 **Content Management**
- **Translate Pages**: "Translate the /about-us page to German" - The AI reads your content, translates it, and creates proper language versions
- **Import Documents**: "Create a news article from this Word document" - Transform external documents into TYPO3 content with proper structure
- **Bulk Updates**: "Update all product descriptions to include our new sustainability message" - Make consistent changes across multiple pages

### 🔍 **Content Analysis & SEO**
- **SEO Optimization**: "Add meta descriptions to all pages that don't have them" - Automatically generate missing SEO content based on page content
- **Tone Analysis**: "Review the tone of our product pages and make them more friendly" - Get suggestions for improving content voice and style
- **Content Audit**: "Find all pages mentioning our old company name" - Quickly locate content that needs updating

### 🚀 **Productivity Boosters**
- **Template Application**: "Apply our standard legal disclaimer to all service pages" - Consistently apply content patterns
- **Content Migration**: "Copy all news articles from 2023 to the archive folder" - Reorganize content efficiently
- **Multi-language Management**: "Ensure all German pages have English translations" - Identify and fill translation gaps

All these operations happen safely in workspaces, giving you full control to review before publishing!

> 💡 **Want to know how it works?** Check out our [Technical Overview](TECHNICAL_OVERVIEW.md) for detailed information about the implementation, available tools, and real-world examples with actual tool calls.

## What is MCP?

The Model Context Protocol (MCP) is a standard protocol for connecting AI systems with external tools and data sources.
It enables AI assistants to interact with systems in a structured way, providing capabilities like:

- Executing commands and tools
- Accessing resources
- Creating and retrieving content

## Features

### Core MCP Capabilities
- **Smart Navigation** - Tools for exploring your site structure just like in the TYPO3 page tree
- **Content Intelligence** - AI can read and understand any TYPO3 content, from pages to news articles
- **Safe Modifications** - All changes go through TYPO3's DataHandler, ensuring data integrity
- **Enterprise Security** - OAuth 2.1 authentication with user-specific permissions

### Workspace Safety Features
- **Zero Risk to Live Site** - AI automatically works in a safe workspace, never touching live content
- **Smart Table Filtering** - Only content that can be safely versioned is exposed to AI
- **System Protection** - Critical configuration and system tables remain untouchable
- **Preview Everything** - See AI changes in context before they go live
- **Full Editorial Control** - Review, modify, or reject AI suggestions before publishing

### Publishing Workflow

1. AI creates/modifies content in workspace
2. Content appears in TYPO3 Backend → Workspaces module
3. Editors review and approve changes
4. Content is published to live site
5. Workspace is cleared for next round of changes

This approach combines the power of AI automation with human oversight and TYPO3's proven content management workflows.

## Installation

```bash
composer require hn/typo3-mcp-server
```

**Requirements:**
- TYPO3 v13.4+
- TYPO3 Workspaces extension (automatically installed as dependency)

## Usage

### Quick Start

There are two ways to connect AI assistants like Claude Desktop to your TYPO3 installation:

#### Option 1: OAuth Authentication (Recommended)

For secure remote access with proper authentication:

1. Go to **[Username] → MCP Server** in your TYPO3 backend
2. Copy the OAuth configuration from the "OAuth Client Configuration" section
3. Add it to your Claude Desktop configuration file

The OAuth setup:
- Provides secure authentication through TYPO3 backend login
- Supports multiple simultaneous client connections per user
- Includes proper token management and revocation
- Works with any MCP client that supports OAuth 2.1

**OAuth Flow:**
1. MCP client redirects you to TYPO3 authorization URL
2. You log in to TYPO3 Backend (if not already logged in)  
3. You authorize the MCP client access
4. TYPO3 generates a secure access token
5. MCP client uses the token for API access

![MCP Server Setup](mcp_setup.png)

#### Option 2: Local Command Line Connection

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

#### Next Steps

1. Begin creating content - all changes will be safely queued in workspaces

2. Review and publish changes through TYPO3 Backend → Workspaces module

### CLI OAuth Management

For advanced users and automation, you can manage OAuth tokens via command line:

```bash
# Generate authorization URL for a user
vendor/bin/typo3 mcp:oauth url admin --client-name="My MCP Client"

# List active tokens for a user  
vendor/bin/typo3 mcp:oauth list admin

# Revoke a specific token
vendor/bin/typo3 mcp:oauth revoke admin --token-id=123

# Revoke all tokens for a user
vendor/bin/typo3 mcp:oauth revoke admin --all

# Clean up expired tokens and codes
vendor/bin/typo3 mcp:oauth cleanup
```

## Development

### Adding New Tools

Tools are defined in the `Classes/MCP/Tools` directory. Each tool follows the MCP tool specification and maps to specific TYPO3 functionality.

## Learn More

- 📖 **[Technical Overview](TECHNICAL_OVERVIEW.md)** - Comprehensive guide covering architecture, implementation details, and advanced usage
- 🏗️ **[Architecture Documentation](Documentation/Architecture/)** - Deep dives into specific implementation aspects:
  - [Workspace Transparency](Documentation/Architecture/WorkspaceTransparency.md) - How workspace complexity is hidden from AI
  - [Language Overlays](Documentation/Architecture/LanguageOverlays.md) - Multi-language content handling
  - [Inline Relations](Documentation/Architecture/InlineRelations.md) - Managing TYPO3's complex relation types

## License

GPL-2.0-or-later
