<?php
/**
 *  \file       admin/setup.php
 *  \ingroup    geminvoice
 *  \brief      Setup page for Geminvoice module (Alpha13)
 */

// Load Dolibarr environment
$res = 0;
if (! $res && file_exists("../../main.inc.php")) $res = @include '../../main.inc.php';
if (! $res && file_exists("../../../main.inc.php")) $res = @include '../../../main.inc.php';
if (! $res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

global $langs, $user, $conf, $db;

$langs->loadLangs(array("admin", "geminvoice@geminvoice"));

// Check access rights
if (!$user->admin && empty($user->rights->geminvoice->write)) accessforbidden();

$action = GETPOST('action', 'aZ09');

// Define constants to display/save
$formParams = array(
    'GEMINVOICE_GDRIVE_FOLDER_ID',
    'GEMINVOICE_GEMINI_API_KEY',
    'GEMINVOICE_GEMINI_MODEL',
    'GEMINVOICE_GDRIVE_AUTH_JSON'
);

/*
 * Actions
 */
if ($action == 'update') {
    $error = 0;

    if (GETPOST('token', 'alpha') !== $_SESSION['token']) {
        $error++;
        setEventMessages($langs->trans("ErrorToken"), null, 'errors');
    }

    if (!$error) {
        foreach ($formParams as $param) {
            if ($param == 'GEMINVOICE_GDRIVE_AUTH_JSON') {
                $val = GETPOST($param, 'none');
            } else {
                $val = GETPOST($param, 'alpha');
            }
            $res = dolibarr_set_const($db, $param, $val, 'chaine', 0, '', $conf->entity);
            if ($res <= 0) $error++;
        }

        if (!$error) {
            setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
        } else {
            setEventMessages($langs->trans("ErrorSetupNotSaved"), null, 'errors');
        }
    }
}

// Save recognition settings
if ($action == 'update_recognition') {
    $error = 0;
    if (GETPOST('token', 'alpha') !== $_SESSION['token']) {
        $error++;
        setEventMessages($langs->trans("ErrorToken"), null, 'errors');
    }
    if (!$error) {
        dolibarr_set_const($db, 'GEMINVOICE_RECOGNITION_TEXTMATCH', GETPOSTINT('GEMINVOICE_RECOGNITION_TEXTMATCH') ? '1' : '0', 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'GEMINVOICE_RECOGNITION_AI', GETPOSTINT('GEMINVOICE_RECOGNITION_AI') ? '1' : '0', 'chaine', 0, '', $conf->entity);
        $threshold = max(0, min(100, GETPOSTINT('GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD')));
        dolibarr_set_const($db, 'GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD', (string) $threshold, 'chaine', 0, '', $conf->entity);
        $max_calls = max(1, min(20, GETPOSTINT('GEMINVOICE_RECOGNITION_AI_MAX_CALLS')));
        dolibarr_set_const($db, 'GEMINVOICE_RECOGNITION_AI_MAX_CALLS', (string) $max_calls, 'chaine', 0, '', $conf->entity);
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    }
}

// ACTION: Run composer install
if ($action == 'composer_install' && !empty($user->rights->geminvoice->write)) {
    if (GETPOST('token', 'alpha') != $_SESSION['token']) {
        accessforbidden();
    }

    @ini_set('max_execution_time', 600);
    @ini_set('memory_limit', '1024M');

    $moduledir = dol_buildpath('/geminvoice', 0);

    $composer_bin = 'composer';
    if (function_exists('shell_exec')) {
        $path_test = shell_exec('which composer 2>/dev/null');
        if (!empty($path_test)) $composer_bin = trim($path_test);
        $cmd    = "cd " . escapeshellarg($moduledir) . " && " . escapeshellarg($composer_bin) . " install --no-dev --no-interaction 2>&1";
        $output = shell_exec($cmd);
    } else {
        $output = $langs->trans("GeminvoiceErrorShellDisabled");
    }

    if ($output) {
        $color = (stripos($output, 'error') !== false || stripos($output, 'failed') !== false) ? '#c0392b' : '#27ae60';
        setEventMessages($langs->trans("GeminvoiceInstallResult") . " <pre style='background:#f9f9f9;border:1px solid #ccc;padding:10px;font-size:0.8em;border-left:5px solid " . $color . "; overflow-x:auto; white-space:pre-wrap;'>" . dol_escape_htmltag($output) . "</pre>", null, 'mesgs');
    } else {
        setEventMessages($langs->trans("GeminvoiceInstallNoOutput", get_current_user(), $moduledir), null, 'errors');
    }
}


/*
 * View
 */

llxHeader('', $langs->trans("ModuleGeminvoiceName") . ' — ' . $langs->trans("Settings"));

$form = new Form($db);

// Check Google SDK availability
if (!class_exists('Google\Client')) {
    $extra_autoload = dol_buildpath('/geminvoice/vendor/autoload.php', 0);
    if (!empty($extra_autoload) && file_exists($extra_autoload)) {
        require_once $extra_autoload;
    }
}
$google_client_found = class_exists('Google\Client');

// Fetch available Gemini models dynamically if API Key is set
$available_models_disp = array('' => '-- ' . $langs->trans("GeminvoiceDefault") . ' (gemini-1.5-flash) --');
if (!empty($conf->global->GEMINVOICE_GEMINI_API_KEY)) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("x-goog-api-key: " . $conf->global->GEMINVOICE_GEMINI_API_KEY));
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $data = json_decode($response, true);
        if (!empty($data['models'])) {
            foreach ($data['models'] as $m) {
                if (strpos($m['name'], 'models/gemini') !== false && !empty($m['supportedGenerationMethods']) && in_array('generateContent', $m['supportedGenerationMethods'])) {
                    $m_id = str_replace('models/', '', $m['name']);
                    $blacklist = array('banana', 'Banana', 'robotics', 'vision', 'imagine', 'audio', 'video');
                    $is_blacklisted = false;
                    foreach ($blacklist as $word) {
                        if (stripos($m_id, $word) !== false || stripos($m['displayName'], $word) !== false) {
                            $is_blacklisted = true;
                            break;
                        }
                    }
                    if ($is_blacklisted) continue;
                    $available_models[$m_id] = $m['displayName'] . ' (' . $m_id . ')';
                }
            }
        }
    }
}

