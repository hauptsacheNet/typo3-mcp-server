CREATE TABLE tx_testrel_parent (
    title varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_testrel_child (
    title varchar(255) DEFAULT '' NOT NULL,
    bodytext text,
    parent int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tx_testrel_grandchild (
    title varchar(255) DEFAULT '' NOT NULL,
    parent_uid int(11) unsigned DEFAULT '0' NOT NULL,
    parent_table varchar(255) DEFAULT '' NOT NULL,
    parent_field varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_testrel_listchild (
    title varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_testrel_optout (
    title varchar(255) DEFAULT '' NOT NULL,
    parent int(11) unsigned DEFAULT '0' NOT NULL
);
