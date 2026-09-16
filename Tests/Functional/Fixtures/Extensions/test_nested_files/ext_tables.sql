CREATE TABLE tx_testnestedfiles_item (
    title varchar(255) DEFAULT '' NOT NULL,
    file int(11) unsigned DEFAULT '0' NOT NULL,
    tt_content_items int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tt_content (
    tx_testnestedfiles_items int(11) unsigned DEFAULT '0' NOT NULL
);
