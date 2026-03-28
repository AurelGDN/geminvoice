<?php
/**
 *  \file       class/geminirecognition.class.php
 *  \ingroup    geminvoice
 *  \brief      AI-powered product recognition using Gemini API.
 *              Receives pre-filtered candidates from textmatch and asks
 *              Gemini to select the most appropriate one. (Alpha14)
 *
 *              Called only when local text-matching is uncertain (score < threshold),
 *              to minimise API token consumption.
 */

class GeminiRecognition
{
    /** @var string */
    private $api_key;

    /** @var string  Full API endpoint URL including model */
    private $api_url;

    /** @var string  Last error message */
    public $error = '';

    /** @var int  Minimum AI confidence (0-100) to accept a suggestion */
    const MIN_CONFIDENCE = 50;

    /**
     * @param DoliDB $db  (unused but kept for symmetry with other classes)
     */
    public function __construct($db)
    {
        global $conf;
        $this->api_key = !empty($conf->global->GEMINVOICE_GEMINI_API_KEY)
            ? $conf->global->GEMINVOICE_GEMINI_API_KEY
            : '';
        $model = !empty($conf->global->GEMINVOICE_GEMINI_MODEL)
            ? $conf->global->GEMINVOICE_GEMINI_MODEL
            : 'gemini-1.5-flash';
        $this->api_url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . urlencode($model) . ':generateContent';
    }

    // ------------------------------------------------------------------
    //  Public API
    // ------------------------------------------------------------------

    /**
     * Ask Gemini to pick the best product from a pre-filtered candidate list.
     *
     * @param  string  $description  OCR invoice line description
     * @param  array   $candidates   Output of GeminvoiceTextMatch::findTopCandidates()
     *                               Each entry: {rowid, ref, label, tva_tx, accounting_code, score}
     * @return object|null  {rowid, accounting_code, confidence, reason, from_cache:false} or null
     */
    public function suggestProduct($description, array $candidates)
    {
        if (empty($this->api_key)) {
            $this->error = 'Gemini API key missing.';
            dol_syslog('Geminvoice GeminiRecognition: ' . $this->error, LOG_WARNING);
            return null;
        }

        if (empty($candidates) || mb_strlen(trim($description)) < 3) {
            return null;
        }

        $prompt = $this->buildPrompt($description, $candidates);
        $raw    = $this->callApi($prompt);
        if ($raw === null) {
            return null;
        }

        return $this->parseResponse($raw, $candidates);
    }

    // ------------------------------------------------------------------
    //  Prompt & API
    // ------------------------------------------------------------------

    /**
     * Build the Gemini prompt.
     *
     * @param  string $description
     * @param  array  $candidates
     * @return string
     */
    private function buildPrompt($description, array $candidates)
    {
        // Sanitize description to prevent prompt injection: strip control characters,
        // limit length, and remove prompt-structural patterns.
        $safe_description = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $description);
        $safe_description = preg_replace('/\b(Instructions?|Ignore|Forget|System|PROMPT)\b/i', '***', $safe_description);
        $safe_description = mb_substr(trim($safe_description), 0, 250);

        $list = '';
        foreach ($candidates as $i => $c) {
            $list .= ($i + 1) . '. rowid=' . (int) $c->rowid
                . ' | ref=' . preg_replace('/[\x00-\x1F\x7F]/', '', (string) $c->ref)
                . ' | label=' . preg_replace('/[\x00-\x1F\x7F]/', '', (string) $c->label)
                . "\n";
            // accounting_code is intentionally omitted from the prompt:
            // the AI selects by rowid only; we retrieve the code from our DB (see parseResponse).
        }

        return <<<PROMPT
You are an accounting assistant for a French company. You must identify the most appropriate product from a list based on an invoice line description.

Invoice line description: "{$safe_description}"

Product candidates (pre-filtered by text similarity):
{$list}

Instructions:
- Select the candidate that best matches the invoice line description.
- If no candidate is a reasonable match, set rowid to 0.
- Respond ONLY with a valid JSON object, no markdown, no explanation outside the JSON.

Required JSON format:
{
  "rowid": <integer, rowid of the selected candidate or 0 if no match>,
  "confidence": <integer 0-100, your confidence in this selection>,
  "reason": "<one short sentence explaining your choice in French>"
}
PROMPT;
    }

    /**
     * Send the prompt to Gemini and return the raw text response.
     *
     * @param  string      $prompt
     * @return string|null
     */
    private function callApi($prompt)
    {
        $payload = json_encode(array(
            'contents' => array(
                array(
                    'parts' => array(
                        array('text' => $prompt)
                    )
                )
            ),
            'generationConfig' => array(
                'temperature'     => 0.1,
                'maxOutputTokens' => 256,
            )
        ));

        // API key sent as header to avoid URL log exposure
        $ch = curl_init($this->api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'x-goog-api-key: ' . $this->api_key,
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err || $http_code !== 200) {
            $this->error = 'API call failed (HTTP ' . $http_code . '): ' . $curl_err;
            dol_syslog('Geminvoice GeminiRecognition: ' . $this->error, LOG_WARNING);
            return null;
        }

        $data = json_decode($response, true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (empty($text)) {
            $this->error = 'Empty response from Gemini.';
            dol_syslog('Geminvoice GeminiRecognition: ' . $this->error, LOG_WARNING);
            return null;
        }

        return $text;
    }

    /**
     * Parse Gemini's JSON response and validate the result.
     *
     * @param  string $raw_text   Raw text from Gemini
     * @param  array  $candidates Original candidate list (used to validate rowid)
     * @return object|null
     */
    private function parseResponse($raw_text, array $candidates)
    {
        // Strip optional markdown code fences
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw_text));
        $clean = preg_replace('/\s*```$/', '', $clean);

        $json = json_decode($clean, true);
        if (!is_array($json)) {
            $this->error = 'Could not parse Gemini JSON response: ' . $raw_text;
            dol_syslog('Geminvoice GeminiRecognition: ' . $this->error, LOG_WARNING);
            return null;
        }

        $rowid      = (int) ($json['rowid'] ?? 0);
        $confidence = (int) ($json['confidence'] ?? 0);

        if ($rowid <= 0 || $confidence < self::MIN_CONFIDENCE) {
            return null;
        }

        // Verify the rowid is actually one of the candidates and retrieve its data from our DB,
        // not from the AI response (prevents prompt-injection from supplying arbitrary codes).
        $matched_candidate = null;
        foreach ($candidates as $c) {
            if ((int) $c->rowid === $rowid) {
                $matched_candidate = $c;
                break;
            }
        }
        if ($matched_candidate === null) {
            $this->error = 'Gemini returned unknown rowid: ' . $rowid;
            dol_syslog('Geminvoice GeminiRecognition: ' . $this->error, LOG_WARNING);
            return null;
        }

        return (object) array(
            'rowid'           => $rowid,
            'accounting_code' => (string) $matched_candidate->accounting_code, // sourced from DB, not AI
            'confidence'      => $confidence,
            'reason'          => (string) ($json['reason'] ?? ''),
            'from_cache'      => false,
        );
    }
}
