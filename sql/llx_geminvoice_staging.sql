-- ============================================================
-- Table: llx_geminvoice_staging
-- Module: Geminvoice
-- Description: Staging table for invoices pending human review
-- Columns added since initial release — see sql/migration/ for upgrade scripts
-- ============================================================

CREATE TABLE IF NOT EXISTS llx_geminvoice_staging (
    rowid              INT          NOT NULL AUTO_INCREMENT,
    entity             INT          NOT NULL DEFAULT 1,
    source             VARCHAR(32)  NOT NULL DEFAULT 'gdrive',  -- migration 002
    gdrive_file_id     VARCHAR(255) NOT NULL,
    filename           VARCHAR(255) NOT NULL,
    local_filepath     VARCHAR(500) DEFAULT NULL,
    vendor_name        VARCHAR(255) DEFAULT NULL,
    invoice_number     VARCHAR(100) DEFAULT NULL,
    invoice_date       DATE         DEFAULT NULL,
    total_ht           DOUBLE(24,8) DEFAULT 0,
    total_ttc          DOUBLE(24,8) DEFAULT 0,
    json_data          TEXT         DEFAULT NULL,
    status             SMALLINT     NOT NULL DEFAULT 0,
    error_message      TEXT         DEFAULT NULL,              -- migration 002
    fk_facture_fourn   INT          DEFAULT NULL,
    fk_user_valid      INT          DEFAULT NULL,
    note               TEXT         DEFAULT NULL,
    datec              DATETIME     DEFAULT NULL,
    tms                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rowid),
    INDEX idx_staging_status (status),
    INDEX idx_staging_gdrive_id (gdrive_file_id),
    INDEX idx_staging_entity (entity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
