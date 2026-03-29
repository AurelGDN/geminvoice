<?php
/**
 *  \file       class/staging.class.php
 *  \ingroup    geminvoice
 *  \brief      CRUD class for llx_geminvoice_staging table
 */

dol_include_once('/geminvoice/class/mapper.class.php');

class GeminvoiceStaging
{
    /**
     * @var DoliDB Database handler.
     */
    public $db;

    /**
     * @var int Primary key.
     */
    public $id;

    /**
     * @var string Error string
     */
    public $error = '';

    /**
     * @var array Errors
     */
    public $errors = array();

    // Fields
    public $entity;
    public $gdrive_file_id;
    public $filename;
    public $local_filepath;
    public $vendor_name;
    public $invoice_number;
    public $invoice_date;
    public $total_ht;
    public $total_ttc;
    public $json_data;
    public $status;  // 0=pending, 1=validated, 2=rejected, -1=error
    public $fk_facture_fourn;
    public $fk_user_valid;
    public $note;
    public $error_message;
    public $duplicate_warning;
    public $source = 'gdrive';  // Source identifier: 'gdrive', 'upload', 'facturx', etc.
    public $datec;
    public $tms;

    const STATUS_PENDING   = 0;
    const STATUS_VALIDATED = 1;
    const STATUS_REJECTED  = 2;
    const STATUS_ERROR     = -1;

    // Source identifiers for llx_geminvoice_staging.source column
    const SOURCE_GDRIVE  = 'gdrive';   // Google Drive synchronisation
    const SOURCE_UPLOAD  = 'upload';   // Manual browser upload
    const SOURCE_FACTURX = 'facturx';  // Factur-X / ZUGFeRD / Peppol XML
    const SOURCE_PDP     = 'pdp';      // Imported via PDPConnectFR (e-invoicing PDP flow)

