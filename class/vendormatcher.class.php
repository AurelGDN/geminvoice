<?php
/**
 *  \file       class/vendormatcher.class.php
 *  \ingroup    geminvoice
 *  \brief      Fuzzy matching between an OCR-extracted vendor name and Dolibarr Tiers (Alpha17)
 *
 *  Resolution chain (returns on first hit ≥ threshold):
 *    1. Exact match (case-insensitive)               → score 100, method 'exact'
 *    2. Normalized exact match (stripped legal forms) → score 95,  method 'normalized'
 *    3. Substring containment (one contains other)   → score 85,  method 'substring'
 *    4. similar_text() on normalized forms           → score 0-84, method 'fuzzy'
 *
 *  A match is accepted if score >= MATCH_THRESHOLD (75).
 *  Below threshold: null is returned and mapper.class.php will create a new Tiers.
 */

class GeminvoiceVendorMatcher
{
    /** @var DoliDB */
    private $db;

    /** Minimum score (0-100) to accept a fuzzy match */
    const MATCH_THRESHOLD = 75;

    /** Legal-form suffixes to strip before comparing */
    private static $LEGAL_FORMS = array(
        'sarl', 'sas', 'sasu', 'sa', 'snc', 'sci', 'eurl', 'ei', 'scp',
        'scop', 'scea', 'gaec', 'earl', 'association', 'asso',
    );

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Find the best matching Dolibarr Tiers (fournisseur=1) for a given OCR vendor name.
     *
     * @param  string $vendor_name  Name extracted by OCR/AI
     * @return array|null           ['rowid'=>int, 'name'=>string, 'score'=>int, 'method'=>string]
     *                              or null if no match meets the threshold
     */
    public function findMatch(string $vendor_name): ?array
    {
        if (empty(trim($vendor_name))) {
            return null;
        }

        $suppliers = $this->loadSuppliers();
        if (empty($suppliers)) {
            return null;
        }

        $vendor_norm = $this->normalize($vendor_name);
        $best        = null;

        foreach ($suppliers as $soc) {
            $score  = 0;
            $method = 'fuzzy';
            $soc_norm = $this->normalize($soc['name']);

            // 1. Exact match (case-insensitive)
            if (mb_strtolower(trim($vendor_name)) === mb_strtolower(trim($soc['name']))) {
                return array('rowid' => $soc['rowid'], 'name' => $soc['name'], 'score' => 100, 'method' => 'exact');
            }

            // 2. Normalized exact match
            if ($vendor_norm === $soc_norm && $vendor_norm !== '') {
                $score  = 95;
                $method = 'normalized';
            }
            // 3. Substring containment
            elseif ($vendor_norm !== '' && $soc_norm !== '') {
                if (mb_strpos($soc_norm, $vendor_norm) !== false || mb_strpos($vendor_norm, $soc_norm) !== false) {
                    $score  = 85;
                    $method = 'substring';
                }
                // 4. similar_text() fuzzy scoring
                else {
                    similar_text($vendor_norm, $soc_norm, $pct);
                    $score  = (int) round($pct);
                    $method = 'fuzzy';
                }
            }

            if ($score >= self::MATCH_THRESHOLD) {
                if ($best === null || $score > $best['score']) {
                    $best = array('rowid' => $soc['rowid'], 'name' => $soc['name'], 'score' => $score, 'method' => $method);
                }
                // Perfect normalized match — no need to continue
                if ($score >= 95) {
                    break;
                }
            }
        }

        return $best;
    }

    /**
     * Load all active suppliers from llx_societe.
     *
     * @return array<array{rowid:int, name:string}>
     */
    private function loadSuppliers(): array
    {
        $sql  = "SELECT rowid, nom FROM " . MAIN_DB_PREFIX . "societe";
        $sql .= " WHERE fournisseur = 1 AND entity IN (" . getEntity('societe') . ")";
        $sql .= " AND status = 1";
        $sql .= " ORDER BY nom ASC";

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog('Geminvoice VendorMatcher: loadSuppliers() failed — ' . $this->db->lasterror(), LOG_ERR);
            return array();
        }

        $result = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $result[] = array('rowid' => (int) $obj->rowid, 'name' => $obj->nom);
        }
        return $result;
    }

    /**
     * Normalize a vendor name for comparison:
     *   - lower-case
     *   - accent removal
     *   - strip legal-form suffixes (SAS, SARL, …)
     *   - collapse whitespace / punctuation
     *
     * @param  string $name
     * @return string
     */
    private function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name));

        // Transliterate accents (requires intl or iconv)
        if (function_exists('transliterator_transliterate')) {
            $name = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        } else {
            $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        }

        // Remove punctuation / special chars
        $name = preg_replace('/[^a-z0-9\s]/', ' ', (string) $name);

        // Strip legal forms as standalone tokens
        $tokens = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_filter($tokens, function ($t) {
            return !in_array($t, self::$LEGAL_FORMS, true);
        });

        return implode(' ', $tokens);
    }
}
