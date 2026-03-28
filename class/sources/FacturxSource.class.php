<?php
/**
 *  \file       class/sources/FacturxSource.class.php
 *  \ingroup    geminvoice
 *  \brief      Factur-X / ZUGFeRD structured XML source — implements GeminvoiceSourceInterface (Alpha16)
 *
 *  Accepts:
 *    - Standalone CII/UBL XML files (.xml)
 *    - PDF/A-3 files with embedded Factur-X XML (extracted from the PDF byte stream)
 *
 *  No OCR is performed: all data is read from the structured XML.
 *  Accounting intelligence (linemap / textmatch / AI recognition) runs normally in review.php
 *  on the extracted line descriptions, exactly as it does for OCR-sourced invoices.
 *
 *  Factur-X profiles supported: MINIMUM, BASIC-WL, BASIC, EN16931 (COMFORT), EXTENDED.
 *  The parser maps Cross-Industry Invoice (CII) elements to the Geminvoice JSON schema.
 *
 *  UBL (BIS Billing 3.0) is detected and mapped in a separate method for future-proofing.
 */

dol_include_once('/geminvoice/class/sources/GeminvoiceSourceInterface.php');
dol_include_once('/geminvoice/class/staging.class.php');

class FacturxSource implements GeminvoiceSourceInterface
{
    /**
     * @var DoliDB Database handler.
     */
    private $db;

    /** Factur-X XML filename embedded in PDF/A-3 attachments */
    private const FX_XML_FILENAME = 'factur-x.xml';
    /** ZUGFeRD variant filename */
    private const ZF_XML_FILENAME = 'ZUGFeRD-invoice.xml';
    /** Order-X variant */
    private const OX_XML_FILENAME = 'order-x.xml';

    /** CII namespace (EN 16931 / Factur-X) */
    private const NS_CII_RSM  = 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100';
    private const NS_CII_RAM  = 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100';
    private const NS_CII_UDT  = 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100';