$moduledir = dol_buildpath('/geminvoice', 0);

$head = array();
$h = 0;
$head[$h][0] = DOL_URL_ROOT.'/admin/geminvoice/setup.php';
$head[$h][1] = $langs->trans("Settings");
$head[$h][2] = 'settings';
$h++;
$head[$h][0] = dol_buildpath('/geminvoice/docs.php', 1);
$head[$h][1] = $langs->trans("GeminvoiceDocumentation");
$head[$h][2] = 'docs';
$h++;
print dol_get_fiche_head($head, 'settings', $langs->trans("ModuleGeminvoiceName"), -1, 'technic');

// =====================================================================
// SECTION 1 — Paramètres API & Drive
// =====================================================================
print load_fiche_titre($langs->trans("GeminvoiceGeminiAPI") . ' &amp; ' . $langs->trans("GeminvoiceGDriveSettings"), '', 'title_setup');

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>' . $langs->trans("Parameters") . '</td>';
print '<td>' . $langs->trans("Value") . '</td>';
print '</tr>';

foreach ($formParams as $param) {
    print '<tr class="oddeven">';
    $tooltip = $langs->trans($param . 'Tooltip');
    print '<td><span class="fieldrequired">' . $langs->trans($param) . '</span>';
    if ($tooltip && $tooltip != $param . 'Tooltip') {
        print ' ' . $form->textwithpicto('', $tooltip, 1, 'help');
    }
    print '</td>';

    $type = (strpos($param, 'API_KEY') !== false) ? 'password' : 'text';

    print '<td>';
    if ($param == 'GEMINVOICE_GDRIVE_AUTH_JSON') {
        $json_val = !empty($conf->global->$param) ? $conf->global->$param : '';
        print '<textarea class="flat minwidth300" name="' . $param . '" rows="6" style="width:100%; font-family:monospace; font-size:0.8em;">' . htmlspecialchars($json_val, ENT_NOQUOTES, 'UTF-8') . '</textarea>';
    } elseif ($param == 'GEMINVOICE_GEMINI_MODEL') {
        if (!empty($available_models)) {
            print '<select name="' . $param . '" class="flat minwidth300">';
            foreach ($available_models as $m_id => $m_name) {
                $selected = (!empty($conf->global->$param) && $conf->global->$param == $m_id) ? 'selected' : '';
                print '<option value="' . dol_escape_htmltag($m_id) . '" ' . $selected . '>' . dol_escape_htmltag($m_name) . '</option>';
            }
            print '</select>';
            print ' <span class="opacitymedium">(' . $langs->trans("GeminvoiceRequiredForOCR") . ')</span>';
        } else {
            print '<input type="text" class="flat minwidth300" name="' . $param . '" value="' . dol_escape_htmltag(!empty($conf->global->$param) ? $conf->global->$param : '') . '" placeholder="gemini-1.5-flash">';
            print '<br><span class="opacitymedium" style="color:#d35400;">⚠️ ' . $langs->trans("GeminvoiceGeminiAPI") . ' — ' . $langs->trans("GeminvoiceDefault") . '</span>';
        }
    } else {
        print '<input type="' . $type . '" class="flat minwidth300" name="' . $param . '" value="' . dol_escape_htmltag(!empty($conf->global->$param) ? $conf->global->$param : '') . '">';
    }
    print '</td></tr>';
}

