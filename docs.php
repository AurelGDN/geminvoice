<?php
/**
 *  \file       docs.php
 *  \ingroup    geminvoice
 *  \brief      User documentation for Geminvoice module
 */

// Load Dolibarr environment
$res = 0;
if (! $res && file_exists("./main.inc.php")) $res = @include './main.inc.php';
if (! $res && file_exists("../main.inc.php")) $res = @include '../main.inc.php';
if (! $res && file_exists("../../main.inc.php")) $res = @include '../../main.inc.php';
if (! $res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

global $langs, $user, $conf, $db;

$langs->loadLangs(array("geminvoice@geminvoice", "admin", "help"));

// Check access rights
if (empty($user->rights->geminvoice->read)) accessforbidden();

$tab = GETPOST('tab', 'aZ09') ?: 'intro';

/*
 * View
 */

llxHeader('', $langs->trans("GeminvoiceDocumentation"));

$head = array();
$h = 0;
$head[$h][0] = $_SERVER["PHP_SELF"].'?tab=intro';
$head[$h][1] = $langs->trans("GeminvoiceDocTabIntro");
$head[$h][2] = 'intro';
$h++;
$head[$h][0] = $_SERVER["PHP_SELF"].'?tab=setup';
$head[$h][1] = $langs->trans("GeminvoiceDocTabSetup");
$head[$h][2] = 'setup';
$h++;
$head[$h][0] = $_SERVER["PHP_SELF"].'?tab=sources';
$head[$h][1] = $langs->trans("GeminvoiceDocTabSources");
$head[$h][2] = 'sources';
$h++;
$head[$h][0] = $_SERVER["PHP_SELF"].'?tab=dashboard';
$head[$h][1] = $langs->trans("GeminvoiceDocTabDashboard");
$head[$h][2] = 'dashboard';
$h++;
$head[$h][0] = $_SERVER["PHP_SELF"].'?tab=review';
$head[$h][1] = $langs->trans("GeminvoiceDocTabReview");
$head[$h][2] = 'review';
$h++;
$head[$h][0] = $_SERVER["PHP_SELF"].'?tab=mappings';
$head[$h][1] = $langs->trans("GeminvoiceDocTabMappings");
$head[$h][2] = 'mappings';
$h++;
$head[$h][0] = $_SERVER["PHP_SELF"].'?tab=errors';
$head[$h][1] = $langs->trans("GeminvoiceDocTabErrors");
$head[$h][2] = 'errors';
$h++;

print dol_get_fiche_head($head, $tab, $langs->trans("GeminvoiceUserGuide"), -1, 'help');

// ---------------------------------------------------------------------
// TAB: INTRODUCTION
// ---------------------------------------------------------------------
if ($tab == 'intro') {
    print '<div class="info">' . $langs->trans("GeminvoiceDocIntroSummary") . '</div>';
    
    print '<h3>' . $langs->trans("GeminvoiceDocWhatIsIt") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocIntroDesc") . '</p>';
    
    print '<div style="background:#f8f9fa; border-left:4px solid #3498db; padding:15px; margin:20px 0;">';
    print '<b>' . $langs->trans("GeminvoiceDocWorkflowTitle") . '</b><br>';
    print '<ol>';
    print '<li>' . $langs->trans("GeminvoiceDocWorkflowStep1") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocWorkflowStep2") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocWorkflowStep3") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocWorkflowStep4") . '</li>';
    print '</ol>';
    print '</div>';
    
    print '<h3>' . $langs->trans("GeminvoiceDocPrerequisites") . '</h3>';
    print '<ul>';
    print '<li>' . $langs->trans("GeminvoiceDocPrereq1") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocPrereq2") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocPrereq3") . '</li>';
    print '</ul>';
}

// ---------------------------------------------------------------------
// TAB: SETUP
// ---------------------------------------------------------------------
if ($tab == 'setup') {
    print '<div class="info">' . $langs->trans("GeminvoiceDocSetupSummary") . '</div>';

    print '<div class="warning">' . $langs->trans("GeminvoiceDocSetupWarning") . '</div>';

    print '<h3>' . $langs->trans("GeminvoiceDocSetupStepByStep") . '</h3>';
    print '<ol>';
    print '<li>' . $langs->trans("GeminvoiceDocSetupStep1") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocSetupStep2") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocSetupStep3") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocSetupStep4") . '</li>';
    print '</ol>';

    print '<h3>' . $langs->trans("GeminvoiceDocIndicatorsTitle") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocIndicatorsDesc") . '</p>';
    print '<div style="border:1px solid #d0d7de; padding:12px; border-radius:6px; background:#f8fafb; line-height:2;">';
    print '<span style="color:#27ae60;font-weight:bold;">✅</span> ' . $langs->trans("GeminvoiceDocIndicatorComposer") . '<br>';
    print '<span style="color:#27ae60;font-weight:bold;">✅</span> ' . $langs->trans("GeminvoiceDocIndicatorGemini") . '<br>';
    print '<span style="color:#27ae60;font-weight:bold;">✅</span> ' . $langs->trans("GeminvoiceDocIndicatorDrive");
    print '</div>';
}

