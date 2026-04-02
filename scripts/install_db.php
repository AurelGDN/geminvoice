<?php
/**
 * Script to forcefully install/update the database tables for Geminvoice.
 * Call this via CLI or browser.
 */

$sapi_type = php_sapi_name();
if (!in_array($sapi_type, array('cli', 'cgi', 'cgi-fcgi'), true)) {
    // If called via web, minimal auth or allow just for admin
    $res = 0;
    if (! $res && file_exists("../../master.inc.php")) $res=@include("../../master.inc.php");
    if (! $res && file_exists("../../../master.inc.php")) $res=@include("../../../master.inc.php");
    if (! $res && file_exists("../../../../master.inc.php")) $res=@include("../../../../master.inc.php");
    if (! $res) die("Include of master.inc.php fails from web context");
    // require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
    // if (!$user->admin) accessforbidden();
} else {
    // CLI
    require_once dirname(dirname(dirname(dirname(__FILE__))))."/master.inc.php";
}

global $db;

print "<b>Starting Geminvoice DB Setup...</b><br>\n";

$sqls = [
    "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "geminvoice_staging (
        rowid              INT          NOT NULL AUTO_INCREMENT,
        entity             INT          NOT NULL DEFAULT 1,
        source             VARCHAR(32)  NOT NULL DEFAULT 'gdrive',
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
        error_message      TEXT         DEFAULT NULL,
        duplicate_warning  VARCHAR(255) DEFAULT NULL,
        fk_facture_fourn   INT          DEFAULT NULL,
        fk_user_valid      INT          DEFAULT NULL,
        note               TEXT         DEFAULT NULL,
        datec              DATETIME     DEFAULT NULL,
        tms                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (rowid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    
    "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "geminvoice_line_mapping (
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
        PRIMARY KEY (rowid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    
    "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping (
        rowid            INT          NOT NULL AUTO_INCREMENT,
        entity           INT          NOT NULL DEFAULT 1,
        vendor_name      VARCHAR(255) NOT NULL,
        accounting_code  VARCHAR(32)  NOT NULL,
        label            VARCHAR(255) DEFAULT NULL,
        fk_user_creat    INT          DEFAULT NULL,
        datec            DATETIME     DEFAULT NULL,
        tms              TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (rowid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_staging ADD INDEX idx_staging_status (status);",
    "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_staging ADD INDEX idx_staging_gdrive_id (gdrive_file_id);",
    "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_staging ADD INDEX idx_staging_entity (entity);",
    
    "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_line_mapping ADD UNIQUE INDEX uk_keyword_entity (keyword, entity);",
    "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_line_mapping ADD INDEX idx_fk_product (fk_product);",
    
    "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping ADD UNIQUE INDEX uk_vendor_entity (vendor_name, entity);"
];

$db->begin();
$error = 0;

foreach ($sqls as $sql) {
    print "Executing: " . substr($sql, 0, 80) . "...<br>\n";
    $resql = $db->query($sql);
    if (!$resql) {
        $err = $db->lasterror();
        // Ignore duplicate key errors for indexes
        if (strpos($err, 'Duplicate key name') !== false) {
            print "=> Ignored (Index already exists)<br>\n";
        } else {
            print "<span style='color:red'>=> Error: " . $err . "</span><br>\n";
            $error++;
        }
    } else {
        print "<span style='color:green'>=> OK</span><br>\n";
    }
}

if ($error) {
    $db->rollback();
    print "<b><br>Status: FAILED with $error error(s). Rolled back.</b><br>\n";
} else {
    $db->commit();
    print "<b><br>Status: SUCCESS! Tables and indexes installed.</b><br>\n";
}

print "<br>You can now test the synchronization.";
?>