print '</table>';
print '<div class="center" style="margin-top:15px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

// =====================================================================
// SECTION 2 — Prérequis & Installation (GCP + SDK + Cron)
// =====================================================================
print '<br>';
print load_fiche_titre($langs->trans("GeminvoiceSetupHelp"), '', 'title_setup');

print '<div class="info">';

// --- 2a. Instructions Google Cloud Platform ---
print '<b>' . $langs->trans("GeminvoiceSetupInstructionsTitle") . '</b><br><br>';
print nl2br($langs->trans("GeminvoiceSetupInstructions"));

print '<hr style="margin:18px 0; border:none; border-top:1px solid #ddd;">';

// --- 2b. Installation SDK Google ---
print '<b>' . $langs->trans("GeminvoiceGoogleSDKInstallation") . '</b><br>';
print $langs->trans("GeminvoiceGoogleSDKDesc") . '<br><br>';

$shell_ok = function_exists('shell_exec');
$shell_status = $shell_ok
    ? '<span style="color:#27ae60;">' . $langs->trans("GeminvoiceEnabled") . '</span>'
    : '<span style="color:#c0392b;">' . $langs->trans("GeminvoiceDisabledPHPRestriction") . '</span>';
print $langs->trans("GeminvoiceSystemCommandsStatus") . ' ' . $shell_status . '<br><br>';

if (!$shell_ok) {
    print '<b>' . $langs->trans("GeminvoiceAlternativeTerminalInstall") . '</b><br>';
    print $langs->trans("GeminvoiceAlternativeTerminalInstallHint") . '<br>';
    print '<code style="background:#eee;padding:5px;display:block;margin-top:5px;">cd ' . dol_escape_htmltag($moduledir) . ' && composer install --no-dev</code><br>';
}

print '</div>';

print '<div class="center" style="margin-top:10px;">';
if (!empty($user->rights->geminvoice->write)) {
    print '<a class="butAction" href="setup.php?action=composer_install&token=' . newToken() . '" onclick="return confirm(\'' . dol_escape_js($langs->trans("GeminvoiceConfirmComposerInstall")) . '\');">';
    print '📦 ' . $langs->trans("GeminvoiceRunComposer");
    print '</a>';
}
print '</div>';

