<?php
/**
 *  \file       class/sources/GdriveSource.class.php
 *  \ingroup    geminvoice
 *  \brief      Google Drive invoice source — implements GeminvoiceSourceInterface (Alpha16)
 *
 *  Encapsulates the GDrive → Gemini OCR → Staging pipeline.
 *  The lock mechanism stays in cron.class.php; this class is lock-agnostic and
 *  can also be called directly from the manual sync button in the dashboard.
 */

dol_include_once('/geminvoice/class/sources/GeminvoiceSourceInterface.php');
dol_include_once('/geminvoice/class/gdrive.class.php');
dol_include_once('/geminvoice/class/gemini.class.php');
dol_include_once('/geminvoice/class/staging.class.php');

class GdriveSource implements GeminvoiceSourceInterface
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

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'gdrive';
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel(): string
    {
        global $langs;
        return $langs->trans('GeminvoiceSourceGdrive');
    }

    /**
     * {@inheritdoc}
     */
    public function getIcon(): string
    {
        return 'fa-brands fa-google-drive';
    }

    /**
     * {@inheritdoc}
     * Requires GEMINVOICE_GDRIVE_FOLDER_ID, GEMINVOICE_GDRIVE_AUTH_JSON and GEMINVOICE_GEMINI_API_KEY.
     */
    public function isConfigured(): bool
    {
        global $conf;
        return !empty($conf->global->GEMINVOICE_GDRIVE_FOLDER_ID)
            && !empty($conf->global->GEMINVOICE_GDRIVE_AUTH_JSON)
            && !empty($conf->global->GEMINVOICE_GEMINI_API_KEY);
    }

    /**
     * {@inheritdoc}
     * GDrive source is enabled whenever it is configured (no separate toggle needed for now).
     */
    public function isEnabled(): bool
    {
        return $this->isConfigured();
    }

    /**
     * {@inheritdoc}
     *
     * Fetches unprocessed invoices from Google Drive, runs Gemini OCR on each,
     * and persists results in llx_geminvoice_staging with source='gdrive'.
     *
     * @return array{count: int, errors: array<string>}
     */
    public function fetchAndStage(): array
    {
        global $conf;

        $count_ok  = 0;
        $errors    = array();
        $temp_dir  = DOL_DATA_ROOT . '/geminvoice/temp';

        if (!is_dir($temp_dir)) {
            dol_mkdir($temp_dir);
        }

        $gdrive  = new GDriveSync($this->db);
        $gemini  = new GeminiOCR($this->db);
        $staging = new GeminvoiceStaging($this->db);

        if (!empty($gdrive->error)) {
            $errors[] = 'Initialisation Google Drive échouée: ' . $gdrive->error;
            dol_syslog('Geminvoice GdriveSource: ' . $errors[0], LOG_ERR);
            return array('count' => 0, 'errors' => $errors);
        }

        $files = $gdrive->getUnprocessedInvoices();

        if ($files === false) {
            $errors[] = 'Impossible de lister les fichiers Google Drive: ' . $gdrive->error;
            dol_syslog('Geminvoice GdriveSource: ' . $errors[0], LOG_ERR);
            return array('count' => 0, 'errors' => $errors);
        }

        if (empty($files)) {
            dol_syslog('Geminvoice GdriveSource: aucun nouveau fichier à traiter.', LOG_DEBUG);
            return array('count' => 0, 'errors' => array());
        }

        dol_syslog('Geminvoice GdriveSource: ' . count($files) . ' fichier(s) trouvé(s).', LOG_INFO);

        foreach ($files as $file) {
            $local_path = $temp_dir . '/' . dol_sanitizeFileName($file['name']);
            $error_msg  = '';

            if ($gdrive->downloadInvoice($file['id'], $local_path)) {
                $extraction = $gemini->analyzeInvoice($local_path, $file['mimeType']);

                if ($extraction && !empty($extraction['vendor_name'])) {
                    $staging_id = $staging->create($file['id'], $file['name'], $local_path, $extraction, GeminvoiceStaging::STATUS_PENDING, '', 'gdrive');
                    if ($staging_id > 0) {
                        $invoice_date = !empty($extraction['date']) ? $extraction['date'] : null;
                        $gdrive->markAsProcessed($file['id'], $invoice_date);
                        dol_syslog('Geminvoice GdriveSource: OK — ' . $file['name'] . ' → Staging ID=' . $staging_id, LOG_INFO);
                        $count_ok++;
                    } else {
                        $error_msg = 'Erreur staging (' . $staging->error . ')';
                    }
                } else {
                    $error_msg = 'Erreur analyse IA (' . $gemini->error . ')';
                }
            } else {
                $error_msg = 'Erreur téléchargement (' . $gdrive->error . ')';
            }

            if ($error_msg) {
                // Create an error staging record for UI visibility, but do NOT move the file in Drive.
                // Leaving the file in place allows the next sync to retry (transient errors: API timeout, rate limit…).
                $staging->create($file['id'], $file['name'], $local_path, array(), GeminvoiceStaging::STATUS_ERROR, $error_msg, 'gdrive');
                dol_syslog('Geminvoice GdriveSource: ERREUR — ' . $file['name'] . ' : ' . $error_msg . ' (fichier conservé pour retry)', LOG_ERR);
                $errors[] = $file['name'] . ': ' . $error_msg;
            }
        }

        return array('count' => $count_ok, 'errors' => $errors);
    }
}
