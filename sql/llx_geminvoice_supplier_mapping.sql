-- ============================================================
-- Table: llx_geminvoice_supplier_mapping
-- Module: Geminvoice
-- ============================================================

CREATE TABLE IF NOT EXISTS llx_geminvoice_supplier_mapping (
    rowid            INT          NOT NULL AUTO_INCREMENT,
    entity           INT          NOT NULL DEFAULT 1,
    vendor_name      VARCHAR(255) NOT NULL,
    accounting_code  VARCHAR(32)  NOT NULL,
    label            VARCHAR(255) DEFAULT NULL,
    fk_user_creat    INT          DEFAULT NULL,
    datec            DATETIME     DEFAULT NULL,
    tms              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rowid)
) ENGINE=innodb;