print '<div class="info" style="margin-top:15px;">';
// --- 2c. Automatisation Cron ---
print '<b>' . $langs->trans("GeminvoiceCronInstructions") . '</b><br>';
print $langs->trans("GeminvoiceCronOption1") . '<br>';
print $langs->trans("GeminvoiceCronOption2") . '<br>';
print '<code style="background:#eee;padding:5px;display:block;margin-top:5px;">php ' . dol_escape_htmltag(dol_buildpath('/geminvoice/scripts/geminvoice_sync.php', 0)) . '</code>';
print '</div>';

// =====================================================================
// SECTION 3 — Indicateurs de connexion
// =====================================================================
print '<br>';
print load_fiche_titre($langs->trans("GeminvoiceConnectionIndicators"), '', 'title_setup');

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("Parameters") . '</td></tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans("GeminvoiceGoogleSDKInstallation") . '</td>';
print '<td>';
if ($google_client_found) {
    print '<span style="color:#27ae60;font-weight:bold;">✅ ' . $langs->trans("GeminvoiceGoogleLibsDetected") . '</span>';
} else {
    print '<span style="color:#c0392b;font-weight:bold;">❌ ' . $langs->trans("GeminvoiceGoogleLibsMissing") . '</span>';
    print ' — <span class="opacitymedium">' . $langs->trans("GeminvoiceGoogleLibsMissingHint") . '</span>';
}
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans("GeminvoiceSystemCommandsStatus") . '</td>';
print '<td>' . $shell_status . '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans("GeminvoiceGeminiAPI") . '</td>';
print '<td>';
if (!empty($conf->global->GEMINVOICE_GEMINI_API_KEY)) {
    print '<span style="color:#27ae60;font-weight:bold;">✅ ' . $langs->trans("GeminvoiceEnabled") . '</span>';
    if (!empty($conf->global->GEMINVOICE_GEMINI_MODEL)) {
        print ' — <span class="opacitymedium">' . dol_escape_htmltag($conf->global->GEMINVOICE_GEMINI_MODEL) . '</span>';
    }
} else {
    print '<span style="color:#c0392b;font-weight:bold;">❌ ' . $langs->trans("GeminvoiceDisabledPHPRestriction") . '</span>';
}
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans("GEMINVOICE_GDRIVE_FOLDER_ID") . '</td>';
print '<td>';
if (!empty($conf->global->GEMINVOICE_GDRIVE_FOLDER_ID)) {
    print '<span style="color:#27ae60;font-weight:bold;">✅ ' . $langs->trans("GeminvoiceEnabled") . '</span>';
    print ' — <span class="opacitymedium">' . dol_escape_htmltag($conf->global->GEMINVOICE_GDRIVE_FOLDER_ID) . '</span>';
} else {
    print '<span style="color:#c0392b;font-weight:bold;">❌ ' . $langs->trans("GeminvoiceDisabledPHPRestriction") . '</span>';
}
print '</td>';
print '</tr>';

print '</table>';

// =====================================================================
// SECTION 4 — Reconnaissance produit
// =====================================================================
print '<br>';
print load_fiche_titre($langs->trans("GeminvoiceRecognitionSettings"), '', 'title_setup');

$recognition_textmatch = !empty($conf->global->GEMINVOICE_RECOGNITION_TEXTMATCH);
$recognition_ai        = !empty($conf->global->GEMINVOICE_RECOGNITION_AI);
$recognition_threshold = isset($conf->global->GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD)
    ? (int) $conf->global->GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD
    : 80;