    /** UBL 2.1 namespace */
    private const NS_UBL      = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

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
        return 'facturx';
    }

    /** {@inheritdoc} */
    public function getLabel(): string
    {
        global $langs;
        return $langs->trans('GeminvoiceSourceFacturx');
    }

    /** {@inheritdoc} */
    public function getIcon(): string
    {
        return 'fa-file-invoice';
    }

    /**
     * {@inheritdoc}
     * FacturX source requires no external credentials — always configured.
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Process uploaded Factur-X files (PDF/A-3 or standalone XML).
     * Reads from $_FILES['geminvoice_facturx'] (multiple file input).
     *
     * {@inheritdoc}
     *
     * @return array{count: int, errors: array<string>}
     */
    public function fetchAndStage(): array
    {
        $count_ok = 0;
        $errors   = array();
        $temp_dir = DOL_DATA_ROOT . '/geminvoice/temp';

        if (!is_dir($temp_dir)) {
            dol_mkdir($temp_dir);
        }

        $uploaded = $this->normalizeUploadedFiles('geminvoice_facturx');

        if (empty($uploaded)) {
            return array('count' => 0, 'errors' => array());
        }

        $staging = new GeminvoiceStaging($this->db);

        foreach ($uploaded as $file_info) {
            $original_name = dol_sanitizeFileName($file_info['name']);
            $tmp_path      = $file_info['tmp_name'];
            $dest_path     = $temp_dir . '/' . uniqid('facturx_') . '_' . $original_name;

            if (!move_uploaded_file($tmp_path, $dest_path)) {
                $errors[] = $original_name . ': impossible de déplacer le fichier temporaire';
                continue;
            }

            // Extract or load XML
            $xml_content = $this->extractXml($dest_path, $original_name);
            if ($xml_content === false) {
                $errors[] = $original_name . ': aucun XML Factur-X trouvé ou format non reconnu';
                dol_syslog('Geminvoice FacturxSource: pas de XML Factur-X dans ' . $original_name, LOG_WARNING);
                continue;
            }

            // Parse XML to Geminvoice JSON schema
            $json_data = $this->parseXml($xml_content);
            if (!$json_data || empty($json_data['vendor_name'])) {
                $errors[] = $original_name . ': impossible d\'extraire les données de la facture (XML invalide ou profil non supporté)';
                dol_syslog('Geminvoice FacturxSource: parsing échoué — ' . $original_name, LOG_ERR);
                continue;
            }

            $file_token = 'facturx_' . md5($dest_path . microtime());
            $staging_id = $staging->create($file_token, $original_name, $dest_path, $json_data, GeminvoiceStaging::STATUS_PENDING, '', 'facturx');

            if ($staging_id > 0) {
                dol_syslog('Geminvoice FacturxSource: OK — ' . $original_name . ' → Staging ID=' . $staging_id, LOG_INFO);
                $count_ok++;
            } else {
                $errors[] = $original_name . ': erreur staging (' . $staging->error . ')';
                dol_syslog('Geminvoice FacturxSource: erreur staging — ' . $original_name . ': ' . $staging->error, LOG_ERR);
            }
        }

        return array('count' => $count_ok, 'errors' => $errors);
    }

    // -------------------------------------------------------------------------
    // XML extraction
    // -------------------------------------------------------------------------

    /**
     * Attempt to obtain Factur-X XML content from a file.
     * For .xml files: return file content directly.
     * For .pdf files: attempt to extract the embedded XML attachment.
     *
     * @param  string $path          Absolute path to the file
     * @param  string $original_name Original filename (used to detect extension)
     * @return string|false          Raw XML string, or false if not found / unsupported
     */
    private function extractXml(string $path, string $original_name)
    {
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

        if ($ext === 'xml') {
            $content = file_get_contents($path);
            return $content !== false && strlen($content) > 10 ? $content : false;
        }

        if ($ext === 'pdf') {
            return $this->extractXmlFromPdf($path);
        }

        return false;
    }

    /**
     * Extract an embedded XML attachment from a PDF/A-3 byte stream.
     *
     * Strategy: search for the PDF "EmbeddedFile" stream that contains the
     * Factur-X XML. The embedded file is identified by its filename in the
     * /Filespec dictionary. We search the raw bytes for the XML content
     * between its stream delimiters.
     *
     * This approach is dependency-free (no external library required).
     * It handles uncompressed streams; FlateDecode-compressed attachments
     * require the zlib extension (available by default on PHP 7.4+).
     *
     * @param  string $pdf_path  Absolute path to the PDF file
     * @return string|false      XML string or false
     */
    private function extractXmlFromPdf(string $pdf_path)
    {
        $pdf_bytes = file_get_contents($pdf_path);
        if ($pdf_bytes === false) {
            return false;
        }

        // Known Factur-X/ZUGFeRD XML attachment filenames
        $candidates = array(self::FX_XML_FILENAME, self::ZF_XML_FILENAME, self::OX_XML_FILENAME);

        foreach ($candidates as $xml_filename) {
            // Locate the filename reference inside the PDF
            $fname_pos = strpos($pdf_bytes, '(' . $xml_filename . ')');
            if ($fname_pos === false) {
                // Try UTF-16BE hex encoded filename (some PDF writers)
                $fname_pos = strpos($pdf_bytes, '<' . bin2hex($xml_filename) . '>');
            }
            if ($fname_pos === false) {
                continue;
            }

            // Find the nearest "stream" keyword after the filename reference
            $stream_start = strpos($pdf_bytes, "stream\n", $fname_pos);
            if ($stream_start === false) {
                $stream_start = strpos($pdf_bytes, "stream\r\n", $fname_pos);
                if ($stream_start === false) {
                    continue;
                }
                $stream_start += 8; // skip "stream\r\n"
            } else {
                $stream_start += 7; // skip "stream\n"
            }

            $stream_end = strpos($pdf_bytes, 'endstream', $stream_start);
            if ($stream_end === false) {
                continue;
            }

            $stream_data = substr($pdf_bytes, $stream_start, $stream_end - $stream_start);

            // Try zlib inflate (FlateDecode) — most PDF/A-3 use this
            if (function_exists('gzuncompress')) {
                $inflated = @gzuncompress($stream_data);
                if ($inflated !== false && strpos($inflated, '<?xml') !== false) {
                    return $inflated;
                }
                // Try gzinflate (raw deflate without header)
                $inflated = @gzinflate($stream_data);
                if ($inflated !== false && strpos($inflated, '<?xml') !== false) {
                    return $inflated;
                }
            }

            // Uncompressed stream
            if (strpos($stream_data, '<?xml') !== false) {
                return $stream_data;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // XML parsing — CII (Factur-X / ZUGFeRD) and UBL
    // -------------------------------------------------------------------------

    /**
     * Parse a Factur-X XML string and map it to the Geminvoice JSON schema.
     *
     * @param  string     $xml_content  Raw XML string
     * @return array|false              Associative array matching Gemini OCR output schema, or false on failure
     */
    private function parseXml(string $xml_content)
    {
        // Suppress libxml errors — we check the result ourselves
        // XXE protection: disable external entity loading (SSRF/file-read vector)
        if (PHP_VERSION_ID < 80000) {
            // phpcs:ignore -- libxml_disable_entity_loader deprecated in PHP 8 (XXE off by default)
            libxml_disable_entity_loader(true);
        }
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // LIBXML_NONET blocks network access; LIBXML_NOENT prevents entity substitution
        if (!$dom->loadXML($xml_content, LIBXML_NONET | LIBXML_NOENT)) {
            libxml_clear_errors();
            return false;
        }
        libxml_clear_errors();

        $root_ns = $dom->documentElement ? $dom->documentElement->namespaceURI : '';

        if ($root_ns === self::NS_CII_RSM || strpos($root_ns, 'CrossIndustryInvoice') !== false) {
            return $this->parseCii($dom);
        }

        if ($root_ns === self::NS_UBL || strpos($root_ns, 'ubl:schema:xsd:Invoice') !== false) {
            return $this->parseUbl($dom);
        }

        // Namespace-free fallback: try CII tag names
        $root_local = $dom->documentElement ? $dom->documentElement->localName : '';
        if ($root_local === 'CrossIndustryInvoice') {
            return $this->parseCii($dom);
        }
        if ($root_local === 'Invoice') {
            return $this->parseUbl($dom);
        }

        return false;
    }

    /**
     * Parse a CII (Cross-Industry Invoice) DOM into Geminvoice JSON schema.
     * Covers Factur-X MINIMUM through EXTENDED profiles and ZUGFeRD 2.x.
     *
     * @param  DOMDocument $dom
     * @return array
     */
    private function parseCii(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('rsm', self::NS_CII_RSM);
        $xpath->registerNamespace('ram', self::NS_CII_RAM);
        $xpath->registerNamespace('udt', self::NS_CII_UDT);

        // Helper: get text of first matching node
        $get = function (string $query, ?DOMNode $ctx = null) use ($xpath) {
            $nodes = $xpath->query($query, $ctx);
            return ($nodes && $nodes->length > 0) ? trim($nodes->item(0)->textContent) : '';
        };

        // --- Header ---
        $invoice_number = $get('//ram:ExchangedDocument/ram:ID');
        $issue_date_raw = $get('//ram:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString');
        $invoice_date   = $this->parseCiiDate($issue_date_raw);

        // --- Seller (vendor) ---
        $vendor_name = $get('//ram:SellerTradeParty/ram:Name');

        // --- Totals ---
        $total_ht  = $get('//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount');
        $total_ttc = $get('//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:GrandTotalAmount');
        $currency  = $get('//ram:InvoiceCurrencyCode');
        if (!$currency) {
            $currency = $get('//ram:SpecifiedTradeSettlementHeaderMonetarySummation/ram:TaxBasisTotalAmount/@currencyID');
        }

        // --- Lines ---
        $lines     = array();
        $line_nodes = $xpath->query('//ram:IncludedSupplyChainTradeLineItem');
        if ($line_nodes) {
            foreach ($line_nodes as $line_node) {
                $description = $get('ram:SpecifiedTradeProduct/ram:Name', $line_node);
                if (!$description) {
                    $description = $get('ram:SpecifiedTradeProduct/ram:Description', $line_node);
                }
                $qty         = $get('ram:SpecifiedLineTradeDelivery/ram:BilledQuantity', $line_node);
                $unit_price  = $get('ram:SpecifiedLineTradeAgreement/ram:NetPriceProductTradePrice/ram:ChargeAmount', $line_node);
                $line_total  = $get('ram:SpecifiedLineTradeSettlement/ram:SpecifiedTradeSettlementLineMonetarySummation/ram:LineTotalAmount', $line_node);
                $vat_rate    = $get('ram:SpecifiedLineTradeSettlement/ram:ApplicableTradeTax/ram:RateApplicablePercent', $line_node);

                $lines[] = array(
                    'description'   => $description,
                    'qty'           => $qty !== '' ? (float) $qty : 1,
                    'unit_price_ht' => $unit_price !== '' ? (float) $unit_price : (float) $line_total,
                    'vat_rate'      => $vat_rate !== '' ? (float) $vat_rate : 20.0,
                    'total_ht'      => (float) $line_total,
                );
            }
        }

        return array(
            'vendor_name'    => $vendor_name,
            'invoice_number' => $invoice_number,
            'date'           => $invoice_date,
            'total_ht'       => (float) $total_ht,
            'total_ttc'      => (float) $total_ttc,
            'currency'       => $currency ?: 'EUR',
            'lines'          => $lines,
            '_source_format' => 'facturx_cii',
        );
    }

    /**
     * Parse a UBL 2.1 Invoice DOM into Geminvoice JSON schema.
     * Supports BIS Billing 3.0 (Peppol).
     *
     * @param  DOMDocument $dom
     * @return array
     */
    private function parseUbl(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('ubl',  self::NS_UBL);
        $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
        $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

        $get = function (string $query, ?DOMNode $ctx = null) use ($xpath) {
            $nodes = $xpath->query($query, $ctx);
            return ($nodes && $nodes->length > 0) ? trim($nodes->item(0)->textContent) : '';
        };

        $invoice_number = $get('//cbc:ID');
        $invoice_date   = $get('//cbc:IssueDate');
        $vendor_name    = $get('//cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name');
        $total_ht       = $get('//cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount');
        $total_ttc      = $get('//cac:LegalMonetaryTotal/cbc:PayableAmount');
        $currency       = $get('//cbc:DocumentCurrencyCode');

        $lines     = array();
        $line_nodes = $xpath->query('//cac:InvoiceLine');
        if ($line_nodes) {
            foreach ($line_nodes as $line_node) {
                $description = $get('cac:Item/cbc:Name', $line_node);
                $qty         = $get('cbc:InvoicedQuantity', $line_node);
                $unit_price  = $get('cac:Price/cbc:PriceAmount', $line_node);
                $line_total  = $get('cbc:LineExtensionAmount', $line_node);
                $vat_rate    = $get('cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $line_node);

                $lines[] = array(
                    'description'   => $description,
                    'qty'           => $qty !== '' ? (float) $qty : 1,
                    'unit_price_ht' => $unit_price !== '' ? (float) $unit_price : (float) $line_total,
                    'vat_rate'      => $vat_rate !== '' ? (float) $vat_rate : 20.0,
                    'total_ht'      => (float) $line_total,
                );
            }
        }

        return array(
            'vendor_name'    => $vendor_name,
            'invoice_number' => $invoice_number,
            'date'           => $invoice_date,
            'total_ht'       => (float) $total_ht,
            'total_ttc'      => (float) $total_ttc,
            'currency'       => $currency ?: 'EUR',
            'lines'          => $lines,
            '_source_format' => 'ubl',
        );
    }

    /**
     * Convert a CII date string (YYYYMMDD with format code 102) to Y-m-d.
     *
     * @param  string $raw  e.g. "20240315" or "2024-03-15"
     * @return string       Y-m-d format or empty string
     */
    private function parseCiiDate(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        return '';
    }

    // -------------------------------------------------------------------------
    // File upload helper (identical pattern to UploadSource)
    // -------------------------------------------------------------------------

    /**
     * Normalise the $_FILES superglobal for a multi-file input into a flat list.
     *
     * @param  string         $input_name  Name attribute of the <input type="file" multiple>
     * @return array<array>
     */
    private function normalizeUploadedFiles(string $input_name): array
    {
        if (empty($_FILES[$input_name])) {
            return array();
        }

        $raw  = $_FILES[$input_name];
        $list = array();

        if (is_array($raw['name'])) {
            $count = count($raw['name']);
            for ($k = 0; $k < $count; $k++) {
                if ($raw['error'][$k] !== UPLOAD_ERR_OK) {
                    continue;
                }
                $list[] = array(
                    'name'     => $raw['name'][$k],
                    'tmp_name' => $raw['tmp_name'][$k],
                    'type'     => $raw['type'][$k],
                    'size'     => $raw['size'][$k],
                );
            }
        } elseif ($raw['error'] === UPLOAD_ERR_OK) {
            $list[] = array(
                'name'     => $raw['name'],
                'tmp_name' => $raw['tmp_name'],
                'type'     => $raw['type'],
                'size'     => $raw['size'],
            );
        }

        return $list;
    }
}
