<?php
/**
 *  \file       class/gdrive.class.php
 *  \ingroup    geminvoice
 *  \brief      Class to manage Google Drive API connections
 */

class GDriveSync
{
    private $db;
    private $client;
    private $service;
    private $folder_id;
    public $error;

    /**
     * Constructor
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf;
        $this->db = $db;
        $this->error = '';
        $this->client = null;
        $this->service = null;
        
        $this->folder_id = !empty($conf->global->GEMINVOICE_GDRIVE_FOLDER_ID) ? $conf->global->GEMINVOICE_GDRIVE_FOLDER_ID : '';

        // Try to load Composer autoload from various standard locations
        $autoloadSources = array(
            __DIR__ . '/../vendor/autoload.php',                                  // Inside the module (geminvoice/vendor/autoload.php)
            __DIR__ . '/../../vendor/autoload.php',                               // Inside custom/ (custom/vendor/autoload.php)
            DOL_DOCUMENT_ROOT . '/custom/vendor/autoload.php',                    // Explicit custom/ directory
            DOL_DOCUMENT_ROOT . '/vendor/autoload.php',                           // Official Dolibarr root
        );

        foreach ($autoloadSources as $path) {
            if (file_exists($path)) {
                require_once $path;
                break;
            }
        }

        if (class_exists('Google\Client')) {
            dol_syslog("Geminvoice: Google Client class found", LOG_DEBUG);
            $this->client = new \Google\Client();
            $this->client->setApplicationName('Geminvoice Dolibarr Sync');
            $this->client->setScopes([\Google\Service\Drive::DRIVE]);
            
            $jsonAuthStr = !empty($conf->global->GEMINVOICE_GDRIVE_AUTH_JSON) ? $conf->global->GEMINVOICE_GDRIVE_AUTH_JSON : '';
            dol_syslog("Geminvoice: JSON Auth length: " . strlen($jsonAuthStr), LOG_DEBUG);

            if (!empty($jsonAuthStr)) {
                // The JSON is stored via GETPOST($param, 'none') and displayed via htmlspecialchars(ENT_NOQUOTES).
                // A direct decode should always work. The html_entity_decode fallback handles any legacy
                // records that may have been stored with encoded entities before the form was fixed.
                $authConfig = json_decode($jsonAuthStr, true);
                if (!$authConfig) {
                    dol_syslog("Geminvoice: json_decode failed (" . json_last_error_msg() . "). Trying HTML entity decode as fallback for legacy records.", LOG_DEBUG);
                    $authConfig = json_decode(html_entity_decode($jsonAuthStr, ENT_QUOTES, 'UTF-8'), true);
                }

                if ($authConfig) {
                    dol_syslog("Geminvoice: JSON decoded successfully", LOG_DEBUG);
                    $this->client->setAuthConfig($authConfig);
                    $this->service = new \Google\Service\Drive($this->client);
                } else {
                    $this->error = "Le format du JSON du Compte de Service est invalide. Erreur: " . json_last_error_msg() . ". Re-collez le JSON depuis le fichier .json directement dans la configuration.";
                }
            } else {
                $this->error = "Aucune configuration de Compte de Service (JSON) fournie.";
            }
        } else {
            $this->error = "La librairie google/apiclient n'est pas installée.";
        }

        if (!empty($this->error)) {
            dol_syslog("Geminvoice: Init GDrive error: " . $this->error, LOG_ERR);
        }
    }

    /**
     * Retrieve a list of unread invoices from the Drive Folder
     * 
     * @return array Array of file metadata (id, name, mimeType, downloadUrl)
     */
    public function getUnprocessedInvoices()
    {
        if (!empty($this->error)) {
            dol_syslog("Geminvoice: getUnprocessedInvoices aborted: " . $this->error, LOG_ERR);
            return false;
        }

        if (empty($this->folder_id) || !$this->service) {
            dol_syslog("Geminvoice: Google Drive Folder ID (".$this->folder_id.") or Service Account is not configured.", LOG_ERR);
            return false;
        }

        try {
            dol_syslog("Geminvoice: Searching for files in folder: " . $this->folder_id, LOG_DEBUG);
            // Find all files in the target folder that are not folders themselves
            $query = sprintf("'%s' in parents and mimeType != 'application/vnd.google-apps.folder' and trashed = false", $this->folder_id);
            $optParams = array(
                'q'         => $query,
                'fields'    => 'nextPageToken, files(id, name, mimeType)',
                'pageSize'  => 100,
            );

            $files_found = array();

            // Paginate through all results using nextPageToken
            do {
                $results    = $this->service->files->listFiles($optParams);
                $page_files = $results->getFiles();

                dol_syslog("Geminvoice: listFiles page — " . count($page_files) . " item(s)", LOG_DEBUG);

                foreach ($page_files as $file) {
                    dol_syslog("Geminvoice: Found file candidate: " . $file->getName() . " (id: " . $file->getId() . ")", LOG_DEBUG);
                    $files_found[] = array(
                        'id'       => $file->getId(),
                        'name'     => $file->getName(),
                        'mimeType' => $file->getMimeType(),
                    );
                }

                $optParams['pageToken'] = $results->getNextPageToken();
            } while (!empty($optParams['pageToken']));

            dol_syslog("Geminvoice: listFiles total — " . count($files_found) . " file(s) found", LOG_DEBUG);

            return $files_found;
        } catch (Exception $e) {
            $this->error = "Erreur listFiles: " . $e->getMessage();
            dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Download a file from Google Drive to a local temporary path
     * 
     * @param string $file_id The Google Drive File ID
     * @param string $dest_path The local absolute path to save the file
     * @return bool True on success
     */
    public function downloadInvoice($file_id, $dest_path)
    {
        if (!$this->service) return false;

        try {
            $response = $this->service->files->get($file_id, array('alt' => 'media'));
            $content = $response->getBody()->getContents();
            
            if (!empty($content)) {
                $bytes = file_put_contents($dest_path, $content);
                return ($bytes !== false);
            }
            return false;
        } catch (Exception $e) {
            $this->error = "Erreur downloadInvoice: " . $e->getMessage();
            dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
            return false;
        }
    }

    /**
     * Move the processed file to a "Processed" subfolder
     * 
     * @param string $file_id The Google Drive File ID
     * @return bool True on success
     */
    public function markAsProcessed($file_id)
    {
        if (!$this->service) return false;

        try {
            // Find the Processed folder ID
            $query = sprintf("name = 'processed' and '%s' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false", $this->folder_id);
            $optParams = array('q' => $query, 'fields' => 'files(id, name)');
            $results = $this->service->files->listFiles($optParams);
            $processed_folder_id = null;

            if (count($results->getFiles()) > 0) {
                $processed_folder_id = $results->getFiles()[0]->getId();
            } else {
                // Create it if not exists
                $fileMetadata = new \Google\Service\Drive\DriveFile(array(
                    'name' => 'processed',
                    'parents' => array($this->folder_id),
                    'mimeType' => 'application/vnd.google-apps.folder'
                ));
                $folder = $this->service->files->create($fileMetadata, array('fields' => 'id'));
                $processed_folder_id = $folder->id;
            }

            // Move the file
            $emptyFile = new \Google\Service\Drive\DriveFile();
            // Retrieve the existing parents to remove
            $file = $this->service->files->get($file_id, array('fields' => 'parents'));
            $previousParents = implode(',', $file->parents);
            
            $this->service->files->update($file_id, $emptyFile, array(
                'addParents' => $processed_folder_id,
                'removeParents' => $previousParents,
                'fields' => 'id, parents'
            ));

            return true;
        } catch (Exception $e) {
            $this->error = "Erreur markAsProcessed: " . $e->getMessage();
            dol_syslog("Geminvoice: " . $this->error, LOG_ERR);
            return false;
        }
    }
}
