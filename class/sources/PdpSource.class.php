<?php
/**
 *  \file       class/sources/PdpSource.class.php
 *  \ingroup    geminvoice
 *  \brief      PDPConnectFR source — imports draft supplier invoices created by PDPConnectFR
 *              for accounting code enrichment via Geminvoice review pipeline (Alpha18)
 *
 *  Unlike GDrive/Upload/FacturX sources which create NEW invoices, PdpSource works on
 *  EXISTING draft FactureFournisseur objects. Lines already have description, qty, price
 *  but lack accounting codes (fk_code_ventilation = 0). Geminvoice applies its accounting
 *  intelligence cascade (learned rules, text-match, AI, vendor fallback) then updates the
 *  existing lines via mapper.enrichExistingInvoice().
 *
 *  Discovery query: llx_facture_fourn JOIN llx_pdpconnectfr_extlinks WHERE draft status
 *  and not already in geminvoice staging.
 */

dol_include_once('/geminvoice/class/sources/GeminvoiceSourceInterface.php');
dol_include_once('/geminvoice/class/staging.class.php');

class PdpSource implements GeminvoiceSourceInterface
{
    /**
     * @var DoliDB Database handler.
     */
    private $db;

    /**
     * Constructor.
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /** {@inheritdoc} */
    public function getName(): string
    {
        return 'pdp';
    }

    /** {@inheritdoc} */
    public function getLabel(): string
    {
        global $langs;
        return $langs->trans('GeminvoiceSourcePdp');
    }

    /** {@inheritdoc} */
    public function getIcon(): string
    {
        return 'fa-plug';
    }

    /**
     * {@inheritdoc}
     * PDP source requires the PDPConnectFR module to be active.
     */
    public function isConfigured(): bool
    {
        return isModEnabled('pdpconnectfr');
    }

    /** {@inheritdoc} */
    public function isEnabled(): bool
    {
        global $conf;
        return $this->isConfigured() && !empty($conf->global->GEMINVOICE_PDP_SOURCE_ENABLED);
    }

    /**
     * Discover draft supplier invoices created by PDPConnectFR and stage them
     * for accounting code assignment via the Geminvoice review pipeline.
     *
     * {@inheritdoc}
     *
     * @return array{count: int, errors: array<string>}
     */
    public function fetchAndStage(): array
    {
        global $conf;

        $count_ok = 0;
        $errors   = array();

        if (!$this->isEnabled()) {
            return array('count' => 0, 'errors' => array('PDP source is not enabled'));
        }

        // 1. Find eligible PDP invoices: draft, linked via pdpconnectfr_extlinks, not already staged
        $eligible = $this->findEligibleInvoices();

        if (empty($eligible)) {
            return array('count' => 0, 'errors' => array());
        }

        $staging = new GeminvoiceStaging($this->db);

        foreach ($eligible as $inv) {
            // 2. Load invoice lines
            $lines = $this->loadInvoiceLines($inv->rowid);

            // 3. Build Geminvoice JSON schema
            $json_data = array(
                'vendor_name'    => $inv->vendor_name,
                'invoice_number' => $inv->ref_supplier ?: $inv->ref,
                'date'           => !empty($inv->datef) ? date('Y-m-d', $this->db->jdate($inv->datef)) : '',
                'total_ht'       => (float) $inv->total_ht,
                'total_ttc'      => (float) $inv->total_ttc,
                'currency'       => 'EUR',
                'fk_soc'         => (int) $inv->fk_soc,
                'lines'          => $lines,
                '_source_format' => 'pdp',
                '_fk_facture_fourn' => (int) $inv->rowid,
            );

            // 4. Create staging record with source='pdp' and fk_facture_fourn pre-set
            $file_token = 'pdp_' . (int) $inv->rowid;

            // Check this invoice is not already staged (safety, in addition to SQL exclusion)
            $existing = $this->findExistingStaging($inv->rowid);
            if ($existing) {
                dol_syslog("Geminvoice PdpSource: Invoice ID=" . $inv->rowid . " already staged (staging ID=" . $existing . "), skipping.", LOG_DEBUG);
                continue;
            }

            $display_name = !empty($inv->ref_supplier) ? $inv->ref_supplier : $inv->ref;

            // Wrap create + fk_facture_fourn update in a single transaction to avoid orphaned staging records
            $this->db->begin();
            $staging_id = $staging->create($file_token, $display_name, '', $json_data, GeminvoiceStaging::STATUS_PENDING, '', GeminvoiceStaging::SOURCE_PDP);

            if ($staging_id > 0) {
                // Set fk_facture_fourn immediately (links staging to existing invoice)
                $upd_res = $staging->update($staging_id, array(
                    'fk_facture_fourn' => (int) $inv->rowid,
                ));
                if ($upd_res < 0) {
                    $this->db->rollback();
                    $errors[] = $display_name . ': erreur mise à jour fk_facture_fourn (' . $staging->error . ')';
                    dol_syslog("Geminvoice PdpSource: rollback — fk_facture_fourn update failed for Invoice ID=" . $inv->rowid . ": " . $staging->error, LOG_ERR);
                } else {
                    $this->db->commit();
                    dol_syslog("Geminvoice PdpSource: OK — Invoice ID=" . $inv->rowid . " (" . $display_name . ") → Staging ID=" . $staging_id, LOG_INFO);
                    $count_ok++;
                }
            } else {
                $this->db->rollback();
                $errors[] = $display_name . ': erreur staging (' . $staging->error . ')';
                dol_syslog("Geminvoice PdpSource: rollback — erreur staging Invoice ID=" . $inv->rowid . ": " . $staging->error, LOG_ERR);
            }
        }

        return array('count' => $count_ok, 'errors' => $errors);
    }

