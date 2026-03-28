<?php
/**
 *  \file       class/suppliermap.class.php
 *  \ingroup    geminvoice
 *  \brief      CRUD class for llx_geminvoice_supplier_mapping table
 *              Memorizes vendor_name -> accounting_code associations
 */

class GeminvoiceSupplierMap
{
    /**
     * @var DoliDB Database handler.
     */
    public $db;

    /**
     * @var string Error string
     */
    public $error = '';

    // Object properties (loaded via fetch)
    public $rowid;
    public $vendor_name;
    public $accounting_code;
    public $label;
    public $datec;

    /**
     * Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Find the accounting code mapped to a vendor name.
     *
     * @param  string $vendor_name  Vendor name (exact match)
     * @return string|null          Accounting code, or null if no mapping found
     */
    public function findByVendor($vendor_name)
    {
        global $conf;

        $sql = "SELECT accounting_code FROM " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping";
        $sql .= " WHERE LOWER(vendor_name) = LOWER('" . $this->db->escape($vendor_name) . "')";
        $sql .= " AND entity IN (" . getEntity('geminvoice') . ")";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return $obj->accounting_code;
            }
            return null;
        } else {
            $this->error = $this->db->lasterror();
            dol_syslog("Geminvoice: findByVendor() failed. Error: " . $this->error, LOG_ERR);
            return null;
        }
    }

    /**
     * Create or update a vendor → accounting code mapping.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to handle the UNIQUE constraint.
     *
     * @param  string $vendor_name      Vendor name
     * @param  string $accounting_code  Accounting account code
     * @param  string $label            Optional label for display
     * @param  int    $rowid            Optional rowid for explicit update
     * @return int                      1 on success, <0 on error
     */
    public function save($vendor_name, $accounting_code, $label = '', $rowid = 0)
    {
        global $conf, $user;

        $now = dol_now();

        $this->db->begin();

        if ($rowid > 0) {
            $sql = "UPDATE " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping SET";
            $sql .= "  vendor_name = '" . $this->db->escape($vendor_name) . "'";
            $sql .= ", accounting_code = '" . $this->db->escape($accounting_code) . "'";
            $sql .= ", label = '" . $this->db->escape($label) . "'";
            $sql .= " WHERE rowid = " . (int) $rowid;
            $sql .= " AND entity IN (" . getEntity('geminvoice') . ")";
        } else {
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping";
            $sql .= " (entity, vendor_name, accounting_code, label, fk_user_creat, datec)";
            $sql .= " VALUES (";
            $sql .= " " . ((int) $conf->entity);
            $sql .= ", '" . $this->db->escape($vendor_name) . "'";
            $sql .= ", '" . $this->db->escape($accounting_code) . "'";
            $sql .= ", '" . $this->db->escape($label) . "'";
            $sql .= ", " . ((int) $user->id);
            $sql .= ", '" . $this->db->idate($now) . "'";
            $sql .= ")";
            $sql .= " ON DUPLICATE KEY UPDATE";
            $sql .= "  accounting_code = '" . $this->db->escape($accounting_code) . "'";
            $sql .= ", label = '" . $this->db->escape($label) . "'";
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
     * Fetch a single vendor mapping by rowid.
     *
     * @param  int $rowid
     * @return int 1 on success, 0 if not found, <0 on error
     */
    public function fetch($rowid)
    {
        $sql = "SELECT rowid, vendor_name, accounting_code, label, datec";
        $sql .= " FROM " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping";
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
     * Fetch all vendor mappings for the current entity with pagination and filtering.
     *
     * @param  int   $limit    Limit
     * @param  int   $offset   Offset
     * @param  array $filters  Optional associative array [field => value]
     * @return array|int    Array of stdClass objects, or <0 on error
     */
    public function fetchAll($limit = 0, $offset = 0, $filters = array())
    {
        $sql = "SELECT rowid, vendor_name, accounting_code, label, datec FROM " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping";
        $sql .= " WHERE entity IN (" . getEntity('geminvoice') . ")";

        if (!empty($filters['vendor_name'])) {
            $sql .= " AND vendor_name LIKE '%" . $this->db->escape($filters['vendor_name']) . "%'";
        }
        if (!empty($filters['accounting_code'])) {
            $sql .= " AND accounting_code LIKE '%" . $this->db->escape($filters['accounting_code']) . "%'";
        }

        $sql .= " ORDER BY vendor_name ASC";
        if ($limit > 0) $sql .= " LIMIT " . (int) $limit;
        if ($offset > 0) $sql .= " OFFSET " . (int) $offset;

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            dol_syslog("Geminvoice: fetchAll() supplier mapping failed. Error: " . $this->error, LOG_ERR);
            return -1;
        }

        $result = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $result[] = $obj;
        }
        return $result;
    }

    /**
     * Count total vendor mappings for the current entity with filtering.
     *
     * @param  array $filters Optional filters
     * @return int Total count or <0 on error
     */
    public function countAll($filters = array())
    {
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping";
        $sql .= " WHERE entity IN (" . getEntity('geminvoice') . ")";

        if (!empty($filters['vendor_name'])) {
            $sql .= " AND vendor_name LIKE '%" . $this->db->escape($filters['vendor_name']) . "%'";
        }
        if (!empty($filters['accounting_code'])) {
            $sql .= " AND accounting_code LIKE '%" . $this->db->escape($filters['accounting_code']) . "%'";
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return (int) $obj->total;
        }
        return -1;
    }

    /**
     * Delete a mapping by rowid.
     *
     * @param  int $rowid  Row ID
     * @return int         1 on success, <0 on error
     */
    public function delete($rowid)
    {
        $this->db->begin();
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "geminvoice_supplier_mapping WHERE rowid = " . (int) $rowid;
        $resql = $this->db->query($sql);
        if ($resql) {
            $this->db->commit();
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            dol_syslog("Geminvoice: delete() supplier mapping failed. Error: " . $this->error, LOG_ERR);
            return -1;
        }
    }
}
