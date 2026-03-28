<?php
/**
 *  \file       index.php
 *  \ingroup    geminvoice
 *  \brief      Main page for Geminvoice — lists pending invoices and triggers sync
 */

// Load Dolibarr environment
$res = 0;
if (! $res && file_exists("../main.inc.php")) $res = @include '../main.inc.php';
if (! $res && file_exists("../../main.inc.php")) $res = @include '../../main.inc.php';
if (! $res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

global $langs, $user, $conf, $db;

$langs->loadLangs(array("geminvoice@geminvoice"));

// Check access rights
if (!$user->hasRight('geminvoice', 'read')) accessforbidden();

$action = GETPOST('action', 'aZ09');

$page = GETPOST('page', 'int');
if (empty($page) || $page < 0) {
    $page = 0;
}
$limit = GETPOST('limit', 'int');
if (empty($limit) || $limit <= 0) {
    $limit = $conf->liste_limit;
}
$offset = ($limit > 0 && $page > 0) ? ($limit * $page) : 0;

$search_filename       = GETPOST('search_filename', 'alphanohtml');
$search_vendor_name    = GETPOST('search_vendor_name', 'alphanohtml');
$search_invoice_number = GETPOST('search_invoice_number', 'alphanohtml');

$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');

$param = '';
if (!empty($search_filename)) $param .= '&search_filename=' . urlencode($search_filename);
if (!empty($search_vendor_name)) $param .= '&search_vendor_name=' . urlencode($search_vendor_name);
if (!empty($search_invoice_number)) $param .= '&search_invoice_number=' . urlencode($search_invoice_number);

/*
 * Actions
 */

// CSRF check for all state-changing actions
if (!empty($action) && $action !== 'list') {
    if (GETPOST('token', 'alpha') !== currentToken()) {
        accessforbidden();
    }
}

// ---- SYNC: Download from Google Drive, analyze with Gemini, push to STAGING ----
if ($action == 'sync') {
    if (!$user->hasRight('geminvoice', 'write')) accessforbidden();

    dol_syslog("Geminvoice: Manual GDrive sync started", LOG_DEBUG);
    dol_include_once('/geminvoice/class/sources/GdriveSource.class.php');

    $source = new GdriveSource($db);
    $result = $source->fetchAndStage();

    if ($result['count'] > 0) {
        setEventMessages($langs->trans("GeminvoiceInvoicesAnalyzed", $result['count']), null, 'mesgs');
    } elseif (empty($result['errors'])) {
        setEventMessages($langs->trans("GeminvoiceNoNewFiles"), null, 'warnings');
    }
    if (!empty($result['errors'])) {
        setEventMessages($langs->trans("GeminvoiceFilesWithError", count($result['errors'])), null, 'errors');
        foreach ($result['errors'] as $err) {
            setEventMessages($err, null, 'errors');
        }
    }
}

// ---- UPLOAD BATCH (PDF/images) ----
if ($action == 'upload_batch') {
    if (!$user->hasRight('geminvoice', 'write')) accessforbidden();

    dol_include_once('/geminvoice/class/sources/UploadSource.class.php');
    $source = new UploadSource($db);

    if (!$source->isEnabled()) {
        setEventMessages($langs->trans("GeminvoiceErrorMissingConfig"), null, 'errors');
    } else {
        $result = $source->fetchAndStage();
        if ($result['count'] > 0) {
            setEventMessages($langs->trans("GeminvoiceInvoicesAnalyzed", $result['count']), null, 'mesgs');
        }
        if (!empty($result['errors'])) {
            setEventMessages($langs->trans("GeminvoiceFilesWithError", count($result['errors'])), null, 'errors');
            foreach ($result['errors'] as $err) {
                setEventMessages($err, null, 'errors');
            }
        }
    }
}

// ---- UPLOAD FACTUR-X (structured XML / PDF-A3) ----
if ($action == 'upload_facturx') {
    if (!$user->hasRight('geminvoice', 'write')) accessforbidden();

    dol_include_once('/geminvoice/class/sources/FacturxSource.class.php');
    $source = new FacturxSource($db);
    $result = $source->fetchAndStage();

    if ($result['count'] > 0) {
        setEventMessages($langs->trans("GeminvoiceInvoicesAnalyzed", $result['count']), null, 'mesgs');
    }
    if (!empty($result['errors'])) {
        setEventMessages($langs->trans("GeminvoiceFilesWithError", count($result['errors'])), null, 'errors');
        foreach ($result['errors'] as $err) {
            setEventMessages($err, null, 'errors');
        }
    }
}

// ---- SYNC PDP: Import eligible invoices from PDPConnectFR ----
if ($action == 'sync_pdp') {
    if (!$user->hasRight('geminvoice', 'write')) accessforbidden();

    dol_include_once('/geminvoice/class/sources/PdpSource.class.php');
    $source = new PdpSource($db);

    if (!$source->isEnabled()) {
        setEventMessages($langs->trans("GeminvoicePdpDisabled"), null, 'errors');
    } else {
        $result = $source->fetchAndStage();
        if ($result['count'] > 0) {
            setEventMessages($langs->trans("GeminvoicePdpImported", $result['count']), null, 'mesgs');
        } elseif (empty($result['errors'])) {
            setEventMessages($langs->trans("GeminvoicePdpNoNewInvoices"), null, 'warnings');
        }
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                setEventMessages($err, null, 'errors');
            }
        }
    }
}

