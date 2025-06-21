<?php

defined('TYPO3') or die();

// Register eID for MCP HTTP endpoint
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['mcp_server'] = 
    \Hn\McpServer\Http\McpEndpoint::class;

// Register OAuth eID endpoints
$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['mcp_server_oauth_authorize'] = 
    \Hn\McpServer\Http\OAuthAuthorizeEndpoint::class;

$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['mcp_server_oauth_token'] = 
    \Hn\McpServer\Http\OAuthTokenEndpoint::class;

$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['mcp_server_oauth_metadata'] = 
    \Hn\McpServer\Http\OAuthMetadataEndpoint::class;

$GLOBALS['TYPO3_CONF_VARS']['FE']['eID_include']['mcp_server_oauth_register'] = 
    \Hn\McpServer\Http\OAuthRegisterEndpoint::class;