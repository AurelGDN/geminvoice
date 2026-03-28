<?php
/**
 *  \file       mappings.php
 *  \ingroup    geminvoice
 *  \brief      Admin page for managing learned accounting code mappings
 */

$res = 0;
if (! $res && file_exists("../main.inc.php")) $res = @include '../main.inc.php';
if (! $res && file_exists("../../main.inc.php")) $res = @include '../../main.inc.php';
if (! $res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

global $langs, $user, $db, $conf;

$langs->loadLangs(array("geminvoice@geminvoice", "admin"));

if (!$user->hasRight('geminvoice', 'read')) accessforbidden();

dol_include_once('/geminvoice/class/linemap.class.php');
dol_include_once('/geminvoice/class/suppliermap.class.php');

$action  = GETPOST('action', 'aZ09');
$section = GETPOST('section', 'alpha') ?: 'line'; // 'line' or 'vendor'
$rowid   = GETPOST('rowid', 'int');

// Load product catalogue only when on the "line" tab (not needed for vendor tab)
$all_products = array();
if ($section !== 'vendor') {
    $sql_prod  = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "product";
    $sql_prod .= " WHERE entity IN (" . getEntity('product') . ")";
    $sql_prod .= " AND tobuy = 1";
    $sql_prod .= " ORDER BY ref ASC";
    $resql_prod = $db->query($sql_prod);
    if ($resql_prod) {
        while ($obj = $db->fetch_object($resql_prod)) {
            $all_products[] = $obj;
        }
    }
}

// Pagination
$page = GETPOST('page', 'int');
if (empty($page) || $page < 0) {
    $page = 0;
}
$limit = GETPOST('limit', 'int');
if (empty($limit) || $limit <= 0) {
    $limit = $conf->liste_limit;
}
$offset = ($limit > 0 && $page > 0) ? ($limit * $page) : 0;

// Search Filters
$search_keyword         = GETPOST('search_keyword', 'alphanohtml');
$search_accounting_code = GETPOST('search_accounting_code', 'alphanohtml');
$search_vat_rate        = GETPOST('search_vat_rate', 'alphanohtml');
$search_vendor_name     = GETPOST('search_vendor_name', 'alphanohtml');

/*
 * Actions
 */

// CSRF check for all state-changing actions
$write_actions = array('delete_line', 'delete_vendor', 'save_line', 'save_vendor');
if (in_array($action, $write_actions) && (GETPOST('token', 'alpha') !== currentToken())) {
    accessforbidden();
}

// Delete a line mapping
if ($action == 'delete_line' && $user->hasRight('geminvoice', 'write')) {
    $linemap = new GeminvoiceLineMap($db);
    if ($linemap->delete($rowid) > 0) {
        setEventMessages($langs->trans("GeminvoiceRuleDeleted"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error") . " : " . $linemap->error, null, 'errors');
    }
}

// Delete a vendor mapping
if ($action == 'delete_vendor' && $user->hasRight('geminvoice', 'write')) {
    $supmap = new GeminvoiceSupplierMap($db);
    if ($supmap->delete($rowid) > 0) {
        setEventMessages($langs->trans("GeminvoiceVendorMappingDeleted"), null, 'mesgs');
    } else {
        setEventMessages($langs->trans("Error") . " : " . $supmap->error, null, 'errors');
    }
}

// Save (Add or Update) a line mapping
if ($action == 'save_line' && $user->hasRight('geminvoice', 'write')) {
    $keyword         = GETPOST('keyword', 'alphanohtml');
    $accounting_code = GETPOST('accounting_code', 'alphanohtml');
    $vat_raw         = GETPOST('vat_rate', 'alphanohtml');
    $is_parafiscal   = GETPOST('is_parafiscal', 'int');
    $edit_id         = GETPOST('edit_id', 'int');
    $fk_product      = GETPOSTINT('fk_product');
    $fk_product      = ($fk_product > 0) ? $fk_product : null;
    $vat_rate        = ($vat_raw !== '' && $vat_raw !== null) ? (float) price2num($vat_raw) : null;

    if (empty($keyword) || empty($accounting_code)) {
        setEventMessages($langs->trans("GeminvoiceErrorKeywordAndCodeRequired"), null, 'errors');
    } else {
        $linemap = new GeminvoiceLineMap($db);
        if ($linemap->save($keyword, $accounting_code, $vat_rate, $is_parafiscal, $keyword, $edit_id, $fk_product) > 0) {
            setEventMessages($langs->trans("GeminvoiceRuleSaved"), null, 'mesgs');
            $action = ''; // Reset edit mode
        } else {
            setEventMessages($langs->trans("Error") . " : " . $linemap->error, null, 'errors');
        }
    }
}

// Save (Add or Update) a vendor mapping
if ($action == 'save_vendor' && $user->hasRight('geminvoice', 'write')) {
    $vendor_name     = GETPOST('vendor_name', 'alphanohtml');
    $accounting_code = GETPOST('accounting_code', 'alphanohtml');
    $label           = GETPOST('label', 'alphanohtml');
    $edit_id         = GETPOST('edit_id', 'int');

    if (empty($vendor_name) || empty($accounting_code)) {
        setEventMessages($langs->trans("GeminvoiceErrorVendorAndCodeRequired"), null, 'errors');
    } else {
        $supmap = new GeminvoiceSupplierMap($db);
        if ($supmap->save($vendor_name, $accounting_code, $label, $edit_id) > 0) {
            setEventMessages($langs->trans("GeminvoiceVendorMappingSaved"), null, 'mesgs');
            $action = ''; // Reset edit mode
        } else {
            setEventMessages($langs->trans("Error") . " : " . $supmap->error, null, 'errors');
        }
    }
}

/*
 * View
 */

llxHeader('', $langs->trans("GeminvoiceMappingsManagement"));

print load_fiche_titre($langs->trans("GeminvoiceAccountingMappingsManagement"), '<a class="butAction" href="index.php">← ' . $langs->trans("Back") . '</a>', 'technic');

// Tabs
print '<div class="tabs">';
print '<a class="' . ($section == 'line' ? 'tabactive' : 'tabunactive') . '" href="mappings.php?section=line">' . $langs->trans("GeminvoiceLineRules") . '</a>&nbsp;';
print '<a class="' . ($section == 'vendor' ? 'tabactive' : 'tabunactive') . '" href="mappings.php?section=vendor">' . $langs->trans("GeminvoiceVendorMappings") . '</a>';
print '</div><br>';

$form = new Form($db);

// ===================== SECTION : LINE MAPPINGS =====================
if ($section == 'line') {
    $linemap = new GeminvoiceLineMap($db);
    
    // Variables for edit mode
    $edit_id = 0;
    $v_kw = ''; $v_code = ''; $v_vat = ''; $v_para = 0; $v_prod = 0;

    if ($action == 'edit_line' && $rowid > 0) {
        if ($linemap->fetch($rowid) > 0) {
            $edit_id = $linemap->rowid;
            $v_kw    = $linemap->keyword;
            $v_code  = $linemap->accounting_code;
            $v_vat   = is_null($linemap->vat_rate) ? '' : $linemap->vat_rate;
            $v_para  = $linemap->is_parafiscal;
            $v_prod  = !empty($linemap->fk_product) ? (int) $linemap->fk_product : 0;
        }
    }

    print '<h3>' . $langs->trans("GeminvoiceLineRulesLong") . '</h3>';
    print '<p class="opacitymedium">' . $langs->trans("GeminvoiceLineRulesHint") . '</p>';

    // Add/Edit form manually layouted for alignment
    if ($user->hasRight('geminvoice', 'write')) {
        print '<form method="POST" action="mappings.php?section=line">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';
        print '<input type="hidden" name="action" value="save_line">';
        print '<input type="hidden" name="edit_id" value="' . (int) $edit_id . '">';
        print '<table class="border centpercent">';
        print '<tr>';
        print '<td style="width:35%"><input type="text" name="keyword" class="flat centpercent" value="'.dol_escape_htmltag($v_kw).'" placeholder="' . $langs->trans("GeminvoicePlaceholderKeyword") . '"></td>';
        print '<td style="width:150px"><input type="text" name="accounting_code" class="flat centpercent" value="'.dol_escape_htmltag($v_code).'" placeholder="' . $langs->trans("GeminvoicePlaceholderAccountLine") . '"></td>';
        print '<td style="width:100px"><input type="text" name="vat_rate" class="flat centpercent" value="'.dol_escape_htmltag($v_vat).'" placeholder="' . $langs->trans("GeminvoicePlaceholderVat") . '"></td>';
        print '<td style="width:200px"><select name="fk_product" class="flat select2-product" style="width:100%;">';
        print '<option value="">— ' . $langs->trans("None") . ' —</option>';
        foreach ($all_products as $p) {
            $sel = ((int) $p->rowid === (int) $v_prod && (int) $v_prod > 0) ? ' selected' : '';
            print '<option value="' . (int) $p->rowid . '"' . $sel . '>' . dol_escape_htmltag($p->ref . ' — ' . $p->label) . '</option>';
        }
        print '</select></td>';
        print '<td class="center" style="width:120px"><label><input type="checkbox" name="is_parafiscal" value="1" ' . ($v_para ? 'checked' : '') . '> ' . $langs->trans("GeminvoiceParafiscal") . '</label></td>';
        print '<td class="center"><input type="submit" class="butAction" value="' . ($edit_id ? $langs->trans("Modify") : "+ " . $langs->trans("Add")) . '">';
        if ($edit_id) print ' <a href="mappings.php?section=line" class="butActionSmall">' . $langs->trans("Cancel") . '</a>';
        print '</td>';
        print '</tr>';
        print '</table>';
        print '</form><br>';
    }

    $filters = array(
        'keyword' => $search_keyword,
        'accounting_code' => $search_accounting_code,
        'vat_rate' => $search_vat_rate
    );

    $total = $linemap->countAll($filters);
    $rules = $linemap->fetchAll(($limit > 0 ? $limit + 1 : 0), $offset, $filters);
    $num = is_array($rules) ? count($rules) : 0;

    $param = "&section=line&search_keyword=".urlencode($search_keyword ?? "")."&search_accounting_code=".urlencode($search_accounting_code ?? "")."&search_vat_rate=".urlencode($search_vat_rate ?? "");
    
    print '<form action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '" method="GET">';
    print '<input type="hidden" name="section" value="line">';
    print '<input type="hidden" name="page" value="'.(int)$page.'">';

    print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, '', '', '', $num, $total, 'technic', 0, '', '', $limit);

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th style="width:30%">' . $langs->trans("GeminvoiceKeyword") . '</th><th style="width:150px">' . $langs->trans("GeminvoiceAccountingCode") . '</th><th style="width:100px">' . $langs->trans("GeminvoiceVatForced") . '</th><th style="width:200px">' . $langs->trans("Product") . '</th><th class="center" style="width:120px">' . $langs->trans("GeminvoiceParafiscal") . '</th><th class="center">' . $langs->trans("Actions") . '</th>';
    print '</tr>';

    // Filters row
    print '<tr class="liste_titre_filter">';
    print '<td><input type="text" class="flat centpercent" name="search_keyword" value="'.dol_escape_htmltag($search_keyword).'"></td>';
    print '<td><input type="text" class="flat centpercent" name="search_accounting_code" value="'.dol_escape_htmltag($search_accounting_code).'"></td>';
    print '<td><input type="text" class="flat centpercent" name="search_vat_rate" value="'.dol_escape_htmltag($search_vat_rate).'"></td>';
    print '<td></td>';
    print '<td class="center"></td>';
    print '<td class="center"><input type="submit" class="button" value="'.$langs->trans("Search").'"></td>';
    print '</tr>';

    if (is_array($rules) && count($rules) > 0) {
        $i = 0;
        foreach ($rules as $r) {
            if ($limit > 0 && $i >= $limit) break;
            print '<tr class="oddeven">';
            print '<td>' . dol_escape_htmltag($r->keyword) . '</td>';
            print '<td><strong>' . dol_escape_htmltag($r->accounting_code) . '</strong></td>';
            print '<td>' . (is_null($r->vat_rate) ? '<span class="opacitymedium">Auto</span>' : price($r->vat_rate) . ' %') . '</td>';
            print '<td>';
            if (!empty($r->fk_product) && !empty($r->product_ref)) {
                print dol_escape_htmltag($r->product_ref . ' — ' . $r->product_label);
            } else {
                print '<span class="opacitymedium">—</span>';
            }
            print '</td>';
            print '<td class="center">' . ($r->is_parafiscal ? '🔵 Oui' : '—') . '</td>';
            print '<td class="center">';
            if ($user->hasRight('geminvoice', 'write')) {
                $link_param = $param . '&rowid=' . ((int) $r->rowid) . '&token=' . newToken();
                print '<a href="mappings.php?action=edit_line' . $link_param . '">' . img_edit() . '</a> &nbsp; ';
                print '<a class="butActionSmallDelete" href="mappings.php?action=delete_line' . $link_param . '" onclick="return confirm(\'' . $langs->trans("GeminvoiceConfirmDeleteRule") . '\');">' . img_delete() . '</a>';
            }
            print '</td>';
            print '</tr>';
            $i++;
        }
    } else {
        print '<tr><td colspan="6" class="opacitymedium">' . $langs->trans("GeminvoiceNoRuleFound") . '</td></tr>';
    }
    print '</table>';
    print '</form>';
}

// ===================== SECTION : VENDOR MAPPINGS =====================
if ($section == 'vendor') {
    $supmap  = new GeminvoiceSupplierMap($db);

    // Edit variables
    $edit_id = 0;
    $v_name = ''; $v_code = ''; $v_label = '';

    if ($action == 'edit_vendor' && $rowid > 0) {
        if ($supmap->fetch($rowid) > 0) {
            $edit_id = $supmap->rowid;
            $v_name  = $supmap->vendor_name;
            $v_code  = $supmap->accounting_code;
            $v_label = $supmap->label;
        }
    }

    print '<h3>' . $langs->trans("GeminvoiceVendorMappings") . '</h3>';
    print '<p class="opacitymedium">' . $langs->trans("GeminvoiceVendorMappingsHint") . '</p>';

    if ($user->hasRight('geminvoice', 'write')) {
        print '<form method="POST" action="mappings.php?section=vendor">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';
        print '<input type="hidden" name="action" value="save_vendor">';
        print '<input type="hidden" name="edit_id" value="' . (int) $edit_id . '">';
        print '<table class="border centpercent">';
        print '<tr>';
        print '<td style="width:35%"><input type="text" name="vendor_name" class="flat centpercent" value="'.dol_escape_htmltag($v_name).'" placeholder="' . $langs->trans("GeminvoiceVendorName") . '"></td>';
        print '<td style="width:150px"><input type="text" name="accounting_code" class="flat centpercent" value="'.dol_escape_htmltag($v_code).'" placeholder="' . $langs->trans("GeminvoicePlaceholderAccountVendor") . '"></td>';
        print '<td><input type="text" name="label" class="flat centpercent" value="'.dol_escape_htmltag($v_label).'" placeholder="' . $langs->trans("GeminvoicePlaceholderLabel") . '"></td>';
        print '<td class="center" style="width:150px"><input type="submit" class="butAction" value="' . ($edit_id ? $langs->trans("Modify") : "+ " . $langs->trans("Add")) . '">';
        if ($edit_id) print ' <a href="mappings.php?section=vendor" class="butActionSmall">' . $langs->trans("Cancel") . '</a>';
        print '</td>';
        print '</tr>';
        print '</table>';
        print '</form><br>';
    }

    $filters = array(
        'vendor_name' => $search_vendor_name,
        'accounting_code' => $search_accounting_code
    );

    $total   = $supmap->countAll($filters);
    $vendors = $supmap->fetchAll(($limit > 0 ? $limit + 1 : 0), $offset, $filters);
    $num = is_array($vendors) ? count($vendors) : 0;

    $param = "&section=vendor&search_vendor_name=".urlencode($search_vendor_name ?? "")."&search_accounting_code=".urlencode($search_accounting_code ?? "");
    
    print '<form action="' . dol_escape_htmltag($_SERVER["PHP_SELF"]) . '" method="GET">';
    print '<input type="hidden" name="section" value="vendor">';
    print '<input type="hidden" name="page" value="'.(int)$page.'">';

    print_barre_liste('', $page, $_SERVER["PHP_SELF"], $param, '', '', '', $num, $total, 'technic', 0, '', '', $limit);

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th style="width:35%">' . $langs->trans("GeminvoiceVendorName") . '</th><th style="width:150px">' . $langs->trans("GeminvoiceAccountingCode") . '</th><th>' . $langs->trans("Label") . '</th><th class="center" style="width:150px">' . $langs->trans("Actions") . '</th>';
    print '</tr>';

    // Filter row
    print '<tr class="liste_titre_filter">';
    print '<td><input type="text" class="flat centpercent" name="search_vendor_name" value="'.dol_escape_htmltag($search_vendor_name).'"></td>';
    print '<td><input type="text" class="flat centpercent" name="search_accounting_code" value="'.dol_escape_htmltag($search_accounting_code).'"></td>';
    print '<td></td>';
    print '<td class="center"><input type="submit" class="button" value="'.$langs->trans("Search").'"></td>';
    print '</tr>';

    if (is_array($vendors) && count($vendors) > 0) {
        $i = 0;
        foreach ($vendors as $v) {
            if ($limit > 0 && $i >= $limit) break;
            print '<tr class="oddeven">';
            print '<td>' . dol_escape_htmltag($v->vendor_name) . '</td>';
            print '<td><strong>' . dol_escape_htmltag($v->accounting_code) . '</strong></td>';
            print '<td>' . dol_escape_htmltag($v->label) . '</td>';
            print '<td class="center">';
            if ($user->hasRight('geminvoice', 'write')) {
                $link_param = $param . '&rowid=' . ((int) $v->rowid) . '&token=' . newToken();
                print '<a href="mappings.php?action=edit_vendor' . $link_param . '">' . img_edit() . '</a> &nbsp; ';
                print '<a class="butActionSmallDelete" href="mappings.php?action=delete_vendor' . $link_param . '" onclick="return confirm(\'' . $langs->trans("GeminvoiceConfirmDeleteVendorMapping") . '\');">' . img_delete() . '</a>';
            }
            print '</td>';
            print '</tr>';
            $i++;
        }
    } else {
        print '<tr><td colspan="4" class="opacitymedium">' . $langs->trans("GeminvoiceNoVendorMappingFound") . '</td></tr>';
    }
    print '</table>';
    print '</form>';
}

print '<script>';
print 'jQuery(document).ready(function() {';
print '    if (typeof jQuery.fn.select2 !== "undefined") {';
print '        jQuery(".select2-product").select2({ width: "100%", allowClear: true, placeholder: "— Aucun produit —" });';
print '    }';
print '});';
print '</script>';

llxFooter();
$db->close();
