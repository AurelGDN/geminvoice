<?php
/**
 *  \file       class/linemap.class.php
 *  \ingroup    geminvoice
 *  \brief      CRUD for llx_geminvoice_line_mapping
 *              Maps line description keywords to accounting codes and/or Dolibarr products.
 */

class GeminvoiceLineMap
{
    /** @var DoliDB */
    public $db;
    /** @var string */
    public $error = '';

    // Object properties (loaded via fetch)
    public $rowid;
    public $keyword;
    public $accounting_code;
    public $vat_rate;
    /** @var int|null  fk_product — rowid in llx_product, or null if not linked */
    public $fk_product;
    public $is_parafiscal;
    public $label;
    public $datec;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Find a mapping rule by matching a keyword against a line description.
     * Case-insensitive substring match. Returns the FIRST (most specific) match found.
     *
     * @param  string $description   Line description from Gemini JSON
     * @return object|null           stdClass {keyword, accounting_code, vat_rate, fk_product, is_parafiscal} or null
     */
    public function findByDescription($description)
    {
        if (empty($description)) {
            return null;
        }

        $sql = "SELECT keyword, accounting_code, vat_rate, fk_product, is_parafiscal";
        $sql .= " FROM " . MAIN_DB_PREFIX . "geminvoice_line_mapping";
        $sql .= " WHERE entity IN (" . getEntity('geminvoice') . ")";
        $sql .= " ORDER BY LENGTH(keyword) DESC"; // prefer longer/more-specific matches

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            return null;
        }

