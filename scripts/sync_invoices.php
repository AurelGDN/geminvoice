#!/usr/bin/env php
<?php
/**
 *  \file       scripts/sync_invoices.php
 *  \ingroup    geminvoice
 *  \brief      CRON script to fetch invoices from Google Drive and process them via Gemini OCR
 */

$sapi_type = php_sapi_name();
$script_file = basename(__FILE__);
$path = __DIR__;

// Block web-context access — this script must only run from the command line.
if (!in_array($sapi_type, array('cli', 'cgi', 'cgi-fcgi'), true)) {
    header('HTTP/1.1 403 Forbidden');
    die("Error: This script must be run from the command line.\n");
}
if (substr($sapi_type, 0, 3) == 'cgi') {
    echo "Error: You are using PHP for CGI. To execute ".$script_file." from command line, you must use PHP for CLI mode.\n";
    exit(-1);
}

// Load Dolibarr environment
require_once dirname(dirname(dirname(dirname(__FILE__))))."/master.inc.php"; // Adjust paths depending on where the script run
require_once DOL_DOCUMENT_ROOT."/core/lib/date.lib.php";

// Load module classes
dol_include_once('/geminvoice/class/gemini.class.php');
dol_include_once('/geminvoice/class/gdrive.class.php');
dol_include_once('/geminvoice/class/mapper.class.php');

global $db, $conf, $user, $langs;

if (empty($conf->geminvoice->enabled)) {
    print "Error: Geminvoice module is not enabled.\n";
    exit(-1);
}

dol_syslog("Geminvoice CRON: Start sync_invoices.php", LOG_INFO);
print "Starting Google Drive to Dolibarr Invoice Synchronization...\n";

$gdrive = new GDriveSync($db);
$gemini = new GeminiOCR($db);
$mapper = new GeminvoiceMapper($db);

// 1. Fetch unprocessed files
$files = $gdrive->getUnprocessedInvoices();

if ($files === false) {
    print "Error fetching files from Google Drive.\n";
    exit(-1);
}

if (empty($files)) {
    print "No new invoices found in the Drive folder.\n";
    exit(0);
}

// Ensure temp directory exists
$temp_dir = $conf->geminvoice->dir_temp;
if (!dol_is_dir($temp_dir)) dol_mkdir($temp_dir);

// 2. Process each file
foreach ($files as $file) {
    print "Processing file: " . $file['name'] . "\n";
    
    $local_path = $temp_dir . '/' . dol_sanitizeFileName($file['name']);
    
    // Download File
    if ($gdrive->downloadInvoice($file['id'], $local_path)) {
        
        $mime = mime_content_type($local_path);
        
        // Analyze via Gemini
        $json_data = $gemini->analyzeInvoice($local_path, $mime);
        
        if ($json_data !== false) {
            // Map to Dolibarr Draft Supplier Invoice
            $invoice_id = $mapper->createSupplierInvoice($json_data, $local_path);
            
            if ($invoice_id > 0) {
                print "Successfully created Dolibarr Invoice Draft ID: $invoice_id\n";
                // Move file in GDrive to processed
                $gdrive->markAsProcessed($file['id']);
            } else {
                print "Failed to create Dolibarr Invoice for file.\n";
            }
        } else {
            print "Gemini API failed to analyze the document.\n";
        }
    } else {
        print "Failed to download file from Google Drive.\n";
    }
}

print "Synchronization complete.\n";
exit(0);