// ---- PURGE ALL (pending + errors) ----
if ($action == 'purge_all' && $user->hasRight('geminvoice', 'write')) {
    dol_include_once('/geminvoice/class/staging.class.php');
    $staging = new GeminvoiceStaging($db);
    if ($staging->purgeAll() > 0) {
        setEventMessages($langs->trans("GeminvoicePurgeAllSuccess"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("GeminvoiceErrorPurgeFailed", $staging->error), null, 'errors');
    }
}

// ---- PURGE ERRORS ----
if ($action == 'purge_errors' && $user->hasRight('geminvoice', 'write')) {
    dol_include_once('/geminvoice/class/staging.class.php');
    $staging = new GeminvoiceStaging($db);
    if ($staging->purgeErrors() > 0) {
        setEventMessages($langs->trans("GeminvoicePurgeSuccess"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("GeminvoiceErrorPurgeFailed", $staging->error), null, 'errors');
    }
}

// ---- RETRY from list ----
if ($action == 'retry' && $user->hasRight('geminvoice', 'write')) {
    $staging_id = GETPOSTINT('id');
    dol_include_once('/geminvoice/class/staging.class.php');
    dol_include_once('/geminvoice/class/gemini.class.php');
    
    $staging = new GeminvoiceStaging($db);
    if ($staging->fetch($staging_id) > 0) {
        $gemini = new GeminiOCR($db);
        $mime = 'application/pdf'; // default
        if (function_exists('mime_content_type') && file_exists($staging->local_filepath)) {
            $mime = mime_content_type($staging->local_filepath);
        }
        $json_data = $gemini->analyzeInvoice($staging->local_filepath, $mime);
        
        if ($json_data) {
            $staging->update($staging_id, array(
                'status'        => GeminvoiceStaging::STATUS_PENDING,
                'json_data'     => $json_data,
                'vendor_name'   => $json_data['vendor_name'],
                'invoice_number'=> $json_data['invoice_number'],
                'invoice_date'  => !empty($json_data['date']) ? $json_data['date'] : 'null',
                'total_ht'      => !empty($json_data['total_ht']) ? $json_data['total_ht'] : 0,
                'total_ttc'     => !empty($json_data['total_ttc']) ? $json_data['total_ttc'] : 0,
                'error_message' => null
            ));
            setEventMessages($langs->trans("GeminvoiceRetrySuccess"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("GeminvoiceRetryFailed", $gemini->error), null, 'errors');
            $staging->update($staging_id, array('error_message' => "Retry failed: " . $gemini->error));
        }
    }
}

// ---- QUICK VALIDATE from list ----
if ($action == 'validate' && $user->hasRight('geminvoice', 'write')) {
    $staging_id = GETPOSTINT('id');
    dol_include_once('/geminvoice/class/staging.class.php');
    $staging    = new GeminvoiceStaging($db);
    $invoice_id = $staging->validate($staging_id, '');
    if ($invoice_id > 0) {
        setEventMessages($langs->trans("GeminvoiceQuickValidateSuccess", $invoice_id), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("GeminvoiceErrorValidationFailed", $staging->error), null, 'errors');
    }
}

// ---- REJECT from list ----
if ($action == 'reject' && $user->hasRight('geminvoice', 'write')) {
    $staging_id = GETPOSTINT('id');
    dol_include_once('/geminvoice/class/staging.class.php');
    $staging = new GeminvoiceStaging($db);
    if ($staging->reject($staging_id) > 0) {
        setEventMessages($langs->trans("GeminvoiceInvoiceRejected"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("GeminvoiceErrorRejectionFailed", $staging->error), null, 'errors');
    }
}

/*
 * View
 */

llxHeader('', $langs->trans("GeminvoiceDashboard"));

dol_include_once('/geminvoice/class/staging.class.php');
$staging = new GeminvoiceStaging($db);

print load_fiche_titre($langs->trans("GeminvoiceDashboard"), $linkback, 'bill');

// --- Source import cards
print '<div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:24px;">';

// ---- Card 1: Google Drive ----
dol_include_once('/geminvoice/class/sources/GdriveSource.class.php');
$gdrive_src = new GdriveSource($db);
$gdrive_configured = $gdrive_src->isConfigured();
print '<div style="flex:1; min-width:240px; border:1px solid #d0d7de; border-radius:8px; padding:16px;">';
print '<div style="font-weight:bold; margin-bottom:8px;"><i class="fa-brands fa-google-drive paddingright" style="color:#4285F4;"></i>' . $langs->trans('GeminvoiceSourceGdrive') . '</div>';
if ($gdrive_configured && $user->hasRight('geminvoice', 'write')) {
    print '<a class="butAction" style="display:inline-block;" id="geminvoice-sync-btn" href="' . $_SERVER["PHP_SELF"] . '?action=sync&token=' . newToken() . '" onclick="startSync(this);">';
    print img_picto('', 'refresh', 'class="paddingright"') . $langs->trans("GeminvoiceRunManualSync");
    print '</a>';
    print '<div id="sync-overlay" style="display:none; margin-top:8px; font-size:0.9em; color:#2980b9; font-style:italic;">';
    print img_picto('', 'refresh', 'class="fa-spin paddingright"') . $langs->trans("GeminvoiceSyncInProgress");
    print '</div>';
} elseif (!$gdrive_configured) {
    print '<span class="opacitymedium">' . $langs->trans("GeminvoiceNotConfigured") . ' — <a href="admin/setup.php">' . $langs->trans("GeminvoiceSetup") . '</a></span>';
}
print '</div>';

// ---- Card 2: Upload PDF/image batch ----
dol_include_once('/geminvoice/class/sources/UploadSource.class.php');
$upload_src = new UploadSource($db);
print '<div style="flex:1; min-width:240px; border:1px solid #d0d7de; border-radius:8px; padding:16px;">';
print '<div style="font-weight:bold; margin-bottom:8px;"><i class="fa fa-upload paddingright" style="color:#27ae60;"></i>' . $langs->trans('GeminvoiceSourceUpload') . '</div>';
if ($upload_src->isEnabled() && $user->hasRight('geminvoice', 'write')) {
    print '<form method="POST" action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '" enctype="multipart/form-data" style="margin:0;">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="upload_batch">';
    print '<input type="file" name="geminvoice_uploads[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" style="margin-bottom:8px; display:block; max-width:100%;">';
    print '<button type="submit" class="butAction" style="margin-top:4px;">';
    print img_picto('', 'upload', 'class="paddingright"') . $langs->trans("GeminvoiceUploadAndAnalyze");
    print '</button>';
    print '</form>';
} elseif (!$upload_src->isEnabled()) {
    print '<span class="opacitymedium">' . $langs->trans("GeminvoiceNotConfigured") . ' — <a href="admin/setup.php">' . $langs->trans("GeminvoiceSetup") . '</a></span>';
}
print '</div>';

// ---- Card 3: Factur-X / structured XML ----
dol_include_once('/geminvoice/class/sources/FacturxSource.class.php');
$facturx_src = new FacturxSource($db);
print '<div style="flex:1; min-width:240px; border:1px solid #d0d7de; border-radius:8px; padding:16px;">';
print '<div style="font-weight:bold; margin-bottom:8px;"><i class="fa fa-file-invoice paddingright" style="color:#8e44ad;"></i>' . $langs->trans('GeminvoiceSourceFacturx') . '</div>';
if ($user->hasRight('geminvoice', 'write')) {
    print '<form method="POST" action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '" enctype="multipart/form-data" style="margin:0;">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="upload_facturx">';
    print '<input type="file" name="geminvoice_facturx[]" multiple accept=".xml,.pdf" style="margin-bottom:8px; display:block; max-width:100%;">';
    print '<button type="submit" class="butAction" style="margin-top:4px;">';
    print img_picto('', 'upload', 'class="paddingright"') . $langs->trans("GeminvoiceImportFacturx");
    print '</button>';
    print '</form>';
    print '<div style="font-size:0.82em; color:#7f8c8d; margin-top:6px;">' . $langs->trans("GeminvoiceSourceFacturxHint") . '</div>';
}
print '</div>';

// ---- Card 4: PDPConnectFR (e-invoicing) ----
if (isModEnabled('pdpconnectfr')) {
    dol_include_once('/geminvoice/class/sources/PdpSource.class.php');
    $pdp_src = new PdpSource($db);
    print '<div style="flex:1; min-width:240px; border:1px solid #d0d7de; border-radius:8px; padding:16px;">';
    print '<div style="font-weight:bold; margin-bottom:8px;"><i class="fa fa-plug paddingright" style="color:#e74c3c;"></i>' . $langs->trans('GeminvoiceSourcePdp') . '</div>';
    if ($pdp_src->isEnabled() && $user->hasRight('geminvoice', 'write')) {
        $pdp_eligible = $pdp_src->countEligible();
        print '<a class="butAction" style="display:inline-block;" href="' . $_SERVER["PHP_SELF"] . '?action=sync_pdp&token=' . newToken() . '">';
        print img_picto('', 'refresh', 'class="paddingright"') . $langs->trans("GeminvoicePdpSyncNow");
        print '</a>';
        if ($pdp_eligible > 0) {
            print '<div style="margin-top:8px; font-size:0.88em; color:#e74c3c; font-weight:bold;">';
            print $langs->trans("GeminvoicePdpEligible", $pdp_eligible);
            print '</div>';
        } else {
            print '<div style="margin-top:8px; font-size:0.88em; color:#7f8c8d;">' . $langs->trans("GeminvoicePdpNoNewInvoices") . '</div>';
        }
    } elseif (!$pdp_src->isEnabled()) {
        print '<span class="opacitymedium">' . $langs->trans("GeminvoicePdpDisabled") . ' — <a href="admin/setup.php">' . $langs->trans("GeminvoiceSetup") . '</a></span>';
    }
    print '</div>';
}

print '</div>'; // end flex row

// Management buttons (purge)
if ($user->hasRight('geminvoice', 'write')) {
    $total_pending = $staging->countAll(array('status' => array(GeminvoiceStaging::STATUS_PENDING, GeminvoiceStaging::STATUS_ERROR)));
    $error_count   = $staging->countAll(array('status' => GeminvoiceStaging::STATUS_ERROR));
    if ($total_pending > 0) {
        print '<a class="butActionDelete" href="' . $_SERVER["PHP_SELF"] . '?action=purge_all&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("GeminvoiceConfirmPurgeAll")) . '\');">';
        print img_picto('', 'delete', 'class="paddingright"') . $langs->trans("GeminvoiceClearList");
        print '</a>';
    }
    if ($error_count > 0 && $total_pending > $error_count) {
        print ' <a class="butActionDelete" href="' . $_SERVER["PHP_SELF"] . '?action=purge_errors&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("GeminvoiceConfirmPurgeErrors")) . '\');">';
        print img_picto('', 'delete', 'class="paddingright"') . $langs->trans("GeminvoicePurgeErrors") . ' (' . $error_count . ')';
        print '</a>';
    }
    print '<br><br>';
}

// --- Files list (Pending + Error)
$filters = array(
    'status'         => array(GeminvoiceStaging::STATUS_PENDING, GeminvoiceStaging::STATUS_ERROR),
    'filename'       => $search_filename,
    'vendor_name'    => $search_vendor_name,
    'invoice_number' => $search_invoice_number
);

$pendings = $staging->fetchAll($limit + 1, $offset, $filters);
$num      = is_array($pendings) ? count($pendings) : 0;
$total    = $staging->countAll($filters);

print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="list">';

print_barre_liste(
    $langs->trans("GeminvoicePendingInvoices"),
    $page,
    $_SERVER['PHP_SELF'],
    $param,
    $sortfield,
    $sortorder,
    '',
    $num,
    $total,
    'bill',
    0, 
    "",
    "",
    $limit
);

if ($num > 0) {
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print ' <th class="liste_titre">' . $langs->trans("GeminvoiceFileName") . '</th>';
    print ' <th class="liste_titre">' . $langs->trans("GeminvoiceVendorName") . '</th>';
    print ' <th class="liste_titre">' . $langs->trans("GeminvoiceInvoiceNumber") . '</th>';
    print ' <th class="liste_titre">' . $langs->trans("GeminvoiceDate") . '</th>';
    print ' <th class="liste_titre right">' . $langs->trans("GeminvoiceTotalHT") . '</th>';
    print ' <th class="liste_titre right">' . $langs->trans("GeminvoiceTotalTTC") . '</th>';
    print ' <th class="liste_titre center">' . $langs->trans("GeminvoiceSource") . '</th>';
    print ' <th class="liste_titre center">' . $langs->trans("GeminvoiceDuplicate") . '</th>';
    print ' <th class="liste_titre center">' . $langs->trans("GeminvoiceActions") . '</th>';
    print '</tr>';

    // Filters row
    print '<tr class="liste_titre">';
    print ' <th class="liste_titre"><input type="text" class="flat" name="search_filename" value="' . dol_escape_htmltag($search_filename) . '" size="10"></th>';
    print ' <th class="liste_titre"><input type="text" class="flat" name="search_vendor_name" value="' . dol_escape_htmltag($search_vendor_name) . '" size="10"></th>';
    print ' <th class="liste_titre"><input type="text" class="flat" name="search_invoice_number" value="' . dol_escape_htmltag($search_invoice_number) . '" size="8"></th>';
    print ' <th class="liste_titre"></th>';
    print ' <th class="liste_titre"></th>';
    print ' <th class="liste_titre"></th>';
    print ' <th class="liste_titre"></th>';
    print ' <th class="liste_titre"></th>';
    print ' <th class="liste_titre maxwidthsearch right"><button type="submit" class="button"><i class="fa fa-search"></i> ' . $langs->trans("Search") . '</button></th>';
    print '</tr>';

    $i = 0;
    foreach ($pendings as $row) {
        if ($i >= $limit) break;
        $duplicate = $staging->findDuplicate($row->vendor_name, $row->invoice_number);
        print '<tr class="oddeven">';
        print ' <td>' . dol_escape_htmltag($row->filename) . '</td>';
        print ' <td>' . dol_escape_htmltag($row->vendor_name) . '</td>';
        print ' <td>' . dol_escape_htmltag($row->invoice_number) . '</td>';
        print ' <td>' . dol_print_date(strtotime($row->invoice_date), 'day') . '</td>';
        print ' <td class="right">' . price($row->total_ht) . '</td>';
        print ' <td class="right">' . price($row->total_ttc) . '</td>';

        // Source badge
        $src_label = dol_escape_htmltag($row->source ?? 'gdrive');
        $src_colors = array('gdrive' => '#4285F4', 'upload' => '#27ae60', 'facturx' => '#8e44ad', 'pdp' => '#e74c3c');
        $src_color  = $src_colors[$row->source] ?? '#7f8c8d';
        $src_icons  = array('gdrive' => 'fa-brands fa-google-drive', 'upload' => 'fa fa-upload', 'facturx' => 'fa fa-file-invoice', 'pdp' => 'fa fa-plug');
        $src_icon   = $src_icons[$row->source] ?? 'fa fa-question';
        print ' <td class="center"><span style="background:' . $src_color . ';color:#fff;padding:2px 8px;border-radius:10px;font-size:0.78em;white-space:nowrap;">';
        print '<i class="' . $src_icon . ' paddingright"></i>' . $src_label . '</span></td>';

        // Error badge if status is -1
        if ($row->status == GeminvoiceStaging::STATUS_ERROR) {
            print ' <td class="center" colspan="2">';
            print '<span style="background:#c0392b;color:#fff;padding:2px 10px;border-radius:10px;font-weight:bold;cursor:help;" title="'.dol_escape_htmltag($row->error_message).'">⚠️ ' . $langs->trans("GeminvoiceAnalysisError") . '</span>';
            if ($user->hasRight('geminvoice', 'write')) {
                print ' <a class="butActionSmall" href="' . $_SERVER["PHP_SELF"] . '?action=retry&id=' . ((int) $row->id) . '&token=' . newToken() . '" title="' . $langs->trans("GeminvoiceRetryAIAnalysis") . '">';
                print img_picto('', 'refresh', 'style="color:#2980b9;"') . ' ' . $langs->trans("GeminvoiceRetry") . '</a>';
                print ' <a href="' . $_SERVER["PHP_SELF"] . '?action=reject&id=' . ((int) $row->id) . '&token=' . newToken() . '" onclick="return confirm(\'' . $langs->trans("GeminvoiceConfirmDeleteError") . '\');" title="' . $langs->trans("Delete") . '">';
                print img_picto('', 'delete', 'style="margin-left:10px;color:#c0392b;"') . '</a>';
            }
            print '</td>';
        } else {
            // Doublon badge
            print ' <td class="center">';
            if ($duplicate) {
                $dupColor = !empty($duplicate->via_pdp) ? '#8e44ad' : '#e67e22';
                $dupIcon  = !empty($duplicate->via_pdp) ? '⚡' : '⚠️';
                $dupTitle = !empty($duplicate->via_pdp)
                    ? $langs->trans("GeminvoiceDuplicatePDP", dol_escape_htmltag($duplicate->ref))
                    : $langs->trans("GeminvoiceInvoiceAlreadyRegistered", dol_escape_htmltag($duplicate->ref));
                print '<a href="' . dol_escape_htmltag($duplicate->url) . '" target="_blank"'
                    . ' title="' . $dupTitle . '"'
                    . ' style="background:' . $dupColor . ';color:#fff;padding:2px 7px;border-radius:4px;font-size:0.82em;text-decoration:none;">' . $dupIcon . ' ' . dol_escape_htmltag($duplicate->ref) . '</a>';
            } else {
                print '<span style="color:#27ae60;font-weight:bold;" title="' . $langs->trans("GeminvoiceNoDuplicateDetected") . '">✅</span>';
            }
            print ' </td>';
            print ' <td class="center">';
            print '<div style="display:flex; justify-content:center; gap:6px; flex-wrap:wrap;">';
            print '<a class="butActionSmall" href="review.php?id=' . ((int) $row->id) . '">' . img_picto('', 'edit') . ' ' . $langs->trans("GeminvoiceReview") . '</a>';
            if ($user->hasRight('geminvoice', 'write')) {
                if ($duplicate) {
                    print '<span class="butActionSmall" style="opacity:0.4;cursor:not-allowed;" title="' . $langs->trans("GeminvoiceDuplicateDetectedReviewOrReject") . '">' . img_picto('', 'tick') . ' ' . $langs->trans("GeminvoiceValidate") . '</span>';
                } else {
                    print '<a class="butActionSmall" style="color:#27ae60; border-color:#27ae60;" href="' . $_SERVER["PHP_SELF"] . '?action=validate&id=' . ((int) $row->id) . '&token=' . newToken() . '" onclick="return confirm(\'' . $langs->trans("GeminvoiceConfirmValidateWithoutReview") . '\');">' . img_picto('', 'tick', 'style="color:#27ae60;"') . ' ' . $langs->trans("GeminvoiceValidate") . '</a>';
                }
                print '<a class="butActionSmallDelete" style="color:#c0392b; border-color:#c0392b;" href="' . $_SERVER["PHP_SELF"] . '?action=reject&id=' . ((int) $row->id) . '&token=' . newToken() . '" onclick="return confirm(\'' . $langs->trans("GeminvoiceConfirmRejectInvoice") . '\');">' . img_picto('', 'delete', 'style="color:#c0392b;"') . ' ' . $langs->trans("GeminvoiceReject") . '</a>';
            }
            print '</div>';
            print ' </td>';
        }
        print '</tr>';

        // Re-inject error details on a separate sub-row if in error
        if ($row->status == GeminvoiceStaging::STATUS_ERROR) {
            print '<tr class="oddeven opacitymedium" style="background:#fdf2f2;">';
            print ' <td colspan="9" style="font-size: 0.9em; padding-left: 20px; border-bottom: 2px solid #f5c6cb;">';
            print ' <i class="fa fa-info-circle" style="color:#c0392b;"></i> ' . $langs->trans("GeminvoiceGeminiFailDetail") . ' <b>' . dol_escape_htmltag($row->error_message) . '</b>';
            print ' </td>';
            print '</tr>';
        }
        $i++;
    }
    print '</table><br>';
} else {
    print '<div class="opacitymedium">' . $langs->trans("NoRecordFound") . '</div><br>';
}
print '</form>';

print '
<div id="review-loading-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.45); z-index:9999; align-items:center; justify-content:center; flex-direction:column;">
  <div style="background:#fff; border-radius:10px; padding:32px 48px; text-align:center; box-shadow:0 4px 24px rgba(0,0,0,0.25);">
    <div style="font-size:2.5em; margin-bottom:12px;">⏳</div>
    <div style="font-size:1.1em; font-weight:bold; color:#2c3e50;">' . $langs->trans("GeminvoiceSyncInProgress") . '</div>
    <div style="margin-top:8px; font-size:0.9em; color:#7f8c8d;">' . $langs->trans("GeminvoiceSyncWarning") . '</div>
  </div>
</div>

<script>
function startSync(btn) {
    var overlay = document.getElementById(\'sync-overlay\');
    if (overlay) {
        overlay.style.display = \'block\';
    }
    btn.style.display = \'none\';
}

document.addEventListener(\'DOMContentLoaded\', function() {
    var overlay = document.getElementById(\'review-loading-overlay\');
    document.querySelectorAll(\'a.butActionSmall[href*="review.php"]\').forEach(function(link) {
        link.addEventListener(\'click\', function() {
            overlay.style.display = \'flex\';
        });
    });
});
</script>';

llxFooter();
$db->close();
