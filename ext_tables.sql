#
# OAuth authorization codes table (temporary codes for OAuth flow)
#
CREATE TABLE tx_mcpserver_oauth_codes (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
	
	code varchar(255) DEFAULT '' NOT NULL,
	be_user_uid int(11) unsigned DEFAULT '0' NOT NULL,
	client_name varchar(255) DEFAULT '' NOT NULL,
	pkce_challenge varchar(255) DEFAULT '' NOT NULL,
	pkce_challenge_method varchar(10) DEFAULT 'S256' NOT NULL,
	redirect_uri text,
	expires int(11) unsigned DEFAULT '0' NOT NULL,
	
	PRIMARY KEY (uid),
	KEY parent (pid),
	KEY code (code),
	KEY be_user_uid (be_user_uid),
	KEY expires (expires)
);

#
# OAuth access tokens table (long-lived tokens for MCP clients)
#
CREATE TABLE tx_mcpserver_access_tokens (
	uid int(11) NOT NULL auto_increment,
	pid int(11) DEFAULT '0' NOT NULL,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	crdate int(11) unsigned DEFAULT '0' NOT NULL,
	deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
	
	token varchar(255) DEFAULT '' NOT NULL,
	be_user_uid int(11) unsigned DEFAULT '0' NOT NULL,
	client_name varchar(255) DEFAULT '' NOT NULL,
	expires int(11) unsigned DEFAULT '0' NOT NULL,
	last_used int(11) unsigned DEFAULT '0' NOT NULL,
	created_ip varchar(45) DEFAULT '' NOT NULL,
	last_used_ip varchar(45) DEFAULT '' NOT NULL,
	
	PRIMARY KEY (uid),
	KEY parent (pid),
	KEY token (token),
	KEY be_user_uid (be_user_uid),
	KEY expires (expires)
);