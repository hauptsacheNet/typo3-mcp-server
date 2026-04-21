#
# Stubbed schema additions matching the subset of GridElementsTeam/gridelements
# that WriteTableTool validation depends on.
#
CREATE TABLE tt_content (
	tx_gridelements_container int(11) DEFAULT '0' NOT NULL,
	tx_gridelements_columns int(11) DEFAULT '0' NOT NULL
);
