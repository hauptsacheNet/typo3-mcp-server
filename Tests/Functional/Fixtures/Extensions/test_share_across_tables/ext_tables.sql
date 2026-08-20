CREATE TABLE tx_testsat_item (
    fieldname varchar(255) DEFAULT '' NOT NULL,
    foreign_table_parent_uid int(11) unsigned DEFAULT '0' NOT NULL,
    tablenames varchar(255) DEFAULT '' NOT NULL,

    title varchar(255) DEFAULT '' NOT NULL,

    KEY parent (foreign_table_parent_uid, tablenames, fieldname)
);

CREATE TABLE tt_content (
    tx_testsat_items int(11) unsigned DEFAULT '0' NOT NULL
);