    /**
     * Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Insert a new staging record from Gemini JSON data.
     *
     * @param  string $gdrive_file_id  Google Drive file ID
     * @param  string $filename        Original filename
     * @param  string $local_filepath  Absolute local path to the downloaded file
     * @param  array  $json_data       Parsed JSON from Gemini
     * @return int                     rowid on success, <0 on error
     */
    public function create($gdrive_file_id, $filename, $local_filepath, array $json_data = array(), $status = self::STATUS_PENDING, $error_message = '', $source = 'gdrive')
    {
        global $conf, $user;

        $this->db->begin();

        $now = dol_now();
        $vendor_name    = !empty($json_data['vendor_name'])    ? $this->db->escape($json_data['vendor_name'])    : '';
        $invoice_number = !empty($json_data['invoice_number']) ? $this->db->escape($json_data['invoice_number']) : '';
        $ts_date        = !empty($json_data['date']) ? strtotime($json_data['date']) : false;
        $invoice_date   = ($ts_date !== false && $ts_date > 0) ? $this->db->idate($ts_date) : 'null';
        $total_ht       = !empty($json_data['total_ht'])       ? price2num($json_data['total_ht'], 'MT')         : 0;
        $total_ttc      = !empty($json_data['total_ttc'])      ? price2num($json_data['total_ttc'], 'MT')        : 0;
        $json_raw       = !empty($json_data) ? "'" . $this->db->escape(json_encode($json_data)) . "'" : "NULL";
        $source_clean   = $this->db->escape(empty($source) ? 'gdrive' : $source);

        // Pre-staging duplicate detection: check if this invoice already exists in Dolibarr
        $dup_warning = null;
        if (!empty($json_data['vendor_name']) && !empty($json_data['invoice_number'])) {
            $dup = $this->findDuplicate($json_data['vendor_name'], $json_data['invoice_number']);
            if ($dup) {
                $dup_warning = $dup->ref;
                dol_syslog("Geminvoice: Duplicate warning at staging: " . $json_data['vendor_name'] . " / " . $json_data['invoice_number'] . " matches Dolibarr invoice " . $dup->ref, LOG_WARNING);
            }
        }

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= " (entity, source, gdrive_file_id, filename, local_filepath, vendor_name, invoice_number,";
        $sql .= "  invoice_date, total_ht, total_ttc, json_data, status, error_message, duplicate_warning, datec)";
        $sql .= " VALUES (";
        $sql .= " " . ((int) $conf->entity);
        $sql .= ", '" . $source_clean . "'";
        $sql .= ", '" . $this->db->escape($gdrive_file_id) . "'";
        $sql .= ", '" . $this->db->escape($filename) . "'";
        $sql .= ", '" . $this->db->escape($local_filepath) . "'";
        $sql .= ", '" . $vendor_name . "'";
        $sql .= ", '" . $invoice_number . "'";
        $sql .= ", " . ($invoice_date !== 'null' ? "'" . $invoice_date . "'" : "NULL");
        $sql .= ", " . (float) $total_ht;
        $sql .= ", " . (float) $total_ttc;
        $sql .= ", " . $json_raw;
        $sql .= ", " . (int) $status;
        $sql .= ", " . ($error_message ? "'" . $this->db->escape($error_message) . "'" : "NULL");
        $sql .= ", " . ($dup_warning ? "'" . $this->db->escape($dup_warning) . "'" : "NULL");
        $sql .= ", '" . $this->db->idate($now) . "'";
        $sql .= ")";

        $resql = $this->db->query($sql);
        if ($resql) {
            $this->id     = $this->db->last_insert_id(MAIN_DB_PREFIX . 'geminvoice_staging');
            $this->source = $source;
            $this->db->commit();
            dol_syslog("Geminvoice: Staging record created ID=" . $this->id . " source=" . $source, LOG_DEBUG);
            return $this->id;
        } else {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            dol_syslog("Geminvoice: Failed to create staging record. Error: " . $this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Load a staging record by rowid.
     *
     * @param  int $rowid  Row ID
     * @return int         1 on success, <0 on error, 0 if not found
     */
    public function fetch($rowid)
    {
        $rowid = (int) $rowid;
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "geminvoice_staging WHERE rowid = " . $rowid;
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $this->id               = $obj->rowid;
                $this->entity           = $obj->entity;
                $this->gdrive_file_id   = $obj->gdrive_file_id;
                $this->filename         = $obj->filename;
                $this->local_filepath   = $obj->local_filepath;
                $this->vendor_name      = $obj->vendor_name;
                $this->invoice_number   = $obj->invoice_number;
                $this->invoice_date     = $obj->invoice_date;
                $this->total_ht         = $obj->total_ht;
                $this->total_ttc        = $obj->total_ttc;
                $this->json_data        = json_decode($obj->json_data, true);
                $this->status           = $obj->status;
                $this->source           = isset($obj->source) ? $obj->source : 'gdrive';
                $this->fk_facture_fourn = $obj->fk_facture_fourn;
                $this->fk_user_valid    = $obj->fk_user_valid;
                $this->note             = $obj->note;
                $this->error_message    = $obj->error_message;
                $this->duplicate_warning = isset($obj->duplicate_warning) ? $obj->duplicate_warning : null;
                $this->datec            = $this->db->jdate($obj->datec);
                return 1;
            }
            return 0;
        } else {
            $this->error = $this->db->lasterror();
            dol_syslog("Geminvoice: fetch() failed. Error: " . $this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Return all staging records, optionally filtered.
     *
     * @param  int   $limit    Max records to return
     * @param  int   $offset   Offset for pagination
     * @param  array $filters  Array of filters: ['status'=>..., 'vendor_name'=>..., 'invoice_number'=>...]
     * @return array|int       Array of GeminvoiceStaging objects, or <0 on error
     */
    public function fetchAll($limit = 0, $offset = 0, $filters = array())
    {
        global $conf;

        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= " WHERE entity IN (" . getEntity('geminvoice') . ")";

        // Apply filters
        if (isset($filters['status']) && $filters['status'] !== '') {
            if (is_array($filters['status'])) {
                $sql .= " AND status IN (" . implode(',', array_map('intval', $filters['status'])) . ")";
            } else {
                $sql .= " AND status = " . (int) $filters['status'];
            }
        }
        if (!empty($filters['vendor_name'])) {
            $sql .= " AND vendor_name LIKE '%" . $this->db->escape($filters['vendor_name']) . "%'";
        }
        if (!empty($filters['invoice_number'])) {
            $sql .= " AND invoice_number LIKE '%" . $this->db->escape($filters['invoice_number']) . "%'";
        }
        if (!empty($filters['filename'])) {
            $sql .= " AND filename LIKE '%" . $this->db->escape($filters['filename']) . "%'";
        }

        $sql .= " ORDER BY datec DESC";

        if ($limit > 0) {
            $sql .= " LIMIT " . ((int) $offset) . ", " . ((int) $limit);
        }

        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->error = $this->db->lasterror();
            dol_syslog("Geminvoice: fetchAll() failed. Error: " . $this->error, LOG_ERR);
            return -1;
        }

        $result = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $staging = new GeminvoiceStaging($this->db);
            $staging->id               = $obj->rowid;
            $staging->entity           = $obj->entity;
            $staging->gdrive_file_id   = $obj->gdrive_file_id;
            $staging->filename         = $obj->filename;
            $staging->local_filepath   = $obj->local_filepath;
            $staging->vendor_name      = $obj->vendor_name;
            $staging->invoice_number   = $obj->invoice_number;
            $staging->invoice_date     = $obj->invoice_date;
            $staging->total_ht         = $obj->total_ht;
            $staging->total_ttc        = $obj->total_ttc;
            $staging->json_data        = json_decode($obj->json_data, true);
            $staging->status           = $obj->status;
            $staging->source           = isset($obj->source) ? $obj->source : 'gdrive';
            $staging->fk_facture_fourn = $obj->fk_facture_fourn;
            $staging->fk_user_valid    = $obj->fk_user_valid;
            $staging->note             = $obj->note;
            $staging->error_message    = $obj->error_message;
            $staging->duplicate_warning = isset($obj->duplicate_warning) ? $obj->duplicate_warning : null;
            $staging->datec            = $this->db->jdate($obj->datec);
            $result[] = $staging;
        }
        return $result;
    }

    /**
     * Count total number of staging records, matching filters.
     *
     * @param  array $filters  Array of filters
     * @return int             Count of records, or <0 on error
     */
    public function countAll($filters = array())
    {
        global $conf;

        $sql = "SELECT COUNT(rowid) as total FROM " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= " WHERE entity IN (" . getEntity('geminvoice') . ")";
        
        // Apply filters
        if (isset($filters['status']) && $filters['status'] !== '') {
            if (is_array($filters['status'])) {
                $sql .= " AND status IN (" . implode(',', array_map('intval', $filters['status'])) . ")";
            } else {
                $sql .= " AND status = " . (int) $filters['status'];
            }
        }
        if (!empty($filters['vendor_name'])) {
            $sql .= " AND vendor_name LIKE '%" . $this->db->escape($filters['vendor_name']) . "%'";
        }
        if (!empty($filters['invoice_number'])) {
            $sql .= " AND invoice_number LIKE '%" . $this->db->escape($filters['invoice_number']) . "%'";
        }
        if (!empty($filters['filename'])) {
            $sql .= " AND filename LIKE '%" . $this->db->escape($filters['filename']) . "%'";
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return (int) $obj->total;
        }
        return -1;
    }

    /**
     * Update editable fields of a staging record.
     *
     * @param  int   $rowid           Row ID
     * @param  array $data            Associative array of fields to update
     * @return int                    1 on success, <0 on error
     */
    public function update($rowid, array $data)
    {
        $rowid = (int) $rowid;
        $sets  = array();

        $allowed = array('vendor_name', 'invoice_number', 'invoice_date', 'total_ht', 'total_ttc', 'json_data', 'status', 'source', 'note', 'error_message', 'duplicate_warning', 'fk_facture_fourn', 'fk_user_valid');

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $val = $data[$field];
            if (is_null($val)) {
                $sets[] = $field . " = NULL";
            } elseif (in_array($field, array('total_ht', 'total_ttc'))) {
                $sets[] = $field . " = " . (float) price2num($val, 'MT');
            } elseif (in_array($field, array('status', 'fk_facture_fourn', 'fk_user_valid'))) {
                $sets[] = $field . " = " . (int) $val;
            } elseif ($field === 'json_data' && is_array($val)) {
                $sets[] = $field . " = '" . $this->db->escape(json_encode($val)) . "'";
            } else {
                $sets[] = $field . " = '" . $this->db->escape($val) . "'";
            }
        }

        if (empty($sets)) {
            return 1; // Nothing to do
        }

        $this->db->begin();
        $sql = "UPDATE " . MAIN_DB_PREFIX . "geminvoice_staging SET " . implode(', ', $sets);
        $sql .= " WHERE rowid = " . $rowid;
        $sql .= " AND entity IN (" . getEntity('geminvoice') . ")";

        $resql = $this->db->query($sql);
        if ($resql) {
            $this->db->commit();
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            $this->db->rollback();
            dol_syslog("Geminvoice: update() failed. Error: " . $this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Mark a staging record as rejected.
     *
     * @param  int $rowid  Row ID
     * @return int         1 on success, <0 on error
     */
    public function reject($rowid)
    {
        global $user;
        return $this->update((int) $rowid, array(
            'status'       => self::STATUS_REJECTED,
            'fk_user_valid' => $user->id
        ));
    }

    /**
     * Validate a staging record: create and validate the Dolibarr invoice, then update status.
     *
     * @param  int    $rowid            Row ID
     * @param  string $accounting_code  Accounting code to assign to invoice lines
     * @return int                      Invoice ID on success, <0 on error
     */
    public function validate($rowid, $accounting_code = '')
    {
        global $user;

        $rowid = (int) $rowid;
        if ($this->fetch($rowid) <= 0) {
            $this->error = "Staging record not found: " . $rowid;
            return -1;
        }

        if ($this->status != self::STATUS_PENDING) {
            $this->error = "Record is not in pending status.";
            return -1;
        }

        $mapper = new GeminvoiceMapper($this->db);

        // PDP source: enrich existing invoice lines with accounting codes (no new invoice creation)
        if ($this->source === self::SOURCE_PDP && !empty($this->fk_facture_fourn)) {
            $invoice_id = $mapper->enrichExistingInvoice($this->fk_facture_fourn, $this->json_data);
        } else {
            $invoice_id = $mapper->createSupplierInvoice($this->json_data, $this->local_filepath, $accounting_code, true);
        }

        if ($invoice_id > 0) {
            $update_fields = array(
                'status'        => self::STATUS_VALIDATED,
                'fk_user_valid' => $user->id,
                'error_message' => '', // Clear any previous error
            );
            // For non-PDP sources, fk_facture_fourn is set after creation; for PDP it's already set
            if ($this->source !== self::SOURCE_PDP) {
                $update_fields['fk_facture_fourn'] = $invoice_id;
            }
            $this->update($rowid, $update_fields);
            dol_syslog("Geminvoice: Staging ID=$rowid validated → Dolibarr Invoice ID=$invoice_id", LOG_INFO);
            return $invoice_id;
        } else {
            $this->error = $mapper->error;
            $this->update($rowid, array(
                'status'        => self::STATUS_ERROR,
                'error_message' => $this->error
            ));
            dol_syslog("Geminvoice: Staging ID=$rowid validation failed. Mapper error: " . $this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Check if an invoice with the same vendor name and reference already exists in Dolibarr.
     *
     * Also checks llx_pdpconnectfr_extlinks when PDPConnectFR module is active,
     * to catch invoices already imported via an e-invoicing PDP flow.
     *
     * @param  string $vendor_name    Vendor name as extracted by Gemini
     * @param  string $invoice_number Invoice reference (ref_supplier) as extracted by Gemini
     * @return object|null            stdClass {rowid, ref, ref_supplier, url, via_pdp} if duplicate found, null otherwise
     */
    public function findDuplicate($vendor_name, $invoice_number)
    {
        if (empty($vendor_name) || empty($invoice_number)) {
            return null;
        }

        // Primary check: llx_facture_fourn (covers all sources including manual entry)
        $sql  = "SELECT ff.rowid, ff.ref, ff.ref_supplier";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn AS ff";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe AS s ON s.rowid = ff.fk_soc";
        $sql .= " WHERE ff.entity IN (" . getEntity('invoice') . ")";
        $sql .= "   AND TRIM(ff.ref_supplier) = '" . $this->db->escape(trim($invoice_number)) . "'";
        $sql .= "   AND (TRIM(s.nom) = '" . $this->db->escape(trim($vendor_name)) . "' OR s.nom_alias = '" . $this->db->escape(trim($vendor_name)) . "')";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                $obj->url    = DOL_URL_ROOT . '/fourn/facture/card.php?id=' . (int) $obj->rowid;
                $obj->via_pdp = false;
                return $obj;
            }
        }

        // Secondary check: PDPConnectFR extlinks — only when the module is active and its table exists.
        // Catches the case where the same invoice arrived via e-invoicing PDP flow before the user
        // uploads the PDF, which would be missed by the primary check if ref_supplier was not yet set.
        if (isModEnabled('pdpconnectfr')) {
            $sql  = "SELECT ff.rowid, ff.ref, ff.ref_supplier";
            $sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn AS ff";
            $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "pdpconnectfr_extlinks AS pdp ON pdp.element_id = ff.rowid";
            $sql .= "   AND pdp.element_type = 'facture_fourn'";
            $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe AS s ON s.rowid = ff.fk_soc";
            $sql .= " WHERE ff.entity IN (" . getEntity('invoice') . ")";
            $sql .= "   AND TRIM(ff.ref_supplier) = '" . $this->db->escape(trim($invoice_number)) . "'";
            $sql .= " LIMIT 1";

            $resql = $this->db->query($sql);
            if ($resql) {
                $obj = $this->db->fetch_object($resql);
                if ($obj) {
                    $obj->url    = DOL_URL_ROOT . '/fourn/facture/card.php?id=' . (int) $obj->rowid;
                    $obj->via_pdp = true;
                    return $obj;
                }
            }
        }

        return null;
    }

    /**
     * Check if a staging record with the given file identifier already exists
     * (any status except rejected). Prevents re-staging the same file on retry.
     *
     * @param  string $gdrive_file_id  The file identifier to check
     * @return int|false               Existing rowid if found, false otherwise
     */
    public function existsInStaging($gdrive_file_id)
    {
        if (empty($gdrive_file_id)) {
            return false;
        }

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= " WHERE gdrive_file_id = '" . $this->db->escape($gdrive_file_id) . "'";
        $sql .= " AND entity IN (" . getEntity('geminvoice') . ")";
        $sql .= " AND status != " . self::STATUS_REJECTED;
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return (int) $obj->rowid;
            }
        }
        return false;
    }

    /**
     * Get the Google Drive view URL for this staging record.
     *
     * @return string URL or empty string if not a Drive file
     */
    public function getDriveViewUrl()
    {
        if (!empty($this->gdrive_file_id) && !in_array($this->gdrive_file_id, array('manual_upload', 'facturx_import'))) {
            return 'https://drive.google.com/file/d/' . urlencode($this->gdrive_file_id) . '/view';
        }
        return '';
    }

    /**
     * Get the Google Drive embeddable preview URL for this staging record.
     *
     * @return string URL or empty string if not a Drive file
     */
    public function getDrivePreviewUrl()
    {
        if (!empty($this->gdrive_file_id) && !in_array($this->gdrive_file_id, array('manual_upload', 'facturx_import'))) {
            return 'https://drive.google.com/file/d/' . urlencode($this->gdrive_file_id) . '/preview';
        }
        return '';
    }

    /**
     * Purge all error records from staging.
     *
     * @return int         >0 on success, <0 on error
     */
    public function purgeErrors()
    {
        global $conf;
        $sql  = "DELETE FROM " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= " WHERE entity = " . ((int) $conf->entity);
        $sql .= " AND status = " . self::STATUS_ERROR;

        $resql = $this->db->query($sql);
        if ($resql) {
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }

    /**
     * Delete all pending and error records for the current entity.
     * Validated/rejected records are kept.
     *
     * @return int 1 on success, -1 on error
     */
    public function purgeAll()
    {
        global $conf;
        $sql  = "DELETE FROM " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= " WHERE entity = " . ((int) $conf->entity);
        $sql .= " AND status IN (" . self::STATUS_PENDING . ", " . self::STATUS_ERROR . ")";

        $resql = $this->db->query($sql);
        if ($resql) {
            return 1;
        } else {
            $this->error = $this->db->lasterror();
            return -1;
        }
    }
}