$recognition_ai_max_calls = isset($conf->global->GEMINVOICE_RECOGNITION_AI_MAX_CALLS)
    ? (int) $conf->global->GEMINVOICE_RECOGNITION_AI_MAX_CALLS
    : 3;

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="update_recognition">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("GeminvoiceRecognitionMethods") . '</td></tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans("GeminvoiceRecognitionTextMatchLabel") . '<br><span class="opacitymedium">' . $langs->trans("GeminvoiceRecognitionTextMatchDesc") . '</span></td>';
print '<td class="center" style="width:100px;"><input type="checkbox" name="GEMINVOICE_RECOGNITION_TEXTMATCH" value="1"' . ($recognition_textmatch ? ' checked' : '') . '></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans("GeminvoiceRecognitionAILabel") . ' <span class="badge" style="background:#e67e22;color:#fff;font-size:0.7em;padding:1px 6px;border-radius:3px;">' . $langs->trans("GeminvoiceExperimental") . '</span>';
print '<br><span class="opacitymedium">' . $langs->trans("GeminvoiceRecognitionAIDesc") . '</span></td>';
print '<td class="center" style="width:100px;"><input type="checkbox" name="GEMINVOICE_RECOGNITION_AI" value="1"' . ($recognition_ai ? ' checked' : '') . '></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans("GeminvoiceRecognitionThreshold");
print '<br><span class="opacitymedium">' . $langs->trans("GeminvoiceRecognitionThresholdDesc") . '</span></td>';
print '<td class="center" style="width:100px;">';
print '<input type="number" class="flat" name="GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD" min="0" max="100" style="width:70px;" value="' . $recognition_threshold . '">';
print ' <span class="opacitymedium">/ 100</span>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans("GeminvoiceRecognitionAIMaxCalls");
print '<br><span class="opacitymedium">' . $langs->trans("GeminvoiceRecognitionAIMaxCallsDesc") . '</span></td>';
print '<td class="center" style="width:100px;">';
print '<input type="number" class="flat" name="GEMINVOICE_RECOGNITION_AI_MAX_CALLS" min="1" max="20" style="width:70px;" value="' . $recognition_ai_max_calls . '">';
print '</td>';
print '</tr>';

print '</table>';
print '<div class="center" style="margin-top:10px;">';
print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
print '</div>';
print '</form>';

// =====================================================================
// SECTION 5 — Source PDPConnectFR (facturation électronique)
// =====================================================================
print '<br>';
print load_fiche_titre($langs->trans("GeminvoiceSourcePdp"), '', 'title_setup');

if (!isModEnabled('pdpconnectfr')) {
    print '<div class="warning">';
    print $langs->trans("GeminvoicePdpRequiresPdpConnectFR");
    print '</div>';
} else {
    // Save PDP settings
    if ($action == 'update_pdp') {
        $error_pdp = 0;
        if (GETPOST('token', 'alpha') !== $_SESSION['token']) {
            $error_pdp++;
        }
        if (!$error_pdp) {
            dolibarr_set_const($db, 'GEMINVOICE_PDP_SOURCE_ENABLED', GETPOSTINT('GEMINVOICE_PDP_SOURCE_ENABLED') ? '1' : '0', 'chaine', 0, '', $conf->entity);
            setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
        }
    }

    $pdp_enabled = !empty($conf->global->GEMINVOICE_PDP_SOURCE_ENABLED);

    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="update_pdp">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td colspan="2">' . $langs->trans("GeminvoicePdpSettings") . '</td></tr>';

    print '<tr class="oddeven">';
    print '<td>' . $langs->trans("GeminvoicePdpEnableLabel") . '<br>';
    print '<span class="opacitymedium">' . $langs->trans("GeminvoicePdpEnableDesc") . '</span></td>';
    print '<td class="center" style="width:100px;"><input type="checkbox" name="GEMINVOICE_PDP_SOURCE_ENABLED" value="1"' . ($pdp_enabled ? ' checked' : '') . '></td>';
    print '</tr>';

    print '<tr class="oddeven">';
    print '<td>' . $langs->trans("GeminvoicePdpModuleStatus") . '</td>';
    print '<td class="center"><span style="color:#27ae60;font-weight:bold;">✅ PDPConnectFR ' . $langs->trans("GeminvoiceEnabled") . '</span></td>';
    print '</tr>';

    print '</table>';
    print '<div class="center" style="margin-top:10px;">';
    print '<input type="submit" class="button button-save" value="' . $langs->trans("Save") . '">';
    print '</div>';
    print '</form>';
}

llxFooter();
$db->close();
