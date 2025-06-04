#
# Add MCP token fields to be_users table
#
CREATE TABLE be_users (
	mcp_token varchar(255) DEFAULT '' NOT NULL,
	mcp_token_expires int(11) unsigned DEFAULT '0' NOT NULL,
	
	KEY mcp_token (mcp_token)
);