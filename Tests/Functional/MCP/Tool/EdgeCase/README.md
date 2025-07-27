# Edge Case Tests for TYPO3 MCP Server

This directory contains comprehensive edge case tests for the TYPO3 MCP Server tools. These tests ensure robust error handling and graceful degradation in unexpected scenarios.

## Test Categories

### 1. Database Error Tests (`DatabaseErrorTest.php`)
Tests handling of database-related errors:
- Connection failures
- Query timeouts  
- Unique constraint violations
- Transaction rollbacks
- Deadlock scenarios
- Corrupted data handling
- Database lock timeouts
- Connection pool exhaustion

### 2. Invalid Data Tests (`InvalidDataTest.php`)
Tests validation and handling of invalid input:
- Negative/zero UIDs
- Non-existent tables
- SQL injection attempts
- Invalid field names
- Field length violations
- Data type mismatches
- Invalid enum values
- Circular references
- Mass assignment protection
- Invalid JSON/array data
- Character encoding issues

### 3. Resource Constraint Tests (`ResourceConstraintTest.php`)
Tests system resource limits:
- Memory limit handling
- Large result sets
- Large IN clauses
- Deep recursive structures
- Complex search queries
- Execution time limits
- Concurrent operations
- File system constraints
- Query complexity limits

### 4. Permission Edge Cases (`PermissionEdgeCaseTest.php`)
Tests access control edge cases:
- Partial table permissions (read but not write)
- Field-level restrictions
- Workspace access limits
- Language permission constraints
- Mount point restrictions
- Operation type restrictions
- System table access
- Recursive permission checks
- Element type permissions

### 5. System Error Tests (`SystemErrorTest.php`)
Tests handling of system-level errors:
- Missing TCA configuration
- Corrupted TCA configuration
- Cache failures
- Configuration manager failures
- Workspace service failures
- Table access service failures
- PHP error handling
- File system errors
- Extension dependency issues
- Global state corruption
- Circular dependencies
- Race conditions

## Running the Tests

Run all edge case tests:
```bash
composer test -- --filter="EdgeCase"
```

Run specific edge case category:
```bash
composer test -- --filter="DatabaseErrorTest"
composer test -- --filter="InvalidDataTest"
composer test -- --filter="ResourceConstraintTest"
composer test -- --filter="PermissionEdgeCaseTest"
composer test -- --filter="SystemErrorTest"
```

## Key Testing Principles

1. **Graceful Degradation**: All tools should handle errors gracefully without crashing
2. **Clear Error Messages**: Error responses should be informative and actionable
3. **Data Integrity**: Failed operations should not corrupt data
4. **Resource Protection**: Tools should protect against resource exhaustion
5. **Security**: Input validation should prevent injection attacks

## Notes

- Some tests are marked with `@group resource-intensive` for tests that use significant resources
- Database tests use transactions where possible to ensure test isolation
- Permission tests create temporary users that are cleaned up after tests
- Resource tests temporarily modify PHP settings but restore them afterwards