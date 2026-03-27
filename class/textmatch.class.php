<?php
/**
 *  \file       class/textmatch.class.php
 *  \ingroup    geminvoice
 *  \brief      Local text-similarity engine for matching OCR descriptions
 *              against the Dolibarr product catalogue and accounting accounts.
 *              No API call — pure PHP string analysis. (Alpha13)
 */

class GeminvoiceTextMatch
{
    /** @var DoliDB */
    private $db;

    /** @var array  Product catalogue [{rowid, ref, label, tva_tx, accounting_code}] */
    private $products = array();

    /** @var array  Accounting accounts [{number, label}] */
    private $accounts = array();

    /** @var int  Minimum similarity score (0-100) to consider a match */
    private $threshold;

    /** @var array  French stopwords removed during tokenization */
    private static $stopwords = array(
        'le','la','les','de','du','des','d','l','un','une','et','en','au','aux',
        'pour','par','sur','avec','dans','ce','cette','ces','son','sa','ses',
        'notre','nos','votre','vos','qui','que','dont','ou','ne','pas','plus',
        'se','si','a','n','y','est','sont','etre','avoir','fait','ete',
    );

    /**
     * @param DoliDB $db
     * @param int    $threshold  Minimum score (0-100). Default 60.
     */
    public function __construct($db, $threshold = 60)
    {
        $this->db        = $db;
        $this->threshold = (int) $threshold;
    }

    /**
     * Inject the product catalogue (already loaded by review.php).
     * @param array $all_products
     */
    public function setProducts($all_products)
    {
        $this->products = $all_products;
    }

    /**
     * Inject the accounting account list (already loaded by review.php).
     * @param array $all_accounts
     */
    public function setAccounts($all_accounts)
    {
        $this->accounts = $all_accounts;
    }

    // ------------------------------------------------------------------
    //  Public matching API
    // ------------------------------------------------------------------

    /**
     * Find the best matching product for a line description.
     * Returns the single best match above $this->threshold, or null.
     *
     * @param  string     $description  OCR line description
     * @return object|null  {rowid, ref, label, tva_tx, accounting_code, score, match_source} or null
     */
    public function findProduct($description)
    {
        $candidates = $this->scoreAllProducts($description);
        if (empty($candidates)) {
            return null;
        }
        $best = $candidates[0];
        return ($best->score >= $this->threshold) ? $best : null;
    }

    /**
     * Return the top-N product candidates sorted by score descending.
     * No threshold filter — used to feed the AI recognition cascade.
     *
     * @param  string  $description  OCR line description
     * @param  int     $limit        Max number of candidates to return (default 5)
     * @return array   Array of {rowid, ref, label, tva_tx, accounting_code, score, match_source}
     */
    public function findTopCandidates($description, $limit = 5)
    {
        $candidates = $this->scoreAllProducts($description);
        return array_slice($candidates, 0, (int) $limit);
    }

    /**
     * Score all products against a description and return them sorted by score desc.
     *
     * @param  string $description
     * @return array
     */
    private function scoreAllProducts($description)
    {
        if (mb_strlen(trim($description)) < 3 || empty($this->products)) {
            return array();
        }

        // Build account_number → label lookup for cross-referencing
        $acc_labels = array();
        foreach ($this->accounts as $acc) {
            $acc_labels[$acc['number']] = $acc['label'];
        }

        $results = array();

        foreach ($this->products as $prod) {
            $candidate = trim($prod['ref'] . ' ' . $prod['label']);
            $score     = $this->computeScore($description, $candidate);
            $source    = 'product';

            if (!empty($prod['accounting_code']) && isset($acc_labels[$prod['accounting_code']])) {
                $acc_text  = $prod['accounting_code'] . ' ' . $acc_labels[$prod['accounting_code']];
                $acc_score = $this->computeScore($description, $acc_text);
                if ($acc_score > $score) {
                    $score  = $acc_score;
                    $source = 'account_label';
                }
            }

            if ($score > 0) {
                $results[] = (object) array(
                    'rowid'           => (int) $prod['rowid'],
                    'ref'             => $prod['ref'],
                    'label'           => $prod['label'],
                    'tva_tx'          => (float) $prod['tva_tx'],
                    'accounting_code' => (string) $prod['accounting_code'],
                    'score'           => (int) round($score),
                    'match_source'    => $source,
                );
            }
        }

        usort($results, function ($a, $b) {
            return $b->score - $a->score;
        });

        return $results;
    }

    /**
     * Find the best matching accounting code for a line description.
     *
     * @param  string      $description  OCR line description
     * @return object|null  {account_number, label, score} or null
     */
    public function findAccountingCode($description)
    {
        if (mb_strlen(trim($description)) < 3 || empty($this->accounts)) {
            return null;
        }

        $best      = null;
        $bestScore = 0;

        foreach ($this->accounts as $acc) {
            $candidate = $acc['number'] . ' ' . $acc['label'];
            $score     = $this->computeScore($description, $candidate);

            if ($score > $bestScore && $score >= $this->threshold) {
                $bestScore = $score;
                $best = (object) array(
                    'account_number' => $acc['number'],
                    'label'          => $acc['label'],
                    'score'          => (int) round($score),
                );
            }
        }

        return $best;
    }

    // ------------------------------------------------------------------
    //  Scoring engine
    // ------------------------------------------------------------------

    /**
     * Compute a similarity score (0-100) between two strings.
     *
     * Uses three strategies and returns the best:
     *   1. Exact substring match (score 90-95)
     *   2. Token-based matching with per-token fuzzy + common-prefix detection
     *   3. Full-string similar_text()
     *
     * @param  string $needle    The search text (OCR line description)
     * @param  string $haystack  The candidate text (product label, account label, etc.)
     * @return float             Score 0-100
     */
    private function computeScore($needle, $haystack)
    {
        $needle_n   = $this->normalize($needle);
        $haystack_n = $this->normalize($haystack);

        if ($needle_n === '' || $haystack_n === '') {
            return 0;
        }

        // --- Strategy 1: Substring containment ---
        if (mb_strlen($needle_n) >= 4 && mb_strpos($haystack_n, $needle_n) !== false) {
            return 95;
        }
        if (mb_strlen($haystack_n) >= 4 && mb_strpos($needle_n, $haystack_n) !== false) {
            return 90;
        }

        // --- Strategy 2: Token-based ---
        $needle_tokens   = $this->tokenize($needle);
        $haystack_tokens = $this->tokenize($haystack);

        if (empty($needle_tokens) || empty($haystack_tokens)) {
            return 0;
        }

        $matched      = 0;
        $total        = count($needle_tokens);
        $best_single  = 0;

        foreach ($needle_tokens as $nt) {
            $token_best = 0;
            foreach ($haystack_tokens as $ht) {
                // Common-prefix check (>= 5 shared chars = likely same root word)
                $prefix_len = $this->commonPrefixLength($nt, $ht);
                if ($prefix_len >= 5) {
                    $token_best = max($token_best, 80 + min($prefix_len, 10));
                }

                // Per-token similar_text
                similar_text($nt, $ht, $pct);
                $token_best = max($token_best, $pct);
            }

            if ($token_best >= 65) {
                $matched++;
            }
            $best_single = max($best_single, $token_best);
        }

        // Ratio of matched tokens
        $token_ratio_score = ($matched / $total) * 100;

        // Boost: if at least one token matches strongly, propagate partial credit
        // This handles "consommation électrique" vs "fourniture d'électricité"
        // where only "électrique/électricité" matches strongly
        $boosted_score = max($token_ratio_score, $best_single * 0.75);

        // --- Strategy 3: Full-string similar_text ---
        similar_text($needle_n, $haystack_n, $full_pct);

        return max($boosted_score, $full_pct);
    }

    // ------------------------------------------------------------------
    //  String utilities
    // ------------------------------------------------------------------

    /**
     * Normalize a string: lowercase, strip accents, collapse whitespace.
     *
     * @param  string $text
     * @return string
     */
    private function normalize($text)
    {
        $text = mb_strtolower(trim($text), 'UTF-8');

        // Remove accents via transliterator if available, otherwise manual mapping
        if (function_exists('transliterator_transliterate')) {
            $text = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
        } else {
            $text = strtr($text, array(
                'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ã'=>'a',
                'è'=>'e','ê'=>'e','ë'=>'e','é'=>'e',
                'ì'=>'i','î'=>'i','ï'=>'i','í'=>'i',
                'ò'=>'o','ô'=>'o','ö'=>'o','ó'=>'o','õ'=>'o',
                'ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u',
                'ÿ'=>'y','ý'=>'y',
                'ç'=>'c','ñ'=>'n','œ'=>'oe','æ'=>'ae',
            ));
        }

        // Collapse multiple spaces / special chars to single space
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        return trim($text);
    }

    /**
     * Tokenize a string into meaningful keywords.
     * Normalizes, splits, removes stopwords and tokens < 3 chars.
     *
     * @param  string $text
     * @return array
     */
    private function tokenize($text)
    {
        $normalized = $this->normalize($text);
        $parts      = explode(' ', $normalized);
        $tokens     = array();

        foreach ($parts as $p) {
            if (mb_strlen($p) < 3) {
                continue;
            }
            if (in_array($p, self::$stopwords, true)) {
                continue;
            }
            $tokens[] = $p;
        }

        return $tokens;
    }

    /**
     * Compute the length of the common prefix between two strings.
     *
     * @param  string $a
     * @param  string $b
     * @return int
     */
    private function commonPrefixLength($a, $b)
    {
        $len = min(mb_strlen($a), mb_strlen($b));
        $i   = 0;
        while ($i < $len && mb_substr($a, $i, 1) === mb_substr($b, $i, 1)) {
            $i++;
        }
        return $i;
    }
}
