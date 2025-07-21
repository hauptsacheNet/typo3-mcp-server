# TYPO3 MCP Server Technical Overview

This document provides a comprehensive technical overview of the TYPO3 MCP (Model Context Protocol) Server extension. It explains how AI assistants can safely interact with TYPO3 content through a carefully designed interface that maintains security while hiding complexity.

## Introduction & Core Concepts

### What is the TYPO3 MCP Server?

The TYPO3 MCP Server is an extension that bridges the gap between AI language models and TYPO3 content management. It provides a standardized interface that allows AI assistants like Claude, ChatGPT, or other MCP-compatible tools to:

- Read and understand TYPO3 content structure
- Create and modify content safely
- Navigate complex site hierarchies
- Work with any TYPO3 extension's data

### The Problem It Solves

Traditional CMS interfaces are designed for human interaction through web browsers. AI assistants need a different approach - one that provides structured data access while maintaining the safety and workflow controls that make TYPO3 reliable. The MCP Server solves this by:

- **Ensuring all changes go through workspaces** - no accidental live modifications
- **Providing AI-friendly interfaces** - structured data instead of HTML forms
- **Maintaining security** - respecting user permissions and access controls
- **Supporting complex operations** - handling relations, translations, and more

### How MCP Fits In

The Model Context Protocol (MCP) is an open standard for connecting AI systems with external tools and data sources. In the TYPO3 context:

- **MCP Client**: Your AI assistant (Claude Desktop, custom implementations, etc.)
- **MCP Server**: This TYPO3 extension
- **TYPO3**: Your content management system

The MCP Server acts as an intelligent translator between what the AI understands and how TYPO3 works internally.

```
┌──────────────┐     OAuth/HTTP      ┌─────────────────┐
│  MCP Client  │◄───────────────────▶│   MCP Server    │
│(Claude, etc) │     stdin/stdout    │  (TYPO3 Ext)    │
└──────────────┘                     └────────┬────────┘
                                              │
                                              ▼
                                     ┌─────────────────┐
                                     │  TYPO3 Core     │
                                     │  - DataHandler  │
                                     │  - Workspaces   │
                                     │  - TCA/Database │
                                     └─────────────────┘
```

## Core Principles

These design principles guide every aspect of the MCP Server implementation:

### 1. Workspace Transparency
All content modifications automatically happen in TYPO3 workspaces. The AI assistant doesn't need to understand workspaces - it just creates or modifies content, and the system ensures it's safely queued for review. From the AI's perspective, it's working with simple record IDs, while behind the scenes, the workspace system manages versions and drafts.

### 2. TCA-First Approach
TYPO3's TCA is designed to create human-understandable forms, and we leverage this same design to create AI-understandable data representations. Every operation is based on the Table Configuration Array rather than raw database schemas. This means:
- Validation rules are automatically enforced
- Field types are properly handled
- Relations work as configured
- Well-named fields and descriptions in TCA automatically create a good AI interface
- The same effort that makes forms intuitive for editors makes data intuitive for AI

### 3. Familiar Interface Patterns
The MCP tools closely resemble TYPO3's Page-Tree and List-Module interfaces. This familiar pattern means:
- TYPO3 users can easily estimate what the AI will see and understand
- Extensions that design good interfaces through TCA automatically provide good AI interfaces
- Better for everyone: improvements in human usability translate directly to AI usability

### 4. Extension Compatibility
Any TYPO3 extension with proper TCA configuration and workspace support works automatically - no modifications needed. If you can edit it in the TYPO3 backend, the AI can work with it through MCP. This includes:
- Core TYPO3 tables (pages, content, users)
- Popular extensions (news, etc.)
- Your custom extensions

### 5. User Context & Responsibility
The MCP operates with the permissions and workspace of the authenticated user. It's a tool to help editors, but editors remain responsible for the content. The AI assistant:
- Can only access what the user can access
- Creates changes in the user's workspace and in the users name
- Respects all permission settings
- Maintains an audit trail

