CREATE TABLE tx_testrequiredfields_slide (
    bodytext text,
    author varchar(255) DEFAULT '' NOT NULL,
    parent_uid int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tt_content (
    tx_testrequiredfields_slides int(11) unsigned DEFAULT '0' NOT NULL
);
