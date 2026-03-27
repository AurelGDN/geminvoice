<?php
/**
 *  \file       scripts/geminvoice_sync.php
 *  \ingroup    geminvoice
 *  \brief      CLI entry point for server-level cron (crontab) — delegates to GeminvoiceCron::runSync()
 *
 *  Usage (server cron):
 *    php /path/to/htdocs/custom/geminvoice/scripts/geminvoice_sync.php
 *
 *  Exit codes: 0 = success, 1 = error
 */

if (!defined('NOSESSION')) define('NOSESSION', '1');

$res = 0;
if (! $res && file_exists("../../../main.inc.php"))  $res = @include "../../../main.inc.php";
if (! $res && file_exists("../../../../main.inc.php")) $res = @include "../../../../main.inc.php";
if (! $res) die("Include of main.inc.php fails. Check script position.\n");

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
dol_include_once('/geminvoice/class/cron.class.php');

global $db;

print "--- Geminvoice Sync CLI --- " . dol_print_date(dol_now(), 'dayhour') . "\n";

$cron   = new GeminvoiceCron();
$result = $cron->runSync();

if ($result === 0) {
    print "Synchronisation terminée avec succès.\n";
    $db->close();
    exit(0);
} else {
    print "Erreur : " . $result . "\n";
    $db->close();
    exit(1);
}