### 6. Page-Centric Context
Everything in TYPO3 revolves around pages, and the MCP Server embraces this. Most operations require or benefit from page context:
- Content elements belong to pages
- Records are often filtered by page
- Permissions are page-based
- URLs map to pages

### 7. Safety by Default
No direct modifications to live data are possible. Every change:
- Goes through TYPO3's DataHandler
- Is created in a workspace
- Must be explicitly published
- Can be reviewed before going live

### 8. Thoughtful Data Representation
The complexity of TYPO3 is hidden through carefully crafted data representations. Rather than simply dumping JSON, we thoughtfully curate what the AI sees:
- JSON structures that mirror the form layouts, showing only relevant fields
- Friendly error messages instead of technical exceptions
- Logical field names with context from TCA labels and descriptions
- Automatic handling of relations and references
- Tab and palette groupings preserved to maintain semantic relationships


## Available Tools

The MCP Server provides these tools for interacting with TYPO3:

### Discovery & Navigation
- **GetPageTree** - Navigate site hierarchy and explore page structure
- **GetPage** - Get page details by URL or ID with content summary
- **ListTables** - Discover available TYPO3 tables and extensions

### Content Reading
- **ReadTable** - Read records from any TYPO3 table with filtering
- **Search** - Find content across tables using full-text search
- **GetTableSchema** - Understand table structure and field types
- **GetFlexFormSchema** - Get plugin configuration schemas

### Content Modification
- **WriteTable** - Create, update, or delete records (safely in workspace)

> Each tool provides detailed schema information when called. See the Real-World Scenarios below for practical examples.

## Real-World Scenarios

Here are practical examples of how the MCP Server enables AI-powered content management:

### "Translate that page"

**User says**: "Translate the /about-us page to German"

**What happens**:
1. AI uses `GetPage` with URL "/about-us" to fetch the page
2. Reads all content elements using `ReadTable` with pid filter
3. Translates the text content
4. Creates German language versions using `WriteTable`
5. Sets proper language relations and parent references

**Tool calls**:
```json
// 1. Get page info
{"tool": "GetPage", "params": {"identifier": "/about-us"}}

// 2. Read content elements
{"tool": "ReadTable", "params": {
  "table": "tt_content",
  "where": {"pid": 123, "sys_language_uid": 0}
}}

// 3. Create translations
{"tool": "WriteTable", "params": {
  "table": "tt_content",
  "data": {
    "pid": 123,
    "sys_language_uid": 1,
    "l10n_parent": 456,
    "header": "Über uns",
    "bodytext": "[translated content]"
  }
}}
```

### "Create a news article from this Word draft"

**User says**: "Create a news article from this document" [provides Word file]

**What happens**:
1. AI extracts content from the Word document
2. Finds appropriate storage location for news articles
3. Uses `GetTableSchema` to understand news record structure
4. Searches for or creates appropriate categories
5. Creates news record with proper metadata
6. Handles relations and references

**Tool calls**:
```json
// 1. Find news storage folder
{"tool": "GetPageTree", "params": {"depth": 3}}
// or
{"tool": "ReadTable", "params": {
  "table": "pages",
  "where": {"doktype": 254},
  "limit": 10
}}

// 2. Check news table structure
{"tool": "GetTableSchema", "params": {"table": "tx_news_domain_model_news"}}

// 3. Look for existing categories
{"tool": "ReadTable", "params": {
  "table": "tx_news_domain_model_category",
  "where": {"pid": 789}
}}

// 4. Create news article
{"tool": "WriteTable", "params": {
  "table": "tx_news_domain_model_news",
  "data": {
    "pid": 789,
    "title": "Annual Report 2024 Released",
    "teaser": "Our latest financial results...",
    "bodytext": "[full article content]",
    "categories": [12, 15],
    "datetime": "2024-01-15T10:00:00"
  }
}}
```

### "Proofread and judge the tone of site X"

**User says**: "Review the tone of our product pages and make them more friendly"

