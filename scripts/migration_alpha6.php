<?php
if (!defined('NOSESSION')) define('NOSESSION', '1');
if (! $res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res) die("Include of main.inc.php fails. Check script position.\n");

global $db;
$sql = "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_staging ADD COLUMN error_message TEXT DEFAULT NULL AFTER status";
print "Executing: $sql\n";
$resql = $db->query($sql);
if ($resql) {
    print "SUCCESS: Column error_message added.\n";
} else {
    if ($db->lasterrno() == '1060') { // Duplicate column
        print "SKIP: Column error_message already exists.\n";
    } else {
        print "ERROR: " . $db->lasterror() . "\n";
    }
}
$db->close();
