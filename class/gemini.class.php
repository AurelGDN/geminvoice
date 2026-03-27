<?php
/**
 *  \file       class/gemini.class.php
 *  \ingroup    geminvoice
 *  \brief      Class to manage Gemini AI API calls for OCR
 */

class GeminiOCR
{
    private $api_key;
    private $db;
    private $api_url;
    public $error = "";

    /**
     * Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;
        $this->db = $db;
        $this->api_key = !empty($conf->global->GEMINVOICE_GEMINI_API_KEY) ? $conf->global->GEMINVOICE_GEMINI_API_KEY : '';
        
        $model = !empty($conf->global->GEMINVOICE_GEMINI_MODEL) ? $conf->global->GEMINVOICE_GEMINI_MODEL : 'gemini-1.5-flash';
        $this->api_url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($model) . ":generateContent";
    }

    /**
     * Analyze a file (PDF or Image) using Gemini API
     * 
     * @param string $filepath Absolute path to the file
     * @param string $mime_type MIME type of the file (application/pdf, image/jpeg, etc.)
     * @return array|bool Parsed JSON data array or false on failure
     */
    public function analyzeInvoice($filepath, $mime_type)
    {
        if (empty($this->api_key)) {
            $this->error = "Gemini API Key is missing.";
            dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
            return false;
        }

        if (!file_exists($filepath)) {
            $this->error = "File not found for analysis: " . $filepath;
            dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
            return false;
        }

        // Reject files larger than 20 MB to avoid exhausting PHP memory
        $file_size = filesize($filepath);
        if ($file_size === false || $file_size > 20971520) {
            $this->error = "File too large for Gemini analysis (max 20 MB): " . $filepath;
            dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
            return false;
        }

        // Read file content
        $file_data = file_get_contents($filepath);
        if ($file_data === false) {
            $this->error = "Failed to read file content: " . $filepath;
            dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
            return false;
        }
        
        $base64_data = base64_encode($file_data);

        // System Prompt — Structured JSON Output with per-line accounting code (PCG France)
        $prompt = "You are an expert French accountant system. Extract data from this supplier invoice document and output ONLY valid JSON, with no markdown, no explanation.

Structure required:
{
    \"vendor_name\": \"Company Name\",
    \"vendor_siret\": \"123 456 789 00012\",
    \"vendor_vat\": \"FR12345678900\",
    \"vendor_address\": \"1 rue de la Paix\",
    \"vendor_zip\": \"75001\",
    \"vendor_city\": \"Paris\",
    \"invoice_number\": \"INV-1234\",
    \"date\": \"YYYY-MM-DD\",
    \"total_ht\": 100.00,
    \"total_ttc\": 120.00,
    \"currency\": \"EUR\",
    \"is_credit_note\": false,
    \"taxes\": [
        {\"rate\": 20.0, \"amount\": 20.00}
    ],
    \"lines\": [
        {
            \"description\": \"Item description\",
            \"qty\": 1,
            \"unit_price_ht\": 100.00,
            \"vat_rate\": 20.0,
            \"accounting_code\": \"607000\",
            \"is_parafiscal\": false
        }
    ]
}

Rules:
- \"is_credit_note\": set to true if the document is a credit note (avoir, note de crédit, remboursement, facture d'avoir). For credit notes, all monetary amounts in \"lines\" MUST be positive numbers — the credit nature is expressed by is_credit_note=true, not by negative signs.
- \"vendor_siret\": SIRET number if visible on the document (14 digits). null if absent.
- \"vendor_vat\": intra-community VAT number (e.g. FR12345678900). null if absent.
- \"vendor_address\", \"vendor_zip\", \"vendor_city\": vendor postal address if visible. null if absent.
- \"qty\": MUST be the actual multiplier used to calculate the line total. Beware of invoices displaying a \"Poids\" or \"Base\" column separately from \"Qté\". If the line total is calculated as Poids * Prix Unitaire, output the Poids value in \"qty\".
- \"accounting_code\": suggest the most appropriate French PCG account number. Examples: purchases of goods=607xxx, services/fees=604xxx or 622xxx, professional subscriptions/taxes=637xxx, energy=606xxx, insurance=616xxx.
- \"is_parafiscal\": true if the line is a parafiscal tax or levy (CVO, CRPV, TICPE, CSPE) — these lines often have vat_rate=0.
- Keep all monetary values as numbers (not strings).
- If a field cannot be determined, use null.";

        $payload = array(
            "contents" => array(
                array(
                    "parts" => array(
                        array("text" => $prompt),
                        array(
                            "inlineData" => array(
                                "mimeType" => $mime_type,
                                "data" => $base64_data
                            )
                        )
                    )
                )
            ),
            "generationConfig" => array(
                "temperature" => 0.1
            )
        );

        $json_payload = json_encode($payload);

        // Make cURL request — API key sent as header to avoid URL log exposure
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "x-goog-api-key: " . $this->api_key,
        ));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // OCR can be slow on large PDFs

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $this->error = "cURL Error: " . $curl_err;
            dol_syslog("Geminvoice GeminiOCR: " . $this->error, LOG_ERR);
            return false;
        }

        if ($http_code !== 200) {
            $this->error = "Gemini API HTTP " . $http_code . ". Response: " . $response;
            dol_syslog("Geminvoice GeminiOCR: " . $this->error, LOG_ERR);
            return false;
        }

        $decoded = json_decode($response, true);
        $raw_text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($raw_text)) {
            $this->error = "Unexpected response structure from Gemini API.";
            dol_syslog("Geminvoice GeminiOCR: " . $this->error, LOG_ERR);
            return false;
        }

        // Strip optional markdown code fences
        $raw_text = preg_replace('/^```(?:json)?\s*/i', '', trim($raw_text));
        $raw_text = preg_replace('/\s*```$/', '', $raw_text);

        $result = json_decode(trim($raw_text), true);
        if (!is_array($result)) {
            $this->error = "Could not parse JSON from Gemini response: " . $raw_text;
            dol_syslog("Geminvoice GeminiOCR: " . $this->error, LOG_ERR);
            return false;
        }

        return $result;
    }
}
