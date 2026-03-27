-- ============================================================
-- Table: llx_geminvoice_line_mapping
-- Module: Geminvoice Alpha12
-- Description: Per-line description keyword → accounting code + product mapping
-- ============================================================

CREATE TABLE IF NOT EXISTS llx_geminvoice_line_mapping (
    rowid            INT          NOT NULL AUTO_INCREMENT,
    entity           INT          NOT NULL DEFAULT 1,
    keyword          VARCHAR(255) NOT NULL,
    accounting_code  VARCHAR(32)  NOT NULL,
    vat_rate         DOUBLE       DEFAULT NULL,
    fk_product       INT          DEFAULT NULL,
    is_parafiscal    TINYINT(1)   NOT NULL DEFAULT 0,
    label            VARCHAR(255) DEFAULT NULL,
    fk_user_creat    INT          DEFAULT NULL,
    datec            DATETIME     DEFAULT NULL,
    tms              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rowid),
    UNIQUE KEY uk_keyword_entity (keyword, entity),
    KEY idx_fk_product (fk_product)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
