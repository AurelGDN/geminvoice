<?php
/**
 *  \file       review.php
 *  \ingroup    geminvoice
 *  \brief      Review and validation page for a staged supplier invoice (Alpha18)
 *              Per-line accounting code, parafiscal badge, learning memorization
 */

$res = 0;
if (! $res && file_exists("../main.inc.php")) $res = @include '../main.inc.php';
if (! $res && file_exists("../../main.inc.php")) $res = @include '../../main.inc.php';
if (! $res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

global $langs, $user, $conf, $db;

$langs->loadLangs(array("geminvoice@geminvoice", "bills", "compta"));

if (empty($user->rights->geminvoice->read)) accessforbidden();

dol_include_once('/geminvoice/class/staging.class.php');
dol_include_once('/geminvoice/class/suppliermap.class.php');
dol_include_once('/geminvoice/class/linemap.class.php');
dol_include_once('/geminvoice/class/textmatch.class.php');
dol_include_once('/geminvoice/class/geminirecognition.class.php');

$staging_id = GETPOSTINT('id');
$action     = GETPOST('action', 'aZ09');

$staging = new GeminvoiceStaging($db);
$ret     = $staging->fetch($staging_id);
if ($ret <= 0) {
    setEventMessages($langs->trans("GeminvoiceErrorRecordNotFound", $staging_id), null, 'errors');
    header("Location: index.php");
    exit;
}

/*
 * Actions
 */

// CSRF check for all state-changing actions
if (!empty($action) && in_array($action, array('save', 'validate_final', 'reject'))) {
    if (GETPOST('token', 'alpha') !== $_SESSION['token']) {
        accessforbidden();
    }
}

// -- SAVE: persist editable fields + per-line codes back to staging, and memorize ticked lines
if ($action == 'save' && !empty($user->rights->geminvoice->write)) {
    $upd = array(
        'vendor_name'    => GETPOST('vendor_name', 'alphanohtml'),
        'invoice_number' => GETPOST('invoice_number', 'alphanohtml'),
        'invoice_date'   => GETPOST('invoice_date', 'alphanohtml'),
        'total_ht'       => GETPOSTFLOAT('total_ht'),
        'total_ttc'      => GETPOSTFLOAT('total_ttc'),
        'note'           => GETPOST('note', 'restricthtml'),
    );

    $descs       = GETPOST('line_desc', 'array');
    $qtys        = GETPOST('line_qty', 'array');
    $pu_hts      = GETPOST('line_pu_ht', 'array');
    $vat_txs     = GETPOST('line_vat', 'array');
    $acc_codes   = GETPOST('line_acc', 'array');
    $parafiscals = GETPOST('line_parafiscal', 'array');
    $memorizes   = GETPOST('line_memorize', 'array'); // indices with checkbox ticked
    $fk_products = GETPOST('line_fk_product', 'array');
    $det_rowids  = GETPOST('line_det_rowid', 'array'); // PDP source: preserve det rowids

    $lines = array();
    if (is_array($descs)) {
        foreach ($descs as $k => $desc) {
            $line_entry = array(
                'description'     => $desc,
                'qty'             => price2num(isset($qtys[$k]) ? $qtys[$k] : 1, 'MS'),
                'unit_price_ht'   => price2num(isset($pu_hts[$k]) ? $pu_hts[$k] : 0, 'MU'),
                'vat_rate'        => price2num(isset($vat_txs[$k]) ? $vat_txs[$k] : 20),
                'accounting_code' => isset($acc_codes[$k]) ? trim($acc_codes[$k]) : '',
                'is_parafiscal'   => (is_array($parafiscals) && in_array((string) $k, $parafiscals, true)) ? true : false,
                'fk_product'      => (isset($fk_products[$k]) && (int) $fk_products[$k] > 0) ? (int) $fk_products[$k] : null,
            );
            // PDP source: preserve link to existing invoice line for enrichment
            if (is_array($det_rowids) && isset($det_rowids[$k]) && (int) $det_rowids[$k] > 0) {
                $line_entry['_fk_facture_fourn_det'] = (int) $det_rowids[$k];
            }
            $lines[] = $line_entry;
        }
    }

    $json = is_array($staging->json_data) ? $staging->json_data : array();
    $json['lines'] = $lines;
    // A17: persist fk_soc (confirmed vendor match) and is_credit_note toggle
    $fk_soc_post = GETPOSTINT('fk_soc');
    if ($fk_soc_post > 0) {
        $json['fk_soc'] = $fk_soc_post;
    }
    $json['is_credit_note'] = (GETPOST('is_credit_note', 'alpha') === '1');
    $upd['json_data'] = $json;

    if ($staging->update($staging_id, $upd) > 0) {
        // -- Memorize per-line rules for ticked rows (checkboxes ARE in this form)
        if (is_array($memorizes) && count($memorizes) > 0) {
            $linemap_mem = new GeminvoiceLineMap($db);
            $mem_count = 0;
            foreach ($memorizes as $k) {
                $kw   = isset($descs[$k]) ? trim($descs[$k]) : '';
                $code = isset($acc_codes[$k]) ? trim($acc_codes[$k]) : '';
                $vat  = isset($vat_txs[$k]) ? (float) price2num($vat_txs[$k]) : null;
                $para = (is_array($parafiscals) && in_array((string) $k, $parafiscals, true)) ? 1 : 0;
                if ($kw && $code) {
                    $prod_k = (isset($fk_products[$k]) && (int) $fk_products[$k] > 0) ? (int) $fk_products[$k] : null;
                    $linemap_mem->save($kw, $code, $vat, $para, '', 0, $prod_k);
                    $mem_count++;
                }
            }
            if ($mem_count > 0) {
                setEventMessages($langs->trans("GeminvoiceLineRulesMemorized", $mem_count), null, 'mesgs');
            }
        }
        setEventMessages($langs->trans("RecordSaved"), null, 'mesgs');
        $staging->fetch($staging_id);
    } else {
        setEventMessages($langs->trans("GeminvoiceErrorSaveFailed", $staging->error), null, 'errors');
    }
}

// -- VALIDATE: create Dolibarr invoice + auto-memorize line rules
if ($action == 'validate_final' && !empty($user->rights->geminvoice->write)) {
    // A15-4: Pre-validation — reject lines with qty=0 AND unit_price=0 (completely empty lines)
    $empty_line_error = false;
    foreach (($staging->json_data['lines'] ?? array()) as $li_idx => $li) {
        $li_qty = (float) price2num($li['qty'] ?? 0, 'MS');
        $li_pu  = (float) price2num($li['unit_price_ht'] ?? 0, 'MU');
        if ($li_qty == 0 && $li_pu == 0) {
            $empty_line_error = true;
            setEventMessages($langs->trans("GeminvoiceErrorEmptyLine", $li_idx + 1, $li['description'] ?? ''), null, 'errors');
        }
    }
    if ($empty_line_error) {
        // Redirect back to same page so errors are shown
        header("Location: review.php?id=" . $staging_id);
        exit;
    }

    $invoice_id = $staging->validate($staging_id, '');

    if ($invoice_id > 0) {
        // Memorize vendor → fallback code
        $memorize_vendor = GETPOSTINT('memorize_vendor');
        if ($memorize_vendor) {
            $fallback_code = GETPOST('memorize_vendor_code', 'alpha');
            if (empty($fallback_code)) {
                foreach (($staging->json_data['lines'] ?? array()) as $l) {
                    if (!empty($l['accounting_code'])) { $fallback_code = $l['accounting_code']; break; }
                }
            }
            if (!empty($fallback_code)) {
                $supmap = new GeminvoiceSupplierMap($db);
                $supmap->save($staging->vendor_name, $fallback_code, $staging->vendor_name);
            }
        }

        // Auto-memorize line rules from validated data (A13-1)
        $linemap_auto = new GeminvoiceLineMap($db);
        $auto_count = 0;
        foreach (($staging->json_data['lines'] ?? array()) as $l) {
            $kw   = trim($l['description'] ?? '');
            $code = trim($l['accounting_code'] ?? '');
            $prod = (!empty($l['fk_product']) && (int) $l['fk_product'] > 0) ? (int) $l['fk_product'] : null;
            // Only memorize lines with meaningful description and at least one piece of data
            if (mb_strlen($kw) < 3 || (empty($code) && empty($prod))) {
                continue;
            }
            $linemap_auto->save(
                $kw,
                $code,
                isset($l['vat_rate']) ? (float) price2num($l['vat_rate']) : null,
                !empty($l['is_parafiscal']) ? 1 : 0,
                '',
                0,
                $prod
            );
            $auto_count++;
        }
        if ($auto_count > 0) {
            dol_syslog("Geminvoice: auto-memorized " . $auto_count . " line rule(s) from invoice validation", LOG_DEBUG);
        }

        setEventMessages($langs->trans("GeminvoiceInvoiceCreated", $invoice_id), null, 'mesgs');
        if ($auto_count > 0) {
            setEventMessages($langs->trans("GeminvoiceAutoMemorized", $auto_count), null, 'mesgs');
        }
        header("Location: index.php");
        exit;
    } else {
        setEventMessages($langs->trans("GeminvoiceErrorValidationFailed", $staging->error), null, 'errors');
    }
}

// -- REJECT
if ($action == 'reject' && !empty($user->rights->geminvoice->write)) {
    if ($staging->reject($staging_id) > 0) {
        setEventMessages($langs->trans("GeminvoiceInvoiceRejected"), null, 'mesgs');
        header("Location: index.php");
        exit;
    } else {
        setEventMessages($langs->trans("GeminvoiceErrorRejectionFailed"), null, 'errors');
    }
}

/*
 * View — build accounting account dropdown options
 */

$supmap         = new GeminvoiceSupplierMap($db);
$saved_acc_code = $supmap->findByVendor($staging->vendor_name);

// PDP source detection — affects UI behavior (read-only fields, no preview, etc.)
$is_pdp = ($staging->source === GeminvoiceStaging::SOURCE_PDP);

// A17: fuzzy vendor matching — resolve Dolibarr Tiers for the OCR vendor name
dol_include_once('/geminvoice/class/vendormatcher.class.php');
$vendor_matcher = new GeminvoiceVendorMatcher($db);
$vendor_match   = null;
$fk_soc_current = !empty($staging->json_data['fk_soc']) ? (int) $staging->json_data['fk_soc'] : 0;
if ($fk_soc_current <= 0) {
    $vendor_match = $vendor_matcher->findMatch($staging->vendor_name);
}

// A17: credit note flag from JSON or POST
$is_credit_note = !empty($staging->json_data['is_credit_note']);

$linemap_obj = new GeminvoiceLineMap($db);

// Build indexed options string for the accounting dropdown
$all_accounts = array();
$sql_acc = "SELECT account_number, label FROM " . MAIN_DB_PREFIX . "accounting_account";
$sql_acc .= " WHERE active = 1 AND entity IN (" . getEntity('accounting_account') . ")";
$sql_acc .= " ORDER BY account_number ASC";
$resql_acc = $db->query($sql_acc);
if ($resql_acc) {
    while ($obj = $db->fetch_object($resql_acc)) {
        $all_accounts[] = array('number' => $obj->account_number, 'label' => $obj->label);
    }
}

// Load product catalogue for the product dropdown (server-side, same approach as accounts)
$all_products = array();
$sql_prod  = "SELECT rowid, ref, label, tva_tx, accountancy_code_buy";
$sql_prod .= " FROM " . MAIN_DB_PREFIX . "product";
$sql_prod .= " WHERE entity IN (" . getEntity('product') . ")";
$sql_prod .= " AND tobuy = 1";
$sql_prod .= " ORDER BY ref ASC";
$resql_prod = $db->query($sql_prod);
if ($resql_prod) {
    while ($obj = $db->fetch_object($resql_prod)) {
        $all_products[] = array(
            'rowid'           => (int) $obj->rowid,
            'ref'             => $obj->ref,
            'label'           => $obj->label,
            'tva_tx'          => (float) $obj->tva_tx,
            'accounting_code' => (string) ($obj->accountancy_code_buy ?: ''),
        );
    }
}

// Textmatch threshold (A14): score >= threshold → accepted without AI call
$tm_threshold = isset($conf->global->GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD)
    ? (int) $conf->global->GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD
    : 80;

// Init text-match engine (A13-2) if recognition enabled
$textmatch = null;
if (!empty($conf->global->GEMINVOICE_RECOGNITION_TEXTMATCH)) {
    $textmatch = new GeminvoiceTextMatch($db);
    $textmatch->setProducts($all_products);
    $textmatch->setAccounts($all_accounts);
}

// Init AI recognition engine (A14) if enabled
$gemini_recog = null;
if (!empty($conf->global->GEMINVOICE_RECOGNITION_AI)) {
    $gemini_recog = new GeminiRecognition($db);
}

// A15-2: AI call budget per page load (avoids hanging on invoices with many uncertain lines)
$ai_call_budget = isset($conf->global->GEMINVOICE_RECOGNITION_AI_MAX_CALLS)
    ? max(1, (int) $conf->global->GEMINVOICE_RECOGNITION_AI_MAX_CALLS)
    : 3;
$ai_calls_made  = 0;
$ai_budget_hit  = false;

// Build a reusable product select HTML for a given pre-selected rowid
function buildProductSelect($name, $selected_id, $all_products) {
    $html = '<select name="' . dol_escape_htmltag($name) . '" class="flat select2-product" style="width:100%;">';
    $html .= '<option value="">— Aucun —</option>';
    foreach ($all_products as $prod) {
        $sel  = ((int) $prod['rowid'] === (int) $selected_id && (int) $selected_id > 0) ? ' selected' : '';
        $text = $prod['ref'] . (!empty($prod['label']) ? ' — ' . $prod['label'] : '');
        $html .= '<option value="' . (int) $prod['rowid'] . '"'
               . $sel
               . ' data-tva="' . dol_escape_htmltag($prod['tva_tx']) . '"'
               . ' data-acc="' . dol_escape_htmltag($prod['accounting_code']) . '">'
               . dol_escape_htmltag($text)
               . '</option>';
    }
    $html .= '</select>';
    return $html;
}

// Build a reusable select HTML for a given pre-selected value
function buildAccountSelect($name, $selected, $all_accounts, $with_apply_btn = true) {
    if (empty($all_accounts)) {
        return '<input type="text" name="' . dol_escape_htmltag($name) . '" class="flat width100" value="' . dol_escape_htmltag($selected) . '" placeholder="ex: 622100">';
    }
    $width = $with_apply_btn ? '80%' : '100%';
    $html = '<select name="' . dol_escape_htmltag($name) . '" class="flat minwidth100 select2-acc" style="width:' . $width . ';">';
    $html .= '<option value="">— Compte —</option>';
    foreach ($all_accounts as $acc) {
        $sel = ($acc['number'] == $selected) ? ' selected' : '';
        $html .= '<option value="' . dol_escape_htmltag($acc['number']) . '"' . $sel . '>' . dol_escape_htmltag($acc['number'] . ' — ' . $acc['label']) . '</option>';
    }
    $html .= '</select>';
    
    if ($with_apply_btn) {
        $html .= ' <a href="#" onclick="applyAccountToAll(this); return false;" title="Appliquer ce compte à toutes les lignes" style="font-size:1.2em; text-decoration:none; vertical-align:middle; margin-left:5px;">⮟</a>';
    }
    
    return $html;
}

llxHeader('', $langs->trans("GeminvoiceReviewInvoice") . " — " . dol_escape_htmltag($staging->filename));

print load_fiche_titre($langs->trans("GeminvoiceReviewLines") . ' : ' . dol_escape_htmltag($staging->filename), '<a class="butAction" href="index.php">← ' . $langs->trans("GeminvoiceBackToList") . '</a>', 'bill');

print '<form method="POST" action="review.php?id=' . $staging_id . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

// ---- Header fields
$pdp_readonly = $is_pdp ? ' readonly style="background:#f0f0f0;"' : '';
print '<table class="border centpercent">';

// PDP source: link to existing invoice
if ($is_pdp && !empty($staging->fk_facture_fourn)) {
    print '<tr><td class="titlefield">' . $langs->trans("GeminvoiceLinkedInvoice") . '</td>';
    print '<td><a href="' . DOL_URL_ROOT . '/fourn/facture/card.php?id=' . (int) $staging->fk_facture_fourn . '" target="_blank">';
    print img_picto('', 'bill') . ' #' . (int) $staging->fk_facture_fourn . '</a>';
    print ' <span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;">PDP</span>';
    print '</td></tr>';
}

// Fournisseur row + fuzzy match badge
print '<tr><td class="titlefield">' . $langs->trans("ThirdParty") . '</td><td>';
print '<input type="text" name="vendor_name" class="flat minwidth300" value="' . dol_escape_htmltag($staging->vendor_name) . '"' . $pdp_readonly . '>';
print '<input type="hidden" name="fk_soc" id="fk_soc_hidden" value="' . (int) $fk_soc_current . '">';
if ($fk_soc_current > 0) {
    // Already confirmed
    $soc_confirmed = new Societe($db);
    $soc_confirmed->fetch($fk_soc_current);
    print ' <span style="background:#27ae60;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;" title="' . $langs->trans("GeminvoiceVendorConfirmed") . '">✅ ' . dol_escape_htmltag($soc_confirmed->name) . '</span>';
} elseif ($vendor_match) {
    $badge_color = $vendor_match['method'] === 'exact' || $vendor_match['method'] === 'normalized' ? '#27ae60' : '#e67e22';
    $badge_icon  = $vendor_match['score'] >= 90 ? '✅' : '🔍';
    print ' <span style="background:' . $badge_color . ';color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;cursor:pointer;"'
        . ' title="' . $langs->trans("GeminvoiceVendorMatchTooltip", $vendor_match['score'], $vendor_match['method']) . '"'
        . ' onclick="document.getElementById(\'fk_soc_hidden\').value=' . (int) $vendor_match['rowid'] . '; this.style.background=\'#27ae60\'; this.innerHTML=\'✅ ' . dol_escape_js($vendor_match['name']) . ' (confirmé)\';">'
        . $badge_icon . ' ' . dol_escape_htmltag($vendor_match['name']) . ' (' . $vendor_match['score'] . '%%)</span>';
    print ' <span style="font-size:0.8em;color:#7f8c8d;">' . $langs->trans("GeminvoiceVendorMatchHint") . '</span>';
} else {
    print ' <span style="background:#e74c3c;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;" title="' . $langs->trans("GeminvoiceVendorNotFound") . '">⚠️ ' . $langs->trans("GeminvoiceVendorWillBeCreated") . '</span>';
}
print '</td></tr>';

print '<tr><td>' . $langs->trans("InvoiceNumber") . '</td>';
print '<td><input type="text" name="invoice_number" class="flat minwidth200" value="' . dol_escape_htmltag($staging->invoice_number) . '"' . $pdp_readonly . '></td></tr>';
print '<tr><td>' . $langs->trans("InvoiceDate") . '</td>';
print '<td><input type="text" name="invoice_date" class="flat" value="' . dol_escape_htmltag($staging->invoice_date) . '"' . $pdp_readonly . '></td></tr>';
print '<tr><td>' . $langs->trans("TotalHT") . '</td>';
print '<td><input type="text" name="total_ht" class="flat right" value="' . price2num($staging->total_ht) . '"' . $pdp_readonly . '></td></tr>';
print '<tr><td>' . $langs->trans("TotalTTC") . '</td>';
print '<td><input type="text" name="total_ttc" class="flat right" value="' . price2num($staging->total_ttc) . '"' . $pdp_readonly . '></td></tr>';
print '<tr><td>' . $langs->trans("Note") . '</td>';
print '<td><textarea name="note" class="flat" rows="2" cols="60">' . dol_escape_htmltag($staging->note) . '</textarea></td></tr>';

// A17: credit note toggle
$cn_checked = $is_credit_note ? ' checked' : '';
print '<tr><td>' . $langs->trans("GeminvoiceIsCreditNote") . '</td>';
print '<td>';
print '<input type="checkbox" name="is_credit_note" id="chk_credit_note" value="1"' . $cn_checked . '>';
print ' <label for="chk_credit_note" style="' . ($is_credit_note ? 'font-weight:bold;color:#8e44ad;' : '') . '">' . $langs->trans("GeminvoiceIsCreditNoteLabel") . '</label>';
if ($is_credit_note) {
    print ' <span style="background:#8e44ad;color:#fff;padding:2px 8px;border-radius:10px;font-size:0.82em;margin-left:8px;">🔄 ' . $langs->trans("GeminvoiceCreditNoteDetected") . '</span>';
}
print '</td></tr>';

print '</table>';

// A15-5: Currency warning — alert if OCR detected a non-system currency
$ocr_currency = !empty($staging->json_data['currency']) ? strtoupper(trim($staging->json_data['currency'])) : '';
$sys_currency  = !empty($conf->currency) ? strtoupper($conf->currency) : 'EUR';
if ($ocr_currency && $ocr_currency !== $sys_currency) {
    print '<div class="warning" style="margin-top:8px;">'
        . '⚠️ ' . $langs->trans("GeminvoiceCurrencyMismatch", $ocr_currency, $sys_currency)
        . '</div>';
}

// ---- Lines table with per-line accounting code
print '<br><h3>' . $langs->trans("GeminvoiceInvoiceLines") . '</h3>';
print '<table class="noborder centpercent" id="lines_table">';
print '<tr class="liste_titre">';
print '<th style="min-width:300px;">' . $langs->trans("Description") . '</th>';
print '<th class="right" style="width:55px;">' . $langs->trans("Qty") . '</th>';
print '<th class="right" style="width:90px;">' . $langs->trans("UnitPriceHT") . '</th>';
print '<th class="right" style="width:70px;">' . $langs->trans("VATRate") . ' %</th>';
print '<th class="right" style="width:80px;">' . $langs->trans("TotalHT") . '</th>';
print '<th class="right" style="width:80px;">' . $langs->trans("TotalTTC") . '</th>';
print '<th style="width:220px;">' . $langs->trans("AccountingCode") . '</th>';
print '<th style="width:180px;">' . $langs->trans("Product") . ' <span style="font-weight:normal;font-size:0.75em;opacity:0.7;">(' . $langs->trans("GeminvoiceProductAutoFill") . ')</span></th>';
print '<th class="center" style="width:80px;">' . $langs->trans("GeminvoiceParafiscal") . '<br><input type="checkbox" id="toggle_parafiscal_all" title="' . $langs->trans("GeminvoiceToggleAll") . '" onchange="toggleAllParafiscal(this.checked)"></th>';
print '<th class="center" style="width:70px;">' . $langs->trans("GeminvoiceMemorize") . '<br><input type="checkbox" id="toggle_memorize_all" title="' . $langs->trans("GeminvoiceToggleAll") . '" onchange="toggleAllMemorize(this.checked)"></th>';
print '<th class="center" style="width:40px;">⊗</th>';
print '</tr>';

$lines = !empty($staging->json_data['lines']) && is_array($staging->json_data['lines']) ? $staging->json_data['lines'] : array();

foreach ($lines as $i => $line) {
    $is_para  = !empty($line['is_parafiscal']);
    $acc_code = '';
    $rule     = null;
    $source_badge = '<span title="' . $langs->trans("GeminvoiceManualEntryTooltip") . '" style="cursor:help;opacity:0.5;">✏️</span>';

    // Priority 1: LineMap matching rule (Highest priority for automatic learning)
    if (!empty($line['description'])) {
        $rule = $linemap_obj->findByDescription($line['description']);
        if ($rule) {
            $acc_code = $rule->accounting_code;
            $is_para  = (bool) $rule->is_parafiscal;
            $source_badge = '<span title="' . $langs->trans("GeminvoiceMemorizedRuleTooltip") . '" style="cursor:help;">🧠</span>';
        }
    }

    // Priority 2 & 3: Text match — collect top candidates first
    $text_product_match = null;
    $tm_top_candidates  = array();
    if ($textmatch && empty($acc_code) && empty($line['fk_product']) && !empty($line['description'])) {
        $tm_top_candidates  = $textmatch->findTopCandidates($line['description'], 5);
        $best_tm            = !empty($tm_top_candidates) ? $tm_top_candidates[0] : null;

        if ($best_tm && $best_tm->score >= $tm_threshold) {
            // P2: textmatch score above threshold — confident match, use directly
            $text_product_match = $best_tm;
            if (!empty($best_tm->accounting_code)) {
                $acc_code     = $best_tm->accounting_code;
                $source_badge = '<span title="' . dol_escape_htmltag($langs->trans("GeminvoiceTextMatchAccountTooltip", $best_tm->score . '%')) . '" style="cursor:help;">🔍</span>';
            }
        } elseif ($gemini_recog && !empty($tm_top_candidates) && !$ai_budget_hit) {
            // P3: textmatch uncertain — check staging cache first, then call AI
            $cached = $line['ai_product'] ?? null;
            $ai_result = null;

            // A15-3: validate cached rowid still exists in the current product catalogue
            $cached_rowid_valid = false;
            if (!empty($cached) && isset($cached['rowid']) && (int) $cached['rowid'] > 0) {
                foreach ($all_products as $_cp) {
                    if ((int) $_cp['rowid'] === (int) $cached['rowid']) {
                        $cached_rowid_valid = true;
                        break;
                    }
                }
                if (!$cached_rowid_valid) {
                    // Product was deleted — clear the stale cache entry
                    $stale_rowid = (int) $cached['rowid'];
                    $json_clr = is_array($staging->json_data) ? $staging->json_data : array();
                    unset($json_clr['lines'][$i]['ai_product']);
                    $staging->update($staging_id, array('json_data' => $json_clr));
                    $cached = null;
                    dol_syslog('Geminvoice: stale ai_product cache cleared for line ' . $i . ' (rowid ' . $stale_rowid . ' not found)', LOG_WARNING);
                }
            }

            if ($cached_rowid_valid
                && ($cached['description_hash'] ?? '') === md5($line['description'])
            ) {
                // Use cached AI result (does not count against budget)
                $ai_result = (object) array_merge($cached, array('from_cache' => true));
            } elseif ($ai_calls_made < $ai_call_budget) {
                // Fresh AI call — consume one budget unit
                $ai_calls_made++;
                $ai_result = $gemini_recog->suggestProduct($line['description'], $tm_top_candidates);
                if ($ai_calls_made >= $ai_call_budget) {
                    $ai_budget_hit = true;
                }
                if ($ai_result) {
                    // Persist in staging JSON for next page load
                    $ai_cache_entry = array(
                        'rowid'            => $ai_result->rowid,
                        'accounting_code'  => $ai_result->accounting_code,
                        'confidence'       => $ai_result->confidence,
                        'reason'           => $ai_result->reason,
                        'from_cache'       => false,
                        'description_hash' => md5($line['description']),
                    );
                    $json_upd = is_array($staging->json_data) ? $staging->json_data : array();
                    $json_upd['lines'][$i]['ai_product'] = $ai_cache_entry;
                    $staging->update($staging_id, array('json_data' => $json_upd));
                    $ai_result->from_cache = false;
                }
            }

            if ($ai_result) {
                // Find the full product object from all_products to populate text_product_match
                foreach ($all_products as $p) {
                    if ((int) $p['rowid'] === (int) $ai_result->rowid) {
                        $text_product_match = (object) array(
                            'rowid'           => (int) $p['rowid'],
                            'ref'             => $p['ref'],
                            'label'           => $p['label'],
                            'tva_tx'          => (float) $p['tva_tx'],
                            'accounting_code' => !empty($ai_result->accounting_code) ? $ai_result->accounting_code : $p['accounting_code'],
                            'score'           => $ai_result->confidence,
                            'from_ai'         => true,
                            'from_cache'      => (bool) $ai_result->from_cache,
                            'reason'          => $ai_result->reason,
                        );
                        break;
                    }
                }
                if ($text_product_match && !empty($text_product_match->accounting_code)) {
                    $acc_code     = $text_product_match->accounting_code;
                    $source_badge = '<span title="' . dol_escape_htmltag($langs->trans("GeminvoiceAISuggestionTooltip")) . '" style="cursor:help;">🤖</span>';
                }
            }
        }

        // P4: textmatch best-effort (score < threshold, no AI) — still better than nothing
        if (empty($acc_code) && $best_tm && !empty($best_tm->accounting_code)) {
            $text_product_match = $best_tm;
            $acc_code           = $best_tm->accounting_code;
            $source_badge       = '<span title="' . dol_escape_htmltag($langs->trans("GeminvoiceTextMatchAccountTooltip", $best_tm->score . '%')) . '" style="cursor:help;opacity:0.7;">🔍</span>';
        }
    }

    // P4b: Text match against accounting account labels (no product found)
    if ($textmatch && empty($acc_code) && !empty($line['description'])) {
        $text_account = $textmatch->findAccountingCode($line['description']);
        if ($text_account) {
            $acc_code     = $text_account->account_number;
            $source_badge = '<span title="' . dol_escape_htmltag($langs->trans("GeminvoiceTextMatchAccountTooltip", $text_account->score . '%')) . '" style="cursor:help;">🔍</span>';
        }
    }

    // P5: Stored code in JSON (suggested by Gemini OCR or previously saved by user)
    if (empty($acc_code) && !empty($line['accounting_code'])) {
        $acc_code     = $line['accounting_code'];
        $source_badge = '<span title="' . $langs->trans("GeminvoiceAISuggestionTooltip") . '" style="cursor:help;">🤖</span>';
    }

    // Priority 5: Fallback from vendor/global setting
    if (empty($acc_code)) {
        $acc_code = $saved_acc_code ?: '';
        if ($acc_code) {
            $source_badge = '<span title="' . $langs->trans("GeminvoiceSupplierDefaultTooltip") . '" style="cursor:help;">🏢</span>';
        }
    }

    // Determine pre-selected product + badge source
    $fk_product_line = 0;
    $product_badge = '';
    if (!empty($rule) && !empty($rule->fk_product)) {
        $fk_product_line = (int) $rule->fk_product;
        $product_badge = '<span style="background:#9b59b6;color:#fff;padding:1px 5px;border-radius:3px;font-size:0.75em;cursor:help;" title="' . $langs->trans("GeminvoiceProductBadgeMemorized") . '">🧠</span> ';
    } elseif (!empty($line['fk_product'])) {
        $fk_product_line = (int) $line['fk_product'];
        $product_badge = '<span style="background:#95a5a6;color:#fff;padding:1px 5px;border-radius:3px;font-size:0.75em;cursor:help;" title="' . $langs->trans("GeminvoiceProductBadgeManual") . '">✏️</span> ';
    } elseif (!empty($text_product_match)) {
        $fk_product_line = (int) $text_product_match->rowid;
        if (!empty($text_product_match->from_ai)) {
            $cache_indicator = !empty($text_product_match->from_cache) ? ' 💾' : '';
            $ai_title = dol_escape_htmltag($langs->trans("GeminvoiceProductBadgeAI", $text_product_match->score) . ($text_product_match->reason ? ' — ' . $text_product_match->reason : ''));
            $product_badge = '<span style="background:#8e44ad;color:#fff;padding:1px 5px;border-radius:3px;font-size:0.75em;cursor:help;" title="' . $ai_title . '">🤖 ' . $text_product_match->score . '%' . $cache_indicator . '</span> ';
        } else {
            $product_badge = '<span style="background:#e67e22;color:#fff;padding:1px 5px;border-radius:3px;font-size:0.75em;cursor:help;" title="' . dol_escape_htmltag($langs->trans("GeminvoiceProductBadgeTextMatch", $text_product_match->score)) . '">🔍 ' . $text_product_match->score . '%</span> ';
        }
    }

    $prod_select_html = buildProductSelect('line_fk_product[]', $fk_product_line, $all_products);

    $row_class = $is_para ? 'oddeven' : 'oddeven';
    $para_badge = $is_para ? ' <span style="background:#3498db;color:#fff;padding:1px 5px;border-radius:3px;font-size:0.8em;">🔵 ' . $langs->trans("GeminvoiceParafiscal") . '</span>' : '';

    // PDP source: preserve line det rowid for enrichment
    $pdp_det_hidden = '';
    if ($is_pdp && !empty($line['_fk_facture_fourn_det'])) {
        $pdp_det_hidden = '<input type="hidden" name="line_det_rowid[]" value="' . (int) $line['_fk_facture_fourn_det'] . '">';
    }
    $line_readonly = $is_pdp ? ' readonly style="background:#f0f0f0;"' : '';

    print '<tr class="' . $row_class . '">';
    print '<td>' . $pdp_det_hidden . '<input type="text" name="line_desc[]" class="flat" style="width:100%;" value="' . dol_escape_htmltag($line['description']) . '"' . $line_readonly . '>' . $para_badge . '</td>';
    print '<td><input type="text" name="line_qty[]" class="flat right width50" value="' . price2num($line['qty'], 'MS') . '"' . $line_readonly . '></td>';
    print '<td class="center"><input type="text" name="line_pu_ht[]" class="flat right width90" value="' . price2num($line['unit_price_ht'], 'MU') . '"' . $line_readonly . '></td>';
    print '<td><input type="text" name="line_vat[]" class="flat right width50" value="' . price2num($line['vat_rate']) . '"' . $line_readonly . '></td>';
    print '<td class="right"><span class="line_total_ht" style="font-weight:bold; opacity:0.8;">0.00</span></td>';
    print '<td class="right"><span class="line_total_ttc" style="font-weight:bold; opacity:0.8;">0.00</span></td>';
    print '<td class="nowrap">' . $source_badge . ' ' . buildAccountSelect('line_acc[]', $acc_code, $all_accounts) . '</td>';
    print '<td style="min-width:160px;">' . $product_badge . $prod_select_html . '</td>';
    print '<td class="center"><input type="checkbox" name="line_parafiscal[]" value="' . $i . '"' . ($is_para ? ' checked' : '') . '></td>';
    print '<td class="center"><input type="checkbox" name="line_memorize[]" value="' . $i . '" title="' . $langs->trans("GeminvoiceMemorizeLineRule") . '"></td>';
    print '<td class="center nowrap">';
    print '<a href="#" onclick="splitLine(this); return false;" title="' . $langs->trans("GeminvoiceSplitLine") . '" style="margin-right:5px;text-decoration:none;">✂️</a>';
    print '<a href="#" onclick="this.closest(\'tr\').remove(); recomputeTotals(); return false;" title="' . $langs->trans("Delete") . '">🗑️</a>';
    print '</td>';
    print '</tr>';
}

print '<tfoot>';
print '<tr class="liste_total">';
print ' <td>' . $langs->trans("GeminvoiceCalculatedTotal") . '</td>';
print ' <td colspan="3"></td>';
print ' <td class="right" id="footer_total_ht" style="font-weight:bold;">0.00</td>';
print ' <td class="right" id="footer_total_ttc" style="font-weight:bold;">0.00</td>';
print ' <td colspan="4" class="center"><span id="consistency_badge" style="padding: 2px 8px; border-radius: 4px; font-weight: bold;">-</span></td>';
print ' <td></td>';
print '</tr>';
print '</tfoot>';

print '</table>';
print '<div id="total_warning" style="display:none; margin-top:10px;" class="warning">⚠️ ' . $langs->trans("GeminvoiceWarningMismatch") . '</div>';

// A15-2: Notice if AI call budget was reached
if ($ai_budget_hit) {
    print '<div class="warning" style="margin-top:8px;">⚠️ ' . $langs->trans("GeminvoiceAIBudgetReached", $ai_call_budget) . '</div>';
}
print '<br><a class="butActionSmall" href="#" onclick="addLine();return false;">' . img_picto('', 'add') . ' ' . $langs->trans("GeminvoiceAddLine") . '</a>';

print '<div class="center" style="margin-top:20px;">';
print '<input type="submit" class="butAction" value="💾 ' . $langs->trans("GeminvoiceSaveModifications") . '">';
print '</div>';
print '</form>';

// ---- Validate section
print '<hr>';
print '<h3>' . $langs->trans("GeminvoiceFinalValidation") . '</h3>';
if ($staging->status == GeminvoiceStaging::STATUS_PENDING) {
    print '<form method="POST" action="review.php?id=' . $staging_id . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    print '<input type="hidden" name="action" value="validate_final">';

    // Pass current line data for memorization
    if (!empty($lines)) {
        foreach ($lines as $i => $line) {
            print '<input type="hidden" name="line_desc[]" value="' . dol_escape_htmltag($line['description']) . '">';
            print '<input type="hidden" name="line_acc[]" value="' . dol_escape_htmltag(!empty($line['accounting_code']) ? $line['accounting_code'] : '') . '">';
            print '<input type="hidden" name="line_vat[]" value="' . price2num($line['vat_rate']) . '">';
            if (!empty($line['is_parafiscal'])) {
                print '<input type="hidden" name="line_parafiscal[]" value="' . $i . '">';
            }
        }
    }

    print '<table class="border centpercent">';
    print '<tr><td style="width:250px;">' . $langs->trans("GeminvoiceMemorizeVendor") . '</td>';
    print '<td>';
    print '<input type="checkbox" name="memorize_vendor" value="1" id="chk_mem_vendor" ' . (empty($saved_acc_code) ? 'checked' : '') . '> ';
    print '<label for="chk_mem_vendor">' . $langs->trans("GeminvoiceMemorizeVendorDescription") . '</label><br><br>';
    
    $suggested_code = $saved_acc_code;
    if (empty($suggested_code) && !empty($lines)) {
        foreach ($lines as $l) {
            if (!empty($l['accounting_code'])) { $suggested_code = $l['accounting_code']; break; }
        }
    }
    
    print buildAccountSelect('memorize_vendor_code', $suggested_code, $all_accounts, false);
    
    print '</td></tr>';
    print '</table>';

    print '<div class="info" style="margin:10px 0;">' . $langs->trans("GeminvoiceLineCodesUsedForValidation") . '</div>';

    print '<div class="center" style="margin-top:10px;">';
    $validate_label = $is_pdp ? $langs->trans("GeminvoicePdpApplyAccounting") : $langs->trans("GeminvoiceValidateAndCreate");
    print '<input type="submit" class="butAction" value="✅ ' . $validate_label . '">';
    print '</div>';
    print '</form>';

    print '<div class="center" style="margin-top:10px;">';
    print '<a class="butActionDelete" href="review.php?id=' . $staging_id . '&action=reject&token=' . newToken() . '" onclick="return confirm(\'' . $langs->trans("GeminvoiceConfirmReject") . '\');">🗑️ ' . $langs->trans("GeminvoiceRejectInvoice") . '</a>';
    print '</div>';
} else {
    print '<div class="opacitymedium">' . $langs->trans("GeminvoiceInvoiceAlreadyProcessed", ($staging->status == GeminvoiceStaging::STATUS_VALIDATED ? $langs->trans("Validated") : $langs->trans("Rejected"))) . '</div>';
}

// ---- PDF / Document Preview
if ($is_pdp) {
    print '<hr><h3>' . $langs->trans("GeminvoiceDocumentPreview") . '</h3>';
    print '<div class="opacitymedium" style="font-size:0.9em;">';
    print '<i class="fa fa-plug paddingright" style="color:#e74c3c;"></i> ' . $langs->trans("GeminvoicePdpNoPreview");
    if (!empty($staging->fk_facture_fourn)) {
        print ' — <a href="' . DOL_URL_ROOT . '/fourn/facture/card.php?id=' . (int) $staging->fk_facture_fourn . '" target="_blank">' . $langs->trans("GeminvoicePdpViewInvoice") . '</a>';
    }
    print '</div>';
} elseif (!empty($staging->local_filepath) && file_exists($staging->local_filepath)) {
    $mime = @mime_content_type($staging->local_filepath);
    print '<hr><h3>' . $langs->trans("GeminvoiceDocumentPreview") . '</h3>';

    // Build the relative path from DOL_DATA_ROOT for document.php
    $rel_path = ltrim(str_replace(DOL_DATA_ROOT, '', $staging->local_filepath), '/');

    if ($mime === 'application/xml' || $mime === 'text/xml' || pathinfo($staging->local_filepath, PATHINFO_EXTENSION) === 'xml') {
        // Factur-X: no inline preview — show structured data summary instead
        print '<div class="info" style="font-size:0.9em;">';
        print '<b>' . dol_escape_htmltag(basename($staging->local_filepath)) . '</b><br>';
        print $langs->trans('GeminvoiceSourceFacturxHint');
        print '</div>';
    } elseif ($mime === 'application/pdf') {
        $doc_url = dol_buildpath('/document.php', 1) . '?modulepart=geminvoice&file=' . urlencode($rel_path);
        print '<a href="' . $doc_url . '" target="_blank" class="butAction">📎 ' . $langs->trans("GeminvoiceOpenPDF") . '</a>';
    } else {
        // Image
        $doc_url = dol_buildpath('/document.php', 1) . '?modulepart=geminvoice&file=' . urlencode($rel_path);
        print '<img src="' . $doc_url . '" style="max-width:100%;">';
    }
}

// ---- Hidden product select template (options only — cloned by addLine() JS)
print '<select id="product_select_template" style="display:none;" name="_tpl_product">';
print '<option value="">— Aucun —</option>';
foreach ($all_products as $_p) {
    $_text = $_p['ref'] . (!empty($_p['label']) ? ' — ' . $_p['label'] : '');
    print '<option value="' . (int) $_p['rowid'] . '"'
        . ' data-tva="' . dol_escape_htmltag($_p['tva_tx']) . '"'
        . ' data-acc="' . dol_escape_htmltag($_p['accounting_code']) . '">'
        . dol_escape_htmltag($_text)
        . '</option>';
}
print '</select>';

// ---- JS
print '
<script>
function addLine() {
    var table = document.getElementById("lines_table");
    var tbody = table.getElementsByTagName("tbody")[0] || table;
    var rowCount = tbody.querySelectorAll("tr.oddeven").length;
    var tplOptions = document.getElementById("product_select_template") ? document.getElementById("product_select_template").innerHTML : "<option value=\"\">— Aucun —</option>";
    var row = tbody.insertRow(-1);
    row.className = "oddeven";
    row.innerHTML = `
        <td><input type="text" name="line_desc[]" class="flat" style="width:100%;" value=""></td>
        <td><input type="text" name="line_qty[]" class="flat right width50" value="1"></td>
        <td class="center"><input type="text" name="line_pu_ht[]" class="flat right width90" value="0"></td>
        <td><input type="text" name="line_vat[]" class="flat right width50" value="20"></td>
        <td class="right"><span class="line_total_ht" style="font-weight:bold; opacity:0.8;">0.00</span></td>
        <td class="right"><span class="line_total_ttc" style="font-weight:bold; opacity:0.8;">0.00</span></td>
        <td class="nowrap"><span title="Saisie Manuelle : Ligne ajoutée manuellement, saisie libre requise." style="cursor:help;opacity:0.5;">✏️</span> <input type="text" name="line_acc[]" class="flat width100" value="" placeholder="ex: 622100"></td>
        <td style="min-width:160px;"><select name="line_fk_product[]" class="flat select2-product" style="width:100%;">${tplOptions}</select></td>
        <td class="center"><input type="checkbox" name="line_parafiscal[]" value="${rowCount}"></td>
        <td class="center"><input type="checkbox" name="line_memorize[]" value="${rowCount}"></td>
        <td class="center nowrap">
            <a href="#" onclick="splitLine(this); return false;" title="Diviser la ligne (Séparer le montant en deux)" style="margin-right:5px;text-decoration:none;">✂️</a>
            <a href="#" onclick="this.closest(\'tr\').remove(); recomputeTotals(); return false;" title="Supprimer">🗑️</a>
        </td>
    `;
    if (typeof jQuery !== "undefined" && typeof jQuery.fn.select2 !== "undefined") {
        jQuery(row).find(".select2-acc").select2({ width: "80%" });
        jQuery(row).find(".select2-product").select2({ width: "100%", allowClear: true, placeholder: "— Aucun produit —" });
    }
    setTimeout(recomputeTotals, 50);
}

function splitLine(btn) {
    var tr = btn.closest(\'tr\');
    
    // Destroy select2 temporarily to clone clean HTML
    if (typeof jQuery !== "undefined" && typeof jQuery.fn.select2 !== "undefined") {
        jQuery(tr).find(\'.select2-acc\').select2(\'destroy\');
        jQuery(tr).find(\'.select2-product\').select2(\'destroy\');
    }
    
    var clone = tr.cloneNode(true);
    
    var percentStr = prompt("Répartition : Quel pourcentage (1 à 99) de la valeur souhaitez-vous conserver sur la ligne d\'origine ?", "50");
    if (!percentStr) return; // User cancelled
    
    var percent = parseFloat(percentStr.replace(\',\', \'.\'));
    if (isNaN(percent) || percent <= 0 || percent >= 100) {
        alert("Pourcentage invalide. L\'opération a été annulée.");
        return;
    }
    
    var ratio = percent / 100;
    
    // Split the amount
    var puInputOrig = tr.querySelector(\'input[name="line_pu_ht[]"]\');
    var puInputClone = clone.querySelector(\'input[name="line_pu_ht[]"]\');
    
    var origVal = parseDoliNum(puInputOrig.value);
    
    // Calculate part 1 and remainder to ensure exact sum matching original value
    var part1 = parseFloat((origVal * ratio).toFixed(2));
    var part2 = parseFloat((origVal - part1).toFixed(2));
    
    puInputOrig.value = part1;
    puInputClone.value = part2;
    
    // Append percentage to descriptions for traceability
    var descOrig = tr.querySelector(\'input[name="line_desc[]"]\');
    var descClone = clone.querySelector(\'input[name="line_desc[]"]\');
    if (descOrig && descClone) {
        let text = descOrig.value;
        let percentRem = Math.round((100 - percent) * 100) / 100;
        descOrig.value = text + " (Part " + percent + "%)";
        descClone.value = text + " (Part " + percentRem + "%)";
    }
    
    // Maintain select value
    var origSelect = tr.querySelector(\'select[name="line_acc[]"]\');
    var cloneSelect = clone.querySelector(\'select[name="line_acc[]"]\');
    if (origSelect && cloneSelect) { cloneSelect.value = origSelect.value; }
    
    // Change source badge to manual on the clone to indicate it was user-created
    var badgeSpan = clone.querySelector(\'td.nowrap span[title]\');
    if (badgeSpan) {
        badgeSpan.outerHTML = \'<span title="Manuel (Divisé)" style="cursor:help;opacity:0.5;">✏️</span>\';
    }
    
    tr.parentNode.insertBefore(clone, tr.nextSibling);
    
    // Re-init select2 on both rows
    if (typeof jQuery !== "undefined" && typeof jQuery.fn.select2 !== "undefined") {
        jQuery(tr).find(\'.select2-acc\').select2({ width: "80%" });
        jQuery(clone).find(\'.select2-acc\').select2({ width: "80%" });
        jQuery(tr).find(\'.select2-product\').select2({ width: "100%", allowClear: true, placeholder: "— Aucun produit —" });
        jQuery(clone).find(\'.select2-product\').val("").trigger("change").select2({ width: "100%", allowClear: true, placeholder: "— Aucun produit —" });
    }
    recomputeTotals();
}

function applyAccountToAll(btnElement) {
    let parent = btnElement.parentElement;
    let selectElement = parent.querySelector(\'select[name="line_acc[]"]\') || parent.querySelector(\'input[name="line_acc[]"]\');
    
    if (!selectElement) return;
    
    let selectedValue = selectElement.value;
    if (!selectedValue) return; // Don\'t apply empty selection
    
    document.querySelectorAll(\'[name="line_acc[]"]\').forEach(el => {
        el.value = selectedValue;
        // If Select2 is used, notify it of the change
        if (typeof jQuery !== "undefined" && jQuery(el).hasClass("select2-acc")) {
            jQuery(el).trigger("change");
        }
    });
}

function toggleAllMemorize(checked) {
    document.querySelectorAll(\'input[name="line_memorize[]"]\').forEach(function(cb) {
        cb.checked = checked;
    });
}
function toggleAllParafiscal(checked) {
    document.querySelectorAll(\'input[name="line_parafiscal[]"]\').forEach(function(cb) {
        cb.checked = checked;
    });
}

function parseDoliNum(val) {
    if (!val) return 0;
    // Supprime les espaces (séparateurs de milliers) et remplace la virgule par un point
    var clean = val.toString().replace(/ /g, \'\').replace(/\u00A0/g, \'\').replace(\',\', \'.\');
    return parseFloat(clean) || 0;
}

function checkDiscounts() {
    document.querySelectorAll("#lines_table tr").forEach(row => {
        let descInput = row.querySelector(\'input[name="line_desc[]"]\');
        let puInput = row.querySelector(\'input[name="line_pu_ht[]"]\');
        if (!descInput || !puInput) return;
        
        let desc = descInput.value.toLowerCase();
        let pu = parseDoliNum(puInput.value);
        let puCell = puInput.parentElement;
        
        let existingWarn = puCell.querySelector(\'.discount-warning\');
        if (existingWarn) existingWarn.remove();
        
        // Keywords array
        let kw = [\'remise\', \'rabais\', \'ristourne\', \'acompte\', \'avoir\'];
        let hasKw = kw.some(word => desc.includes(word));
        
        if (hasKw && pu > 0) {
            let warn = document.createElement(\'div\');
            warn.className = \'discount-warning\';
            warn.style.marginTop = \'3px\';
            warn.innerHTML = \'<span class="badge" style="background:#e74c3c;color:white;font-size:9px;padding:2px 4px;border-radius:3px;cursor:pointer;" title="Inverser le signe (-)" onclick="this.parentElement.parentElement.querySelector(\\\'input\\\').value = (\' + pu + \' * -1); recomputeTotals();">⚠️ Inverser ?</span>\';
            puCell.appendChild(warn);
            puInput.style.borderColor = \'#e74c3c\';
        } else {
            puInput.style.borderColor = \'\';
        }
    });
}

function recomputeTotals() {
    let totalHT = 0;
    let totalTTC = 0;
    
    document.querySelectorAll("#lines_table tr").forEach(row => {
        let inputQty = row.querySelector(\'input[name="line_qty[]"]\');
        if (!inputQty) return; // Ignore les lignes sans input quantité (headers, footers)
        
        let inputPu = row.querySelector(\'input[name="line_pu_ht[]"]\');
        let inputVat = row.querySelector(\'input[name="line_vat[]"]\');
        
        // Parse sécurisé des nombres Dolibarr (espaces & virgules)
        let fQty = parseDoliNum(inputQty.value);
        let fPu  = parseDoliNum(inputPu ? inputPu.value : 0);
        let fVat = parseDoliNum(inputVat ? inputVat.value : 0);
        
        let lineHT  = fQty * fPu;
        let lineTTC = lineHT * (1 + (fVat / 100));
        
        // Update per-line UI individually
        let cellHT = row.querySelector(\'.line_total_ht\');
        if (cellHT) cellHT.innerText = lineHT.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        let cellTTC = row.querySelector(\'.line_total_ttc\');
        if (cellTTC) cellTTC.innerText = lineTTC.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        
        totalHT += lineHT;
        totalTTC += lineTTC;
    });
    
    let footerHT = document.getElementById("footer_total_ht");
    if (footerHT) footerHT.innerText = totalHT.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    let footerTTC = document.getElementById("footer_total_ttc");
    if (footerTTC) footerTTC.innerText = totalTTC.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    
    // Comparison with header
    let inputHeadHT  = document.querySelector(\'input[name="total_ht"]\');
    let inputHeadTTC = document.querySelector(\'input[name="total_ttc"]\');
    
    let headerHT  = parseDoliNum(inputHeadHT ? inputHeadHT.value : 0);
    let headerTTC = parseDoliNum(inputHeadTTC ? inputHeadTTC.value : 0);
    
    let warning = document.getElementById("total_warning");
    let badge   = document.getElementById("consistency_badge");
    
    // 0.05 tolerance for rounding issues
    let isError = (Math.abs(totalHT - headerHT) > 0.05 || Math.abs(totalTTC - headerTTC) > 0.05);
    
    if (isError) {
        if (warning) warning.style.display = "block";
        if (footerTTC) footerTTC.style.color = "red";
        if (badge) {
            badge.innerText = "ERREUR";
            badge.style.backgroundColor = "#e74c3c";
            badge.style.color = "white";
        }
    } else {
        if (warning) warning.style.display = "none";
        if (footerTTC) footerTTC.style.color = "";
        if (badge) {
            badge.innerText = "COHÉRENT (OK)";
            badge.style.backgroundColor = "#27ae60";
            badge.style.color = "white";
        }
    }
    
    // Run smart discount visual checks
    checkDiscounts();
}

// Initial calculation
document.addEventListener("DOMContentLoaded", function() {
    recomputeTotals();
    
    if (typeof jQuery !== "undefined" && typeof jQuery.fn.select2 !== "undefined") {
        // Init Select2 on accounting selects
        jQuery(".select2-acc").select2({ width: "80%" });
        // Init local Select2 on product selects (server-side loaded options)
        jQuery(".select2-product").select2({ width: "100%", allowClear: true, placeholder: "— Aucun produit —" });
    }

    // Auto-fill TVA + accounting code from data-* attributes when a product is selected
    jQuery(document).on("select2:select", ".select2-product", function(e) {
        var opt = e.params.data.element;
        var row = jQuery(this).closest("tr");
        var tva = jQuery(opt).data("tva");
        var acc = jQuery(opt).data("acc");
        if (tva !== undefined && tva !== "") {
            row.find("input[name=\'line_vat[]\']").val(tva);
        }
        if (acc) {
            var accSelect = row.find("select[name=\'line_acc[]\']");
            var accInput  = row.find("input[name=\'line_acc[]\']");
            if (accSelect.length && !accSelect.val()) {
                accSelect.val(acc).trigger("change");
            } else if (accInput.length && !accInput.val()) {
                accInput.val(acc);
            }
        }
        recomputeTotals();
    });
    
    // Event delegation for all inputs in the table
    let table = document.getElementById("lines_table");
    if (table) {
        table.addEventListener("input", function(e) {
            if (e.target.name === "line_qty[]" || e.target.name === "line_pu_ht[]" || e.target.name === "line_vat[]") {
                recomputeTotals();
            }
        });
    }
    
    // Also listen to header total changes
    let headerHT = document.querySelector(\'input[name="total_ht"]\');
    if (headerHT) headerHT.addEventListener("input", recomputeTotals);
    let headerTTC = document.querySelector(\'input[name="total_ttc"]\');
    if (headerTTC) headerTTC.addEventListener("input", recomputeTotals);
});
</script>';

llxFooter();
$db->close();
