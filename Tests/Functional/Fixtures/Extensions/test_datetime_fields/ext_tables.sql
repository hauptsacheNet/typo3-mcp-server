CREATE TABLE tx_testdatetime_event (
    title varchar(255) DEFAULT '' NOT NULL,
    event_datetime bigint(20) DEFAULT '0' NOT NULL,
    event_date bigint(20) DEFAULT '0' NOT NULL,
    event_time int(11) DEFAULT '0' NOT NULL,
    native_datetime datetime DEFAULT NULL,
    native_date date DEFAULT NULL,
    native_time time DEFAULT NULL,
    slots int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tx_testdatetime_slot (
    starts_at bigint(20) DEFAULT '0' NOT NULL,
    event int(11) unsigned DEFAULT '0' NOT NULL
);
