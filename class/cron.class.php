<?php
/**
 *  \file       class/cron.class.php
 *  \ingroup    geminvoice
 *  \brief      Cron wrapper for Dolibarr task scheduler (Alpha11)
 *              Exposes runSync() as a Dolibarr cron method (jobtype=method).
 *              Also called by scripts/geminvoice_sync.php for CLI/server cron use.
 */

dol_include_once('/geminvoice/class/sources/GdriveSource.class.php');

class GeminvoiceCron
{
    /**
     * Maximum age of the lock file in seconds before it is considered stale.
     * A sync should never take longer than 2 hours; beyond that the process is assumed dead.
     */
    const LOCK_MAX_AGE = 7200;

    /**
     * Run the Google Drive → Gemini → Staging synchronization.
     *
     * Uses an exclusive file lock (flock) to prevent concurrent runs if the
     * Dolibarr cron or a server cron fires while a previous sync is still running.
     * A TTL of LOCK_MAX_AGE seconds breaks any stale lock left by a dead process
     * on filesystems where flock() advisory locks are not auto-released (e.g. NFS).
     *
     * @param  string $params  Unused; required by Dolibarr cron interface
     * @return int|string      0 on success, error message string on failure
     */
    public function runSync($params = '')
    {
        global $db, $langs;

        $temp_dir  = DOL_DATA_ROOT . '/geminvoice/temp';
        $lock_file = $temp_dir . '/sync.lock';

        // Ensure temp directory exists
        if (!is_dir($temp_dir)) {
            dol_mkdir($temp_dir);
        }

        // --- TTL safety: remove lock file if older than LOCK_MAX_AGE ---
        if (file_exists($lock_file)) {
            $mtime = filemtime($lock_file);
            if ($mtime !== false && (time() - $mtime) > self::LOCK_MAX_AGE) {
                @unlink($lock_file);
                dol_syslog(
                    "Geminvoice: verrou périmé supprimé (âge > " . (self::LOCK_MAX_AGE / 3600) . "h). "
                    . "Le process précédent s'est probablement terminé anormalement.",
                    LOG_WARNING
                );
            }
        }

        // --- Acquire exclusive non-blocking lock ---
        $lock_handle = @fopen($lock_file, 'w');
        if (!$lock_handle) {
            dol_syslog("Geminvoice: runSync() impossible d'ouvrir le fichier verrou: " . $lock_file, LOG_WARNING);
            return "Impossible d'ouvrir le fichier verrou: " . $lock_file;
        }

        if (!flock($lock_handle, LOCK_EX | LOCK_NB)) {
            // Another process holds the lock: skip silently, this is not an error
            fclose($lock_handle);
            dol_syslog("Geminvoice: runSync() déjà en cours d'exécution (verrou actif). Passage ignoré.", LOG_WARNING);
            return 0;
        }

        // Write PID + start time to lock file for diagnosis
        fwrite($lock_handle, getmypid() . "\n" . date('c') . "\n");

        dol_syslog("Geminvoice: runSync() démarré (PID=" . getmypid() . ")", LOG_INFO);

        $count_ok  = 0;
        $count_err = 0;

        try {
            $source = new GdriveSource($db);
            $result = $source->fetchAndStage();
            $count_ok  = $result['count'];
            $count_err = count($result['errors']);
        } catch (Throwable $e) {
            $msg = "Erreur fatale: " . $e->getMessage() . " dans " . $e->getFile() . " ligne " . $e->getLine();
            dol_syslog("Geminvoice: runSync() " . $msg, LOG_ERR);
            flock($lock_handle, LOCK_UN);
            fclose($lock_handle);
            return $msg;
        }

        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);

        dol_syslog("Geminvoice: runSync() terminé. OK=" . $count_ok . " ERR=" . $count_err, LOG_INFO);

        // Dolibarr cron expects 0 on success, a non-empty string on failure
        if ($count_err > 0) {
            return $count_err . " fichier(s) en erreur sur " . ($count_ok + $count_err) . ". Voir le tableau de bord Geminvoice.";
        }
        return 0;
    }
}