        $description_lc = mb_strtolower($description);
        while ($obj = $this->db->fetch_object($resql)) {
            if (mb_strpos($description_lc, mb_strtolower($obj->keyword)) !== false) {
                return $obj;
            }
        }
        return null;
    }

    /**
     * Create or update a keyword → accounting code / product mapping.
     *
     * @param  string      $keyword         Description keyword to memorize
     * @param  string      $accounting_code Accounting account code
     * @param  float|null  $vat_rate        If set, forces this VAT rate on matching lines
     * @param  int         $is_parafiscal   1 if this line is a parafiscal tax
     * @param  string      $label           Optional display label
     * @param  int         $rowid           Optional rowid for explicit update
     * @param  int|null    $fk_product      Optional rowid of linked llx_product
     * @return int                          1 on success, <0 on error
     */
    public function save($keyword, $accounting_code, $vat_rate = null, $is_parafiscal = 0, $label = '', $rowid = 0, $fk_product = null)
    {
        global $conf, $user;

        $now      = dol_now();
        $vat_sql  = is_null($vat_rate)   ? "NULL" : (float) price2num($vat_rate);
        $prod_sql = is_null($fk_product) ? "NULL" : (int) $fk_product;

        $this->db->begin();

        if ($rowid > 0) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "geminvoice_line_mapping SET";
            $sql .= "  keyword = '"          . $this->db->escape($keyword)          . "'";
            $sql .= ", accounting_code = '"  . $this->db->escape($accounting_code)  . "'";
            $sql .= ", vat_rate = "          . $vat_sql;
            $sql .= ", fk_product = "        . $prod_sql;
            $sql .= ", is_parafiscal = "     . ((int) $is_parafiscal);
            $sql .= ", label = '"            . $this->db->escape($label)             . "'";
            $sql .= " WHERE rowid = "        . (int) $rowid;
        } else {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "geminvoice_line_mapping";
            $sql .= " (entity, keyword, accounting_code, vat_rate, fk_product, is_parafiscal, label, fk_user_creat, datec)";
            $sql .= " VALUES (";
            $sql .= "  " . ((int) $conf->entity);
            $sql .= ", '" . $this->db->escape($keyword)         . "'";
            $sql .= ", '" . $this->db->escape($accounting_code) . "'";
            $sql .= ", "  . $vat_sql;
            $sql .= ", "  . $prod_sql;
            $sql .= ", "  . ((int) $is_parafiscal);
            $sql .= ", '" . $this->db->escape($label)           . "'";
            $sql .= ", "  . ((int) $user->id);
            $sql .= ", '" . $this->db->idate($now)              . "'";
            $sql .= ")";
            $sql .= " ON DUPLICATE KEY UPDATE";
            $sql .= "  accounting_code = '" . $this->db->escape($accounting_code) . "'";
            $sql .= ", vat_rate = "         . $vat_sql;
            $sql .= ", fk_product = "       . $prod_sql;
            $sql .= ", is_parafiscal = "    . ((int) $is_parafiscal);
            $sql .= ", label = '"           . $this->db->escape($label) . "'";
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $this->db->commit();
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            return -1;
        }
    }

    /**
     * Fetch a single line mapping by rowid.
     *
     * @param  int $rowid
     * @return int 1 on success, 0 if not found, <0 on error
     */
    public function fetch($rowid)
    {
        $sql = "SELECT rowid, keyword, accounting_code, vat_rate, fk_product, is_parafiscal, label, datec";
        $sql .= " FROM " . MAIN_DB_PREFIX . "geminvoice_line_mapping";
        $sql .= " WHERE rowid = " . (int) $rowid;

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                foreach ($obj as $key => $val) {
                    $this->$key = $val;
                }
                return 1;
            }
            return 0;
        }
        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Fetch all line mapping rules for the current entity with pagination and filtering.
     *
     * @param  int   $limit    Limit
     * @param  int   $offset   Offset
     * @param  array $filters  Optional associative array [field => value]
     * @return array|int       Array of stdClass objects, or <0 on error
     */
    public function fetchAll($limit = 0, $offset = 0, $filters = array())
    {
        $sql = "SELECT m.rowid, m.keyword, m.accounting_code, m.vat_rate, m.fk_product,";
        $sql .= " m.is_parafiscal, m.label, m.datec,";
        $sql .= " p.ref AS product_ref, p.label AS product_label";
        $sql .= " FROM " . MAIN_DB_PREFIX . "geminvoice_line_mapping m";
        $sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "product p ON p.rowid = m.fk_product";
        $sql .= " WHERE m.entity IN (" . getEntity('geminvoice') . ")";

        if (!empty($filters['keyword'])) {
            $sql .= " AND m.keyword LIKE '%" . $this->db->escape($filters['keyword']) . "%'";
        }
        if (!empty($filters['accounting_code'])) {
            $sql .= " AND m.accounting_code LIKE '%" . $this->db->escape($filters['accounting_code']) . "%'";
        }
        if (isset($filters['vat_rate']) && $filters['vat_rate'] !== '') {
            $sql .= " AND m.vat_rate = " . (float) price2num($filters['vat_rate']);
        }

        $sql .= " ORDER BY m.keyword ASC";
        if ($limit > 0)  $sql .= " LIMIT "  . (int) $limit;
        if ($offset > 0) $sql .= " OFFSET " . (int) $offset;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            dol_syslog("Geminvoice: LineMap fetchAll() failed. Error: " . $this->error, LOG_ERR);
            return -1;
        }

        $result = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $result[] = $obj;
        }
        return $result;
    }

    /**
     * Count total mapping rules for the current entity with filtering.
     *
     * @param  array $filters Optional filters
     * @return int Total count or <0 on error
     */
    public function countAll($filters = array())
    {
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "geminvoice_line_mapping";
        $sql .= " WHERE entity IN (" . getEntity('geminvoice') . ")";

        if (!empty($filters['keyword'])) {
            $sql .= " AND keyword LIKE '%" . $this->db->escape($filters['keyword']) . "%'";
        }
        if (!empty($filters['accounting_code'])) {
            $sql .= " AND accounting_code LIKE '%" . $this->db->escape($filters['accounting_code']) . "%'";
        }
        if (isset($filters['vat_rate']) && $filters['vat_rate'] !== '') {
            $sql .= " AND vat_rate = " . (float) price2num($filters['vat_rate']);
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return (int) $obj->total;
        }
        return -1;
    }

    /**
     * Delete a line mapping by rowid.
     *
     * @param  int $rowid
     * @return int 1 on success, <0 on error
     */
    public function delete($rowid)
    {
        $this->db->begin();
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "geminvoice_line_mapping WHERE rowid = " . (int) $rowid;
        $resql = $this->db->query($sql);
        if ($resql) {
            $this->db->commit();
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            dol_syslog("Geminvoice: LineMap delete() failed. Error: " . $this->error, LOG_ERR);
            return -1;
        }
    }
}
