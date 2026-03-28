<?php
/**
 *  \file       ajax/product_search.php
 *  \ingroup    geminvoice
 *  \brief      AJAX endpoint — search llx_product for Select2 autocomplete (Alpha12)
 *
 *  GET params:
 *    term   (string)  Search string matched against ref and label (min 2 chars)
 *    limit  (int)     Max results returned (default 20, max 50)
 *
 *  Response (JSON):
 *    { "results": [ { "id": <rowid>, "text": "<ref> — <label>", "tva_tx": <float>,
 *                     "accounting_code": "<accountancy_code_buy>" }, ... ] }
 *
 *  On error:
 *    { "results": [], "error": "<message>" }
 */

// Note: NOSESSION must NOT be defined here — the session is required to authenticate $user
$res = 0;
if (!$res && file_exists('../../main.inc.php'))    $res = @include '../../main.inc.php';
if (!$res && file_exists('../../../main.inc.php'))  $res = @include '../../../main.inc.php';
if (!$res) {
    header('Content-Type: application/json');
    echo json_encode(array('results' => array(), 'error' => 'Dolibarr environment not found'));
    exit(1);
}

header('Content-Type: application/json; charset=utf-8');

// --- Authentication & permission check ---
if (empty($user->id) || $user->id <= 0) {
    echo json_encode(array('results' => array(), 'error' => 'Unauthorized'));
    exit(0);
}

if (!$user->hasRight('geminvoice', 'read')) {
    echo json_encode(array('results' => array(), 'error' => 'Forbidden'));
    exit(0);
}

// --- Input validation ---
$term  = GETPOST('term',  'alphanohtml');
$limit = min(50, max(1, GETPOSTINT('limit') ?: 20));

if (mb_strlen($term) < 2) {
    echo json_encode(array('results' => array()));
    exit(0);
}

// --- Query ---
$search = $db->escape($term);

$sql  = "SELECT p.rowid, p.ref, p.label, p.tva_tx, p.accountancy_code_buy";
$sql .= " FROM " . MAIN_DB_PREFIX . "product p";
$sql .= " WHERE p.entity IN (" . getEntity('product') . ")";
$sql .= " AND (p.ref LIKE '%" . $search . "%' OR p.label LIKE '%" . $search . "%')";
$sql .= " ORDER BY";
$sql .= "   CASE WHEN p.ref LIKE '" . $search . "%' THEN 0"; // exact prefix on ref first
$sql .= "        WHEN p.label LIKE '" . $search . "%' THEN 1";
$sql .= "        ELSE 2 END,";
$sql .= "   p.ref ASC";
$sql .= " LIMIT " . (int) $limit;

$resql = $db->query($sql);
if (!$resql) {
    dol_syslog("Geminvoice: product_search AJAX query failed: " . $db->lasterror(), LOG_ERR);
    echo json_encode(array('results' => array(), 'error' => 'Database error'));
    $db->close();
    exit(0);
}

$results = array();
while ($obj = $db->fetch_object($resql)) {
    $text = dol_htmlentitiesbr_decode($obj->ref);
    if (!empty($obj->label)) {
        $text .= ' — ' . dol_htmlentitiesbr_decode($obj->label);
    }
    $results[] = array(
        'id'              => (int) $obj->rowid,
        'text'            => $text,
        'tva_tx'          => (float) $obj->tva_tx,
        'accounting_code' => (string) ($obj->accountancy_code_buy ?: ''),
    );
}

echo json_encode(array('results' => $results), JSON_UNESCAPED_UNICODE);

$db->close();
exit(0);