    /**
     * Count eligible PDP invoices (draft, linked via extlinks, not already staged).
     * Used by the dashboard to display the available count.
     *
     * @return int Number of eligible invoices
     */
    public function countEligible(): int
    {
        $sql = "SELECT COUNT(DISTINCT ff.rowid) AS nb";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn ff";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "pdpconnectfr_extlinks pdp";
        $sql .= "   ON pdp.element_id = ff.rowid AND pdp.element_type = 'facture_fourn'";
        $sql .= " WHERE ff.entity IN (" . getEntity('invoice') . ")";
        $sql .= " AND ff.fk_statut = 0"; // Draft only
        $sql .= " AND ff.rowid NOT IN (";
        $sql .= "   SELECT fk_facture_fourn FROM " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= "   WHERE fk_facture_fourn IS NOT NULL AND source = '" . $this->db->escape(GeminvoiceStaging::SOURCE_PDP) . "'";
        $sql .= " )";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return $obj ? (int) $obj->nb : 0;
        }
        return 0;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Find draft supplier invoices linked via PDPConnectFR extlinks and not already staged.
     *
     * @return array Array of stdClass objects with invoice metadata
     */
    private function findEligibleInvoices(): array
    {
        $sql = "SELECT ff.rowid, ff.ref, ff.ref_supplier, ff.datef, ff.fk_soc,";
        $sql .= " s.nom AS vendor_name,";
        $sql .= " ff.total_ht, ff.total_ttc";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn ff";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "pdpconnectfr_extlinks pdp";
        $sql .= "   ON pdp.element_id = ff.rowid AND pdp.element_type = 'facture_fourn'";
        $sql .= " INNER JOIN " . MAIN_DB_PREFIX . "societe s ON s.rowid = ff.fk_soc";
        $sql .= " WHERE ff.entity IN (" . getEntity('invoice') . ")";
        $sql .= " AND ff.fk_statut = 0"; // Draft only
        $sql .= " AND ff.rowid NOT IN (";
        $sql .= "   SELECT fk_facture_fourn FROM " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= "   WHERE fk_facture_fourn IS NOT NULL AND source = '" . $this->db->escape(GeminvoiceStaging::SOURCE_PDP) . "'";
        $sql .= " )";
        $sql .= " ORDER BY ff.datef ASC";

        $results = array();
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $results[] = $obj;
            }
            $this->db->free($resql);
        } else {
            dol_syslog("Geminvoice PdpSource: SQL error in findEligibleInvoices: " . $this->db->lasterror(), LOG_ERR);
        }

        return $results;
    }

    /**
     * Load invoice lines for a given supplier invoice.
     *
     * @param  int   $fk_facture_fourn  Invoice rowid
     * @return array                    Lines in Geminvoice JSON schema format
     */
    private function loadInvoiceLines(int $fk_facture_fourn): array
    {
        $sql = "SELECT rowid, description, qty, pu_ht, tva_tx, total_ht, total_ttc, fk_code_ventilation, fk_product";
        $sql .= " FROM " . MAIN_DB_PREFIX . "facture_fourn_det";
        $sql .= " WHERE fk_facture_fourn = " . (int) $fk_facture_fourn;
        $sql .= " ORDER BY rang ASC, rowid ASC";

        $lines = array();
        $resql = $this->db->query($sql);
        if ($resql) {
            while ($obj = $this->db->fetch_object($resql)) {
                $lines[] = array(
                    'description'            => $obj->description ?: '',
                    'qty'                    => (float) $obj->qty,
                    'unit_price_ht'          => (float) $obj->pu_ht,
                    'vat_rate'               => (float) $obj->tva_tx,
                    'total_ht'               => (float) $obj->total_ht,
                    'accounting_code'        => '', // Empty: Geminvoice will fill this
                    'fk_product'             => !empty($obj->fk_product) ? (int) $obj->fk_product : null,
                    '_fk_facture_fourn_det'  => (int) $obj->rowid,
                );
            }
            $this->db->free($resql);
        }

        return $lines;
    }

    /**
     * Check if an invoice is already staged as PDP source.
     *
     * @param  int      $fk_facture_fourn  Invoice rowid
     * @return int|null                    Staging rowid if found, null otherwise
     */
    private function findExistingStaging(int $fk_facture_fourn): ?int
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "geminvoice_staging";
        $sql .= " WHERE fk_facture_fourn = " . (int) $fk_facture_fourn;
        $sql .= " AND source = '" . $this->db->escape(GeminvoiceStaging::SOURCE_PDP) . "'";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            return $obj ? (int) $obj->rowid : null;
        }
        return null;
    }
}
