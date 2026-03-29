<?php
/**
 *  \file       class/mapper.class.php
 *  \ingroup    geminvoice
 *  \brief      Class to map JSON data from Gemini to Dolibarr Objects
 */

require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.ligne.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';
dol_include_once('/geminvoice/class/linemap.class.php');
dol_include_once('/geminvoice/class/suppliermap.class.php');
dol_include_once('/geminvoice/class/vendormatcher.class.php');

class GeminvoiceMapper
{
    private $db;

    /**
     * @var string Last error message
     */
    public $error = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Map JSON data to create a supplier invoice in Dolibarr.
     * After creation, the invoice is validated (status = validated, final number assigned).
     *
     * @param  array  $data             JSON data array from Gemini
     * @param  string $source_filepath  Absolute path to the downloaded PDF file
     * @param  string $accounting_code  Accounting code to apply to all invoice lines (fk_code_ventilation)
     * @param  bool   $validate_invoice If true, call $inv->validate() after create() to move invoice to validated state
     * @return int                      Invoice ID on success, <0 on error
     */
    public function createSupplierInvoice($data, $source_filepath, $accounting_code = '', $validate_invoice = false)
    {
        global $user, $conf, $langs;

        $error = 0;
        $this->db->begin();

        try {
            // 1. Find or Create Vendor (ThirdParty) — fuzzy matching via VendorMatcher
            $vendor_name = !empty($data['vendor_name']) ? $data['vendor_name'] : 'Fournisseur Inconnu';
            $soc = new Societe($this->db);

            // A17: use fk_soc pre-resolved in review.php if available
            $fk_soc_override = !empty($data['fk_soc']) ? (int) $data['fk_soc'] : 0;

            if ($fk_soc_override > 0) {
                $soc->fetch($fk_soc_override);
                dol_syslog("Geminvoice: Using pre-resolved fk_soc=" . $fk_soc_override . " for " . $vendor_name, LOG_DEBUG);
            }

            if ($soc->id <= 0) {
                $matcher = new GeminvoiceVendorMatcher($this->db);
                $match   = $matcher->findMatch($vendor_name);

                if ($match) {
                    $soc->fetch($match['rowid']);
                    dol_syslog("Geminvoice: Vendor matched '" . $vendor_name . "' → '" . $match['name'] . "' (score=" . $match['score'] . ", method=" . $match['method'] . ")", LOG_INFO);
                }
            }

            if ($soc->id <= 0) {
                dol_syslog("Geminvoice: No vendor match for '" . $vendor_name . "', creating new Tiers.", LOG_DEBUG);
                $soc->name       = $vendor_name;
                $soc->fournisseur = 1;
                $soc->client     = 0;
                $soc->code_fournisseur = -1; // Auto-generate via Dolibarr numbering module
                $soc->code_client      = -1;
                // Enrich from OCR data if available
                if (!empty($data['vendor_siret']))     $soc->idprof2    = $data['vendor_siret'];
                if (!empty($data['vendor_vat']))       $soc->tva_intra  = $data['vendor_vat'];
                if (!empty($data['vendor_address']))   $soc->address    = $data['vendor_address'];
                if (!empty($data['vendor_zip']))       $soc->zip        = $data['vendor_zip'];
                if (!empty($data['vendor_city']))      $soc->town       = $data['vendor_city'];
                $socid = $soc->create($user);
                if ($socid <= 0) {
                    $error++;
                    $this->error = "Failed to create vendor: " . $soc->error;
                    dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
                } else {
                    $soc->id = $socid;
                }
            }

            if (!$error) {
                // 2. Create Supplier Invoice (or Credit Note)
                $inv = new FactureFournisseur($this->db);
                $inv->socid = $soc->id;

                $is_credit_note = !empty($data['is_credit_note']);
                $inv->type = $is_credit_note ? FactureFournisseur::TYPE_CREDIT_NOTE : FactureFournisseur::TYPE_STANDARD;

                $ts_invoice   = !empty($data['date']) ? strtotime($data['date']) : false;
                $invoice_date = ($ts_invoice !== false && $ts_invoice > 0) ? $ts_invoice : dol_now();
                $inv->date = $invoice_date;

                $inv->ref_supplier = !empty($data['invoice_number']) ? $data['invoice_number'] : "OCR-Geminvoice";
                $inv->note_public  = $is_credit_note
                    ? "Avoir fournisseur importé par Geminvoice OCR (Gemini AI)"
                    : "Generated by Geminvoice OCR (Gemini AI)";

                $invoice_id = $inv->create($user);
                if ($invoice_id <= 0) {
                    $error++;
                    $this->error = "Failed to create invoice: " . $inv->error;
                    dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
                }
            }

            if (!$error && !empty($data['lines']) && is_array($data['lines'])) {
                // 3. Add Invoice Lines with 3-level accounting code resolution
                $linemap   = new GeminvoiceLineMap($this->db);
                $supmap    = new GeminvoiceSupplierMap($this->db);
                $vendor_fallback_code = !empty($accounting_code) ? $accounting_code : $supmap->findByVendor($vendor_name);

                foreach ($data['lines'] as $line) {
                    $desc   = !empty($line['description'])   ? $line['description']                   : 'Ligne importée';
                    $pu_ht  = isset($line['unit_price_ht'])  ? price2num($line['unit_price_ht'], 'MU') : 0;
                    $qty    = isset($line['qty'])             ? price2num($line['qty'], 'MS')           : 1;
                    $tva_tx = isset($line['vat_rate'])        ? price2num($line['vat_rate'])            : 20;
                    // For credit notes, ensure amounts are positive (type=2 handles the sign in Dolibarr)
                    if ($is_credit_note) {
                        $pu_ht = abs((float) $pu_ht);
                        $qty   = abs((float) $qty);
                    }

                    // -- Resolution: accounting code, VAT, product --
                    // Priority 1: LineMap keyword match (learned rule — highest priority)
                    $linemap_rule    = $linemap->findByDescription($desc);
                    $fk_product_id   = 0;
                    $line_type       = 1; // default: service

                    if ($linemap_rule) {
                        $resolved_code = $linemap_rule->accounting_code;
                        if (!is_null($linemap_rule->vat_rate)) {
                            $tva_tx = price2num($linemap_rule->vat_rate);
                        }
                        if (!empty($linemap_rule->fk_product)) {
                            $fk_product_id = (int) $linemap_rule->fk_product;
                        }
                    } elseif (!empty($line['accounting_code'])) {
                        // Priority 2: Suggestion (Gemini or previous staging edit)
                        $resolved_code = $line['accounting_code'];
                    } else {
                        // Priority 3: Global vendor/param fallback
                        $resolved_code = $vendor_fallback_code;
                    }

                    // Priority 2b: fk_product from user selection in review.php (overrides linemap product if set)
                    if (empty($fk_product_id) && !empty($line['fk_product'])) {
                        $fk_product_id = (int) $line['fk_product'];
                    }

                    // If a product is linked, load its type and use its accounting code as fallback
                    if ($fk_product_id > 0) {
                        $sql_pt = "SELECT fk_product_type, accountancy_code_buy FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . $fk_product_id;
                        $r_pt = $this->db->query($sql_pt);
                        if ($r_pt && ($o_pt = $this->db->fetch_object($r_pt))) {
                            $line_type = (int) $o_pt->fk_product_type; // 0=product, 1=service
                            if (empty($resolved_code) && !empty($o_pt->accountancy_code_buy)) {
                                $resolved_code = $o_pt->accountancy_code_buy;
                            }
                        } else {
                            $fk_product_id = 0; // product not found, reset
                        }
                    }

                    // CRITICAL: Dolibarr expects the ROWID of the account, not the account number string
                    $fk_code_ventilation = $this->getAccountingAccountId($resolved_code);

                    $res = $inv->addline(
                        $desc,                  // 1  $desc
                        $pu_ht,                 // 2  $pu
                        $tva_tx,                // 3  $txtva
                        0,                      // 4  $txlocaltax1
                        0,                      // 5  $txlocaltax2
                        $qty,                   // 6  $qty
                        $fk_product_id,         // 7  $fk_product (0 if none)
                        0,                      // 8  $remise_percent
                        0,                      // 9  $date_start
                        0,                      // 10 $date_end
                        $fk_code_ventilation,   // 11 $fk_code_ventilation  ← CRITICAL
                        0,                      // 12 $info_bits
                        'HT',                   // 13 $price_base_type
                        $line_type,             // 14 $type (0=product, 1=service)
                        -1,                     // 15 $rang
                        0                       // 16 $notrigger
                    );

                    if ($res < 0) {
                        $error++;
                        $this->error = "Failed to add line '" . $desc . "': " . $inv->error;
                        dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
                        break; // Stop adding lines on first failure to prevent partial invoice
                    }
                }
            }

            // 4. Call validate() to move invoice to Validated status (numbered, accounting entries generated)
            if (!$error && $validate_invoice) {
                $result = $inv->validate($user);
                if ($result < 0) {
                    // Non-fatal: log warning but do not rollback — the invoice was still created
                    dol_syslog("Geminvoice: Invoice ID=$invoice_id created but validate() failed: " . $inv->error . ". Invoice left as Draft.", LOG_WARNING);
                } else {
                    dol_syslog("Geminvoice: Invoice ID=$invoice_id validated successfully.", LOG_INFO);
                }
            }

            // 5. Attach source document to invoice (conditional on storage mode)
            $storage_mode = getDolGlobalString('GEMINVOICE_DOC_STORAGE', 'local_copy');
            if (!$error && file_exists($source_filepath) && in_array($storage_mode, array('local_copy', 'both'))) {
                $rel_dir = 'fournisseur/facture/' . get_exdir($inv->id, 2, 0, 0, $inv, 'invoice_supplier') . dol_sanitizeFileName($inv->ref);
                $upload_dir = DOL_DATA_ROOT . '/' . $rel_dir;
                if (!dol_is_dir($upload_dir)) {
                    dol_mkdir($upload_dir);
                }
                $filename = dol_sanitizeFileName(basename($source_filepath));
                $dest_file = $upload_dir . '/' . $filename;

                $mime = mime_content_type($source_filepath);
                if (in_array($mime, array('application/pdf', 'image/jpeg', 'image/png'))) {
                    $result_copy = dol_copy($source_filepath, $dest_file, 0, 1);
                    if ($result_copy) {
                        // Register in Dolibarr ECM for native document tab visibility
                        require_once DOL_DOCUMENT_ROOT . '/ecm/class/ecmfiles.class.php';
                        $ecmfile = new EcmFiles($this->db);
                        $ecmfile->filepath = $rel_dir;
                        $ecmfile->filename = $filename;
                        $ecmfile->label = md5_file(dol_osencode($dest_file));
                        $ecmfile->fullpath_orig = $source_filepath;
                        $ecmfile->gen_or_uploaded = 'uploaded';
                        $ecmfile->description = 'Imported by Geminvoice';
                        $ecmfile->src_object_type = 'invoice_supplier';
                        $ecmfile->src_object_id = $inv->id;
                        $ecmfile->create($user);
                    } else {
                        dol_syslog("Geminvoice: Failed to copy file to " . $dest_file, LOG_WARNING);
                    }
                } else {
                    dol_syslog("Geminvoice: Invalid MIME type for document attachment.", LOG_WARNING);
                }
            }

            // Commit or Rollback
            if (!$error) {
                $this->db->commit();
                return $invoice_id;
            } else {
                $this->db->rollback();
                return -1;
            }
        } catch (Throwable $e) {
            $this->db->rollback();
            $this->error = $e->getMessage();
            dol_syslog("Geminvoice: Fatal error during invoice creation: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Enrich an existing FactureFournisseur with accounting codes from Geminvoice review.
     * Used exclusively for PDP source where the invoice already exists in Dolibarr.
     *
     * Applies the same 3-level accounting code resolution as createSupplierInvoice():
     *   Priority 1: LineMap keyword rule (learned)
     *   Priority 2: Line suggestion (from review.php edit)
     *   Priority 3: Vendor fallback code
     *
     * Updates fk_code_ventilation (and optionally fk_product) directly on existing
     * llx_facture_fourn_det rows via their _fk_facture_fourn_det rowid.
     *
     * @param  int   $fk_facture_fourn  Existing invoice rowid
     * @param  array $data              JSON data with lines[] containing accounting_code and _fk_facture_fourn_det
     * @return int                      Invoice ID on success, <0 on error
     */
    public function enrichExistingInvoice($fk_facture_fourn, $data)
    {
        global $user;

        $error = 0;
        $this->db->begin();

        try {
            // 1. Verify invoice exists and is in draft status
            $inv = new FactureFournisseur($this->db);
            $res = $inv->fetch($fk_facture_fourn);
            if ($res <= 0) {
                $this->error = "Invoice ID=" . $fk_facture_fourn . " not found.";
                $this->db->rollback();
                return -1;
            }
            if ($inv->statut != FactureFournisseur::STATUS_DRAFT) {
                $this->error = "Invoice " . $inv->ref . " is no longer in draft status (status=" . $inv->statut . "). Cannot enrich.";
                $this->db->rollback();
                return -1;
            }

            // 2. Resolve accounting codes for each line and UPDATE existing det rows
            if (!empty($data['lines']) && is_array($data['lines'])) {
                $linemap   = new GeminvoiceLineMap($this->db);
                $supmap    = new GeminvoiceSupplierMap($this->db);
                $vendor_name = !empty($data['vendor_name']) ? $data['vendor_name'] : '';
                $vendor_fallback_code = $supmap->findByVendor($vendor_name);

                foreach ($data['lines'] as $line) {
                    $det_rowid = !empty($line['_fk_facture_fourn_det']) ? (int) $line['_fk_facture_fourn_det'] : 0;
                    if ($det_rowid <= 0) {
                        dol_syslog("Geminvoice: enrichExistingInvoice — line without _fk_facture_fourn_det, skipping.", LOG_WARNING);
                        continue;
                    }

                    // Verify det row still exists and belongs to this invoice
                    $sql_check = "SELECT rowid FROM " . MAIN_DB_PREFIX . "facture_fourn_det";
                    $sql_check .= " WHERE rowid = " . $det_rowid . " AND fk_facture_fourn = " . (int) $fk_facture_fourn;
                    $res_check = $this->db->query($sql_check);
                    if (!$res_check || !$this->db->fetch_object($res_check)) {
                        dol_syslog("Geminvoice: enrichExistingInvoice — det rowid=" . $det_rowid . " not found or does not belong to invoice " . $fk_facture_fourn . ", skipping.", LOG_WARNING);
                        continue;
                    }

                    $desc = !empty($line['description']) ? $line['description'] : '';

                    // -- Same 3-level resolution as createSupplierInvoice() --
                    $linemap_rule  = $linemap->findByDescription($desc);
                    $fk_product_id = 0;

                    if ($linemap_rule) {
                        $resolved_code = $linemap_rule->accounting_code;
                        if (!empty($linemap_rule->fk_product)) {
                            $fk_product_id = (int) $linemap_rule->fk_product;
                        }
                    } elseif (!empty($line['accounting_code'])) {
                        $resolved_code = $line['accounting_code'];
                    } else {
                        $resolved_code = $vendor_fallback_code;
                    }

                    // Product override from user selection
                    if (empty($fk_product_id) && !empty($line['fk_product'])) {
                        $fk_product_id = (int) $line['fk_product'];
                    }

                    // Product accounting code fallback
                    if ($fk_product_id > 0 && empty($resolved_code)) {
                        $sql_pt = "SELECT accountancy_code_buy FROM " . MAIN_DB_PREFIX . "product WHERE rowid = " . $fk_product_id;
                        $r_pt = $this->db->query($sql_pt);
                        if ($r_pt && ($o_pt = $this->db->fetch_object($r_pt))) {
                            if (!empty($o_pt->accountancy_code_buy)) {
                                $resolved_code = $o_pt->accountancy_code_buy;
                            }
                        }
                    }

                    $fk_code_ventilation = $this->getAccountingAccountId($resolved_code);

                    // Use SupplierInvoiceLine Active Record so Dolibarr hooks/triggers fire properly
                    $line_obj = new SupplierInvoiceLine($this->db);
                    $res_fetch = $line_obj->fetch($det_rowid);
                    if ($res_fetch <= 0 || (int) $line_obj->fk_facture_fourn !== (int) $fk_facture_fourn) {
                        dol_syslog("Geminvoice: enrichExistingInvoice — det_rowid=" . $det_rowid . " fetch failed or invoice mismatch, skipping.", LOG_WARNING);
                        continue;
                    }
                    $line_obj->fk_code_ventilation = $fk_code_ventilation;
                    if ($fk_product_id > 0) {
                        $line_obj->fk_product = $fk_product_id;
                    }
                    $res_upd = $line_obj->update();
                    if ($res_upd < 0) {
                        $error++;
                        $this->error = "Failed to update line det_rowid=" . $det_rowid . ": " . $line_obj->error;
                        dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
                    } else {
                        dol_syslog("Geminvoice: enrichExistingInvoice — det_rowid=" . $det_rowid . " → fk_code_ventilation=" . $fk_code_ventilation . " (code=" . $resolved_code . ")", LOG_DEBUG);
                    }
                }
            }

            if (!$error) {
                $this->db->commit();
                dol_syslog("Geminvoice: enrichExistingInvoice — Invoice ID=" . $fk_facture_fourn . " enriched successfully.", LOG_INFO);
                return (int) $fk_facture_fourn;
            } else {
                $this->db->rollback();
                return -1;
            }
        } catch (Throwable $e) {
            $this->db->rollback();
            $this->error = $e->getMessage();
            dol_syslog("Geminvoice: Fatal error during invoice enrichment: " . $e->getMessage(), LOG_ERR);
            return -1;
        }
    }

    /**
     * Resolve the rowid of an accounting account from its number string.
     *
     * @param  string $account_number e.g. '607000'
     * @return int                    The rowid in llx_accounting_account or 0 if not found
     */
    public function getAccountingAccountId($account_number)
    {
        if (empty($account_number)) {
            return 0;
        }

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "accounting_account";
        $sql .= " WHERE account_number = '" . $this->db->escape(trim($account_number)) . "'";
        $sql .= " AND active = 1 AND entity IN (" . getEntity('accounting_account') . ")";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj) {
                return (int) $obj->rowid;
            }
        }
        return 0;
    }
}
