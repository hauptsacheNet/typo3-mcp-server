# TYPO3 MCP Server Record Management Plan

This document outlines the plan for implementing record management tools in the TYPO3 MCP server. These tools will enable LLMs to list, read, and write TYPO3 records through a standardized interface.

## Core Principles

- **TCA-First Approach**: All operations will be based on TYPO3's Table Configuration Array (TCA) rather than database schema
- **Page-Centric Context**: All operations work within the context of a page, following TYPO3's core patterns
- **Simplified Interface**: Provide enough context for LLMs to understand data without overwhelming with technical details
- **Clear Error Handling**: Provide detailed, validation-focused error messages

## Tools Overview

### 1. ListTableTypes Tool

This tool will provide an overview of available tables in the TYPO3 installation.

**Features:**
- Group tables by extension name
- Provide human-readable descriptions
- Filter out tables with `hideTable` set (usually inline relations)
- Indicate if tables are editable or read-only
- Include typical purpose (content, system, extension-specific)
- Filter by page ID to show only relevant tables

**Example Output:**
```
CORE TABLES:
- pages (Pages): The main page records [content]
- tt_content (Content Elements): Page content elements [content]

EXTENSION: news (News System)
- tx_news_domain_model_news (News): News articles [content]
- tx_news_domain_model_category (Categories): News categories [content]
```

### 2. GetTableType Tool

This tool will provide detailed schema information for a specific table.

**Features:**
- Display both technical and human-readable field names
- Include simplified type information (text, number, relation, etc.)
- Show validation rules (required, min/max values)
- Group fields by tabs and palettes to provide context
- Include default values for fields
- Handle record types within tables (show type-specific fields)
- Include inline relation schemas
- For select fields, show available options and labels
- Provide JSON example of a typical record
- Highlight special fields (language, type, etc.)

**Example Output:**
```
TABLE SCHEMA: tt_content (Content Elements)

RECORD TYPES:
- text (Regular Text Element) [default]
- textpic (Text with Images)
- ...

FIELDS FOR ALL TYPES:
- header (Header) [text, required]: The element's headline
  Default: ""
- hidden (Hidden) [boolean]: If set, the element is not visible
  Default: false
- sys_language_uid (Language) [language]: The language of this record
  Default: 0 (Default language)

FIELDS FOR TYPE: text
- bodytext (Text) [richtext]: The main content text
  Default: ""

FIELDS FOR TYPE: textpic
- bodytext (Text) [richtext]: The main content text
  Default: ""
- image (Images) [inline relation to sys_file_reference]: Images for this element
  Default: []

EXAMPLE RECORD (text):
{
  "uid": 123,
  "pid": 45,
  "header": "Example Content",
  "bodytext": "<p>This is some rich text content.</p>",
  "CType": "text",
  "sys_language_uid": 0
}
```

### 3. ReadTable Tool

This tool will retrieve actual record data from the database.

**Features:**
- Return records in JSON format
- Only include fields that differ from default values to reduce complexity
- Include relations directly in their respective fields
- Properly handle inline relations (include as nested objects in their original fields)
- Support filtering by page ID, record type, etc.
- Allow SQL-like conditions for advanced filtering
- Include pagination for large result sets
- Special handling for RTE fields (indicate HTML content)

**Example Output:**
```json
{
  "record": {
    "uid": 123,
    "pid": 45,
    "header": "Example Content",
    "bodytext": "<p>This is some rich text content.</p>",
    "CType": "text",
    "sys_language_uid": 0,
    "categories": [
      {"uid": 5, "title": "Example Category"}
    ],
    "image": [
      {
        "uid": 42,
        "title": "Example Image",
        "alternative": "Alt text",
        "description": "Image description",
        "file": {
          "uid": 78,
          "name": "example.jpg",
          "type": "image/jpeg"
        }
      }
    ]
  },
  "meta": {
    "table": "tt_content",
    "recordType": "text"
  }
}
```

### 4. WriteTable Tool

This tool will create or update records in the database.

**Features:**
- Accept data in a format similar to the read format
- Handle inline relations directly in their fields
- Validate input against TCA rules before saving
- Provide detailed validation errors
- Use TYPO3's DataHandler for actual database operations to ensure hooks are executed
- Support creating translations of records
- Allow updating specific fields (partial updates)
- Require page context (pid) for all operations

**Example Input:**
```json
{
  "table": "tt_content",
  "pid": 45,
  "data": {
    "header": "New Content Element",
    "bodytext": "<p>This is the content.</p>",
    "CType": "text",
    "categories": [5, 8]
  }
}
```

**Example Output (Success):**
```json
{
  "status": "success",
  "message": "Record created successfully",
  "uid": 124
}
```

**Example Output (Error):**
```json
{
  "status": "error",
  "message": "Validation failed",
  "errors": [
    {
      "field": "header",
      "message": "Header is required"
    }
  ]
}
```

## Implementation Phases

### Phase 1: Basic Framework
- Setup the tool interfaces and base classes
- Implement ListTableTypes tool with page context

### Phase 2: Read Operations
- Implement GetTableType tool
- Implement basic ReadTable functionality
- Add support for relations and translations

### Phase 3: Write Operations
- Implement WriteTable for basic operations
- Add validation logic

### Phase 4: Advanced Features
- Add support for complex field types and inline relations
- Implement filtering and search functionality
- Add performance optimizations

## Future Considerations (Not in Initial Implementation)

- **Image Handling**: Direct access and manipulation of images and files
- **Workspace Support**: Integration with TYPO3 workspaces for draft/publishing workflow
- **Permission System**: Respecting TYPO3's backend user permissions
- **FlexForm Support**: Handling complex FlexForm configurations
- **Record History**: Providing access to change history

## Technical Notes

- The implementation will use TYPO3's DataHandler for all write operations to ensure hooks are executed
- Doctrine queries will be used for read operations, avoiding direct SQL where possible
- Special attention will be paid to localization handling as this is a common use case
- All operations will require a page context (pid) to align with TYPO3's architecture

## Validation Approach

Validation will happen at multiple levels:
1. **Schema Validation**: Ensuring data matches expected types
2. **TCA Validation**: Applying TCA rules (required, min/max, etc.)
3. **Business Logic**: Any additional TYPO3-specific validation
4. **Clear Feedback**: Providing detailed error messages that help LLMs correct their inputs