// ---------------------------------------------------------------------
// TAB: SOURCES
// ---------------------------------------------------------------------
if ($tab == 'sources') {
    print '<div class="info">' . $langs->trans("GeminvoiceDocSourcesSummaryV2") . '</div>';

    print '<h3><i class="fa-brands fa-google-drive paddingright" style="color:#4285F4;"></i>1. ' . $langs->trans("GeminvoiceSourceGdrive") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocGdriveDesc") . '</p>';
    print '<div class="info">' . $langs->trans("GeminvoiceDocGdriveNote") . '</div>';

    print '<h3><i class="fa fa-upload paddingright" style="color:#27ae60;"></i>2. ' . $langs->trans("GeminvoiceSourceUpload") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocUploadDesc") . '</p>';

    print '<h3><i class="fa fa-file-invoice paddingright" style="color:#8e44ad;"></i>3. ' . $langs->trans("GeminvoiceSourceFacturx") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocFacturxDesc") . '</p>';
    print '<div class="info">' . $langs->trans("GeminvoiceDocFacturxNote") . '</div>';

    print '<h3><i class="fa fa-plug paddingright" style="color:#e74c3c;"></i>4. ' . $langs->trans("GeminvoiceSourcePdp") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocPdpDesc") . '</p>';
    print '<div class="info">' . $langs->trans("GeminvoiceDocPdpNote") . '</div>';
    print '<div style="background:#f8f9fa; border-left:4px solid #e74c3c; padding:15px; margin:20px 0;">';
    print '<b>' . $langs->trans("GeminvoiceDocPdpDifference") . '</b><br>';
    print '<p>' . $langs->trans("GeminvoiceDocPdpDifferenceDesc") . '</p>';
    print '</div>';
}

// ---------------------------------------------------------------------
// TAB: DASHBOARD
// ---------------------------------------------------------------------
if ($tab == 'dashboard') {
    print '<div class="info">' . $langs->trans("GeminvoiceDocDashboardSummary") . '</div>';

    print '<h3>' . $langs->trans("GeminvoiceDocListTitle") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocListDesc") . '</p>';

    print '<ul>';
    print '<li><b>' . $langs->trans("GeminvoiceSource") . ' :</b> ' . $langs->trans("GeminvoiceDocBadgeDescV2");
    print '<div style="margin:6px 0 2px 0;">';
    print '<span style="background:#4285F4;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;margin-right:4px;"><i class="fa-brands fa-google-drive paddingright"></i>' . $langs->trans("GeminvoiceSourceGdrive") . '</span>';
    print '<span style="background:#27ae60;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;margin-right:4px;"><i class="fa fa-upload paddingright"></i>' . $langs->trans("GeminvoiceSourceUpload") . '</span>';
    print '<span style="background:#8e44ad;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;margin-right:4px;"><i class="fa fa-file-invoice paddingright"></i>' . $langs->trans("GeminvoiceSourceFacturx") . '</span>';
    print '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;"><i class="fa fa-plug paddingright"></i>' . $langs->trans("GeminvoiceSourcePdp") . '</span>';
    print '</div></li>';
    print '<li><b>' . $langs->trans("GeminvoiceDuplicate") . ' :</b> ' . $langs->trans("GeminvoiceDocDuplicateDesc") . '</li>';
    print '</ul>';

    print '<h3>' . $langs->trans("GeminvoiceDocActionsTitle") . '</h3>';
    print '<ul>';
    print '<li>' . $langs->trans("GeminvoiceDocActionReview") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocActionValidate") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocActionReject") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocActionRetry") . '</li>';
    print '</ul>';
}