**What happens**:
1. AI finds all product pages using `GetPageTree`
2. Reads content from each page
3. Analyzes tone and style
4. Provides specific recommendations
5. Can update content if requested

### "Fill in the SEO descriptions of those sites"

**User says**: "Add meta descriptions to all pages that don't have them"

**What happens**:
1. AI searches for pages without descriptions
2. Reads page content to understand context
3. Generates appropriate meta descriptions
4. Updates page records with SEO content

**Note on Limitations**: Complex operations like "translate the entire page" may hit context window limits depending on the MCP client and language model. Consider processing in chunks for large pages.

## Key Features in Detail

### URL Resolution

The `GetPage` tool intelligently handles various URL formats:

- **Full URLs**: `https://example.com/about-us`
- **Paths**: `/about-us` or `about-us`  
- **Multi-language**: Detects language from URL
- **Domain validation**: Ensures URLs match configured sites
- **Fallback strategies**: Router → slug lookup → ID

### Relation Handling

Relations are transparently resolved and can be set using simple syntax:

- **Select relations**: Use comma-separated IDs or arrays
- **Inline relations**: Provide as nested objects
- **MM relations**: Handled automatically
- **File references**: Currently read-only
- **Bidirectional**: Updates both sides as needed

### Workspace Magic

Behind the scenes, the workspace system:

1. **Finds or creates** an appropriate workspace
2. **Manages versions** without exposing version UIDs
3. **Handles deletes** through delete placeholders
4. **Overlays data** for transparent reading
5. **Queues changes** for editorial review

### Validation & Error Handling

Errors are designed to help AI assistants self-correct:

```json
{
  "error": "Validation failed",
  "details": {
    "field_errors": {
      "title": "This field is required",
      "email": "Invalid email format"
    },
    "suggestions": {
      "email": "Use format: user@example.com"
    }
  }
}
```

### Permission Handling

The MCP Server respects all TYPO3 permissions:

- **Page permissions**: Read, write, delete
- **Table permissions**: Based on user group
- **Field permissions**: Exclude fields work
- **Record permissions**: Custom access checks
- **Workspace permissions**: Automatic workspace selection

## What's Not Yet Implemented

While the MCP Server is powerful, some features are still in development:

### Image/File Handling
- Currently read-only access to file references
- Cannot upload new files or modify existing ones
- Workaround: Reference existing files by ID

### Direct Workspace Management
- Cannot create/delete workspaces
- Cannot manually publish changes
- Must use TYPO3 backend for workspace operations

### Bulk Operations Optimization
- Large batch operations may be slow
- No built-in chunking for massive updates
- Consider breaking into smaller operations

## Best Practices for Users

To get the most out of your AI assistant with TYPO3:

### Use Full Page URLs
Give your AI assistant complete URLs like `https://example.com/about-us`. The system can automatically resolve these to the correct pages, making your instructions clearer and reducing errors.

### Be Specific About Scope
For large operations, specify exactly what to process. Instead of "update all pages", say "update the meta descriptions for pages under /products". This helps avoid context window limits and ensures focused results.

### Review Before Publishing
Always check the Workspaces module to review AI-generated changes before they go live. The AI is powerful but should be treated as a helpful assistant, not an autonomous system.

### Provide Context
Give your AI assistant relevant background information. For example: "We're a law firm, keep the tone professional" or "This is for our summer campaign, make it cheerful".

### Work Incrementally
For complex tasks, break them into smaller steps:
1. First, analyze the current content
2. Then, make specific improvements
3. Finally, review and refine

**Tip**: For very complex operations, consider using multiple chat sessions in parallel. Each session maintains its own context, allowing you to tackle different aspects of a project simultaneously without overwhelming a single conversation.

### Understand the Publishing Workflow
Remember that all changes need your approval:
1. AI creates/modifies content in workspace
2. You review in TYPO3 Backend → Workspaces
3. You publish approved changes
4. Content goes live on your site

This workflow ensures you maintain full control while benefiting from AI efficiency.