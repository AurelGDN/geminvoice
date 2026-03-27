<?php
/**
 *  \file       class/sources/UploadSource.class.php
 *  \ingroup    geminvoice
 *  \brief      Batch PDF/image upload source — implements GeminvoiceSourceInterface (Alpha16)
 *
 *  Processes files uploaded directly via the Geminvoice dashboard (multipart POST).
 *  Each file is validated (MIME type), moved to dir_temp, analysed by Gemini OCR,
 *  then persisted in llx_geminvoice_staging with source='upload'.
 *
 *  This source is always configured (no external credentials needed) and always enabled.
 */

dol_include_once('/geminvoice/class/sources/GeminvoiceSourceInterface.php');
dol_include_once('/geminvoice/class/gemini.class.php');
dol_include_once('/geminvoice/class/staging.class.php');

class UploadSource implements GeminvoiceSourceInterface
{
    /**
     * @var DoliDB Database handler.
     */
    private $db;

    /**
     * Maximum accepted file size in bytes (20 MB).
     */
    const MAX_FILE_SIZE = 20971520;

    /**
     * Accepted MIME types for uploaded files.
     * @var array<string>
     */
    private static $ALLOWED_MIME = array(
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    );

    /**
     * Constructor.
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'upload';
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel(): string
    {
        global $langs;
        return $langs->trans('GeminvoiceSourceUpload');
    }

    /**
     * {@inheritdoc}
     */
    public function getIcon(): string
    {
        return 'fa-upload';
    }

    /**
     * {@inheritdoc}
     * Upload source only requires the Gemini API key.
     */
    public function isConfigured(): bool
    {
        global $conf;
        return !empty($conf->global->GEMINVOICE_GEMINI_API_KEY);
    }

    /**
     * {@inheritdoc}
     */
    public function isEnabled(): bool
    {
        return $this->isConfigured();
    }

    /**
     * Process a batch of uploaded files.
     *
     * Reads from $_FILES['geminvoice_uploads'] (multiple file input).
     * Each valid file is moved to dir_temp, analysed by Gemini OCR, and staged.
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
        $temp_dir = DOL_DATA_ROOT . '/geminvoice/temp';

        if (!is_dir($temp_dir)) {
            dol_mkdir($temp_dir);
        }

        // Normalise $_FILES array to a list of individual file entries
        $uploaded = $this->normalizeUploadedFiles('geminvoice_uploads');

        if (empty($uploaded)) {
            return array('count' => 0, 'errors' => array());
        }

        $gemini  = new GeminiOCR($this->db);
        $staging = new GeminvoiceStaging($this->db);

        foreach ($uploaded as $file_info) {
            $original_name = dol_sanitizeFileName($file_info['name']);
            $tmp_path      = $file_info['tmp_name'];
            $mime          = $this->detectMime($tmp_path, $file_info['type']);

            // Security: reject files exceeding size limit
            if ($file_info['size'] > self::MAX_FILE_SIZE) {
                $errors[] = $original_name . ': fichier trop volumineux (max ' . (self::MAX_FILE_SIZE / 1048576) . ' Mo)';
                dol_syslog('Geminvoice UploadSource: rejeté — ' . $original_name . ' taille=' . $file_info['size'], LOG_WARNING);
                continue;
            }

            // Security: reject unsupported MIME types
            if (!in_array($mime, self::$ALLOWED_MIME, true)) {
                $errors[] = $original_name . ': type de fichier non supporté (' . $mime . ')';
                dol_syslog('Geminvoice UploadSource: rejeté — ' . $original_name . ' MIME=' . $mime, LOG_WARNING);
                continue;
            }

            $dest_path = $temp_dir . '/' . uniqid('upload_') . '_' . $original_name;

            if (!move_uploaded_file($tmp_path, $dest_path)) {
                $errors[] = $original_name . ': impossible de déplacer le fichier temporaire';
                dol_syslog('Geminvoice UploadSource: move_uploaded_file échoué — ' . $original_name, LOG_ERR);
                continue;
            }

            $extraction = $gemini->analyzeInvoice($dest_path, $mime);

            if ($extraction && !empty($extraction['vendor_name'])) {
                // Use filename as gdrive_file_id equivalent (unique per upload)
                $file_token = 'upload_' . md5($dest_path . microtime());
                $staging_id = $staging->create($file_token, $original_name, $dest_path, $extraction, GeminvoiceStaging::STATUS_PENDING, '', 'upload');
                if ($staging_id > 0) {
                    dol_syslog('Geminvoice UploadSource: OK — ' . $original_name . ' → Staging ID=' . $staging_id, LOG_INFO);
                    $count_ok++;
                } else {
                    $errors[] = $original_name . ': erreur staging (' . $staging->error . ')';
                    dol_syslog('Geminvoice UploadSource: erreur staging — ' . $original_name . ': ' . $staging->error, LOG_ERR);
                }
            } else {
                $error_msg  = 'Erreur analyse IA (' . $gemini->error . ')';
                $file_token = 'upload_' . md5($dest_path . microtime());
                $staging->create($file_token, $original_name, $dest_path, array(), GeminvoiceStaging::STATUS_ERROR, $error_msg, 'upload');
                $errors[] = $original_name . ': ' . $error_msg;
                dol_syslog('Geminvoice UploadSource: ERREUR — ' . $original_name . ': ' . $error_msg, LOG_ERR);
            }
        }

        return array('count' => $count_ok, 'errors' => $errors);
    }

    /**
     * Normalise the $_FILES superglobal for a multi-file input into a flat list.
     *
     * @param  string         $input_name  Name attribute of the <input type="file" multiple>
     * @return array<array>                List of ['name', 'tmp_name', 'type', 'error', 'size']
     */
    private function normalizeUploadedFiles(string $input_name): array
    {
        if (empty($_FILES[$input_name])) {
            return array();
        }

        $raw  = $_FILES[$input_name];
        $list = array();

        // PHP wraps multiple files as arrays of values; single file is a flat array
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

    /**
     * Detect actual MIME type from file content (finfo), falling back to browser-reported type.
     *
     * @param  string $path         Absolute path to the file
     * @param  string $browser_type MIME type reported by the browser
     * @return string               Detected MIME type
     */
    private function detectMime(string $path, string $browser_type): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detected = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($detected) {
                return $detected;
            }
        }
        return $browser_type;
    }
}
