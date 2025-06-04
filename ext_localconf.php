<?php

defined('TYPO3') or die();

// Register eID for MCP HTTP endpoint
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['mcp_server'] = 
    \Hn\McpServer\Http\McpEndpoint::class;