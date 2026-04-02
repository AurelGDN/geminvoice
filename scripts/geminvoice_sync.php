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
if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (!defined('NOLOGIN')) define('NOLOGIN', '1');
if (!defined('NOCSRFCHECK')) define('NOCSRFCHECK', '1');

$res = 0;
// Prioritize master.inc.php for CLI context
if (! $res && file_exists(dirname(__FILE__)."/../../../master.inc.php"))  $res = @include dirname(__FILE__)."/../../../master.inc.php";
if (! $res && file_exists(dirname(__FILE__)."/../../../../master.inc.php")) $res = @include dirname(__FILE__)."/../../../../master.inc.php";
if (! $res && file_exists(dirname(__FILE__)."/../../../main.inc.php"))    $res = @include dirname(__FILE__)."/../../../main.inc.php";
if (! $res && file_exists(dirname(__FILE__)."/../../../../main.inc.php"))  $res = @include dirname(__FILE__)."/../../../../main.inc.php";

if (! $res) die("Include of master.inc.php or main.inc.php fails. Check script position (DIR: ".dirname(__FILE__).").\n");

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
