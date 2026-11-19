CREATE TABLE tx_rssnewsimport_feed (
    title varchar(255) DEFAULT '' NOT NULL,
    url varchar(2048) DEFAULT '' NOT NULL,
    description text,
    storage_pid int(11) unsigned DEFAULT '0' NOT NULL,
    news_type varchar(10) DEFAULT '0' NOT NULL,
    check_interval int(11) unsigned DEFAULT '60' NOT NULL,
    import_images tinyint(1) unsigned DEFAULT '1' NOT NULL,
    add_related_link tinyint(1) unsigned DEFAULT '1' NOT NULL,
    update_existing tinyint(1) unsigned DEFAULT '0' NOT NULL,
    import_hidden tinyint(1) unsigned DEFAULT '0' NOT NULL,
    map_categories tinyint(1) unsigned DEFAULT '0' NOT NULL,
    import_limit int(11) unsigned DEFAULT '0' NOT NULL,
    max_age_days int(11) unsigned DEFAULT '0' NOT NULL,
    default_author varchar(255) DEFAULT '' NOT NULL,
    exclude_keywords text,
    last_import int(11) unsigned DEFAULT '0' NOT NULL,
    error_count int(11) unsigned DEFAULT '0' NOT NULL,
    last_status text,
    http_state text
);

#
# Colonna e indice aggiunti alla tabella di EXT:news:
# - tx_rssnewsimport_hash: impronta del contenuto, per aggiornare solo le news davvero cambiate
# - indice su import_source/import_id: la ricerca dei duplicati resta istantanea anche con molte news
#
CREATE TABLE tx_news_domain_model_news (
    tx_rssnewsimport_hash varchar(40) DEFAULT '' NOT NULL,

    KEY rssnewsimport_import (import_source(30), import_id(40))
);