// ---------------------------------------------------------------------
// TAB: REVIEW
// ---------------------------------------------------------------------
if ($tab == 'review') {
    print '<div class="info">' . $langs->trans("GeminvoiceDocReviewSummary") . '</div>';

    // Section 1 — Header
    print '<h3>1. ' . $langs->trans("GeminvoiceDocReviewHeader") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocReviewSocMatch") . '</p>';
    print '<div style="display:flex; gap:8px; flex-wrap:wrap; margin:8px 0 12px 0;">';
    print '<span style="background:#27ae60;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;">✅ ' . $langs->trans("GeminvoiceVendorConfirmed") . '</span>';
    print '<span style="background:#e67e22;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;">🔍 ACME (87%%)</span>';
    print '<span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;">⚠️ ' . $langs->trans("GeminvoiceVendorWillBeCreated") . '</span>';
    print '</div>';
    print '<p>' . $langs->trans("GeminvoiceDocReviewCreditNote") . '</p>';
    print '<p>' . $langs->trans("GeminvoiceDocReviewCurrencyWarning") . '</p>';

    // Section 2 — Lines table
    print '<h3>2. ' . $langs->trans("GeminvoiceDocReviewTable") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocReviewTableDesc") . '</p>';

    print '<b>' . $langs->trans("GeminvoiceDocBadgeLearned") . '</b><br>';
    print '<b>' . $langs->trans("GeminvoiceDocBadgeText") . '</b><br>';
    print '<b>' . $langs->trans("GeminvoiceDocBadgeAI") . '</b><br>';
    print '<b>' . $langs->trans("GeminvoiceDocBadgeVendor") . '</b><br>';
    print '<b>' . $langs->trans("GeminvoiceDocBadgeManual") . '</b>';

    print '<h3>' . $langs->trans("GeminvoiceDocLineTools") . '</h3>';
    print '<ul>';
    print '<li>' . $langs->trans("GeminvoiceDocLineSplit") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocLineDuplicate") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocLineAdd") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocLineMemorize") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocLineParafiscal") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocLineInvert") . '</li>';
    print '</ul>';

    // Section 3 — Consistency
    print '<h3>' . $langs->trans("GeminvoiceDocConsistency") . '</h3>';
    print '<p>' . $langs->trans("GeminvoiceDocConsistencyDesc") . '</p>';
    print '<div style="display:flex; gap:8px; margin:6px 0;">';
    print '<span style="background:#27ae60;color:#fff;padding:3px 10px;border-radius:4px;font-size:0.85em;font-weight:bold;">' . $langs->trans("GeminvoiceConsistencyOk") . '</span>';
    print '<span style="background:#e74c3c;color:#fff;padding:3px 10px;border-radius:4px;font-size:0.85em;font-weight:bold;">' . $langs->trans("GeminvoiceConsistencyError") . '</span>';
    print '</div>';

    // Section 4 — PDP specifics
    print '<h3>3. ' . $langs->trans("GeminvoiceDocReviewPdp") . '</h3>';
    print '<div style="background:#f8f9fa; border-left:4px solid #e74c3c; padding:15px; margin:10px 0 20px 0;">';
    print '<p>' . $langs->trans("GeminvoiceDocReviewPdpDesc") . '</p>';
    print '<ul>';
    print '<li>' . $langs->trans("GeminvoiceDocReviewPdpReadonly") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocReviewPdpEditable") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocReviewPdpNoPreview") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocReviewPdpButton") . '</li>';
    print '</ul>';
    print '</div>';

    // Section 5 — Validation
    print '<h3>4. ' . $langs->trans("GeminvoiceDocValidation") . '</h3>';
    print '<ol>';
    print '<li>' . $langs->trans("GeminvoiceDocValidationStep1") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocValidationStep2") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocValidationStep3") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocValidationStep3Pdp") . '</li>';
    print '</ol>';
}

// ---------------------------------------------------------------------
// TAB: MAPPINGS
// ---------------------------------------------------------------------
if ($tab == 'mappings') {
    print '<div class="info">' . $langs->trans("GeminvoiceDocMappingsSummary") . '</div>';

    print '<h3>' . $langs->trans("GeminvoiceDocMappingsTitle") . '</h3>';
    print '<ul>';
    print '<li>' . $langs->trans("GeminvoiceDocMappingsLine") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocMappingsVendor") . '</li>';
    print '</ul>';
    print '<p>' . $langs->trans("GeminvoiceDocMappingsAuto") . '</p>';

    print '<h3>' . $langs->trans("GeminvoiceDocPriorityTitle") . '</h3>';
    print '<div style="background:#f8fafb; border-left:4px solid #3498db; padding:14px; border-radius:0 6px 6px 0; line-height:2;">';
    $priority = array(
        array('#9b59b6', '🧠', 'P1', $langs->trans("GeminvoiceBadgeMemorized")),
        array('#e67e22', '🔍', 'P2', $langs->trans("GeminvoiceRecognitionTextMatchLabel") . ' ≥ seuil'),
        array('#8e44ad', '🤖', 'P3', $langs->trans("GeminvoiceBadgeIA") . ' Gemini'),
        array('#e67e22', '🔍', 'P4', $langs->trans("GeminvoiceRecognitionTextMatchLabel") . ' (meilleur effort)'),
        array('#7f8c8d', '📄', 'P5', 'Suggestion OCR brute'),
        array('#2980b9', '🏢', 'P6', $langs->trans("GeminvoiceBadgeVendor")),
    );
    foreach ($priority as $p) {
        print '<span style="background:' . $p[0] . ';color:#fff;padding:1px 7px;border-radius:10px;font-size:0.8em;margin-right:6px;">' . $p[1] . ' ' . $p[2] . '</span> ' . dol_escape_htmltag($p[3]) . '<br>';
    }
    print '</div>';
}

// ---------------------------------------------------------------------
// TAB: ERRORS
// ---------------------------------------------------------------------
if ($tab == 'errors') {
    print '<div class="info">' . $langs->trans("GeminvoiceDocErrorsSummary") . '</div>';

    print '<h3>' . $langs->trans("GeminvoiceDocErrorsTypes") . '</h3>';
    print '<ul>';
    print '<li>' . $langs->trans("GeminvoiceDocErrorOCR") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocErrorDrive") . '</li>';
    print '<li>' . $langs->trans("GeminvoiceDocErrorStaging") . '</li>';
    print '</ul>';

    print '<p>' . $langs->trans("GeminvoiceDocErrorPurge") . '</p>';
    print '<div class="warning">' . $langs->trans("GeminvoiceDocErrorResolution") . '</div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
