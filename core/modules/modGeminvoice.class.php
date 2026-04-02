<?php
/**
 *  \file       modGeminvoice.class.php
 *  \ingroup    geminvoice
 *  \brief      Descriptor file for module Geminvoice
 */

include_once DOL_DOCUMENT_ROOT .'/core/modules/DolibarrModules.class.php';

/**
 *  Class to describe and enable module Geminvoice
 */
class modGeminvoice extends DolibarrModules
{
    /**
     *   Constructor. Define properties like name, version, lists of tables, menus, permissions, etc.
     *
     *   @param      DoliDB  $db     Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;

        $this->db = $db;

        // Id for module (must be unique).
        // 500000 - 599999 are reserved for custom modules.
        $this->numero = 505100;

        // Rights class
        $this->rights_class = 'geminvoice';

        // Family can be 'crm', 'financial', 'hr', 'projects', 'products', 'smp', 'ecm', 'technic', 'other'
        $this->family = "technic";

        // Module label
        $this->name = preg_replace('/^mod/i', '', get_class($this));

        // Module description
        $this->description = $langs->trans("ModuleGeminvoiceDesc");

        // Version
        $this->version = '1.0.0-beta1';

        // Key used in llx_const table to save module status enabled/disabled
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);

        // Where to store the module relative to the Dolibarr webroot
        $this->module_parts = array(
            'css'      => array(),
            'js'       => array(),
            'hooks'    => array('invoicesuppliercard', 'suppliercard'),
            'triggers' => array()
        );

        // Name of image file used for this module
        $this->picto = 'technic';

        // Data directories to create when module is enabled
        $this->dirs = array(
            "/geminvoice/temp",
            "/geminvoice/processed"
        );

        // Config pages
        $this->config_page_url = array("setup.php@geminvoice");

        // Dependencies
        $this->depends         = array('modFournisseur');
        $this->requiredby      = array();
        $this->conflictwith    = array();
        $this->phpmin          = array(7, 4);
        $this->need_dolibarr_version = array(10, 0);

        // Constants — deleteonunactive (index 6) set to 0 to preserve values across deactivation/reactivation
        $this->const = array(
            array('GEMINVOICE_GDRIVE_FOLDER_ID',  'chaine', '', 'ID of the Google Drive folder to watch',             0, 'current', 0),
            array('GEMINVOICE_GEMINI_API_KEY',     'chaine', '', 'API Key for Gemini Model',                           0, 'current', 0),
            array('GEMINVOICE_GEMINI_MODEL',       'chaine', '', 'Gemini model to use (e.g. gemini-1.5-flash)',        0, 'current', 0),
            array('GEMINVOICE_GDRIVE_AUTH_JSON',   'chaine', '', 'Google Service Account JSON for Drive access',       0, 'current', 0),
            array('GEMINVOICE_RECOGNITION_TEXTMATCH',           'chaine', '1',  'Enable local text matching for product recognition',              0, 'current', 0),
            array('GEMINVOICE_RECOGNITION_AI',                  'chaine', '0',  'Enable Gemini AI for product recognition (experimental)',         0, 'current', 0),
            array('GEMINVOICE_RECOGNITION_TEXTMATCH_THRESHOLD', 'chaine', '80', 'Min textmatch score (0-100) before calling Gemini AI recognition', 0, 'current', 0),
            array('GEMINVOICE_RECOGNITION_AI_MAX_CALLS',        'chaine', '3',  'Max Gemini AI recognition calls per page load (budget)',          0, 'current', 0),
            array('GEMINVOICE_DOC_STORAGE',                    'chaine', 'local_copy', 'Document storage strategy: drive_only, local_copy, both',  0, 'current', 0),
        );

        // Dictionaries
        $this->dictionaries = array();

        // Permissions
        $this->rights = array();
        $r = 0;

        $r++;
        $this->rights[$r][0] = 505101;
        $this->rights[$r][1] = $langs->trans("GeminvoicePermissionRead");
        $this->rights[$r][2] = 'r';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'read';

        $r++;
        $this->rights[$r][0] = 505102;
        $this->rights[$r][1] = $langs->trans("GeminvoicePermissionWrite");
        $this->rights[$r][2] = 'w';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'write';

        // Menus
        $this->menu = array();
        $r = 0;

        // Parent menu: Geminvoice
        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=billing,fk_leftmenu=suppliers_bills',
            'type'     => 'left',
            'titre'    => 'Geminvoice',
            'mainmenu' => 'billing',
            'leftmenu' => 'geminvoice',
            'url'      => '/geminvoice/index.php',
            'langs'    => 'geminvoice@geminvoice',
            'position' => 1000,
            'enabled'  => '$conf->geminvoice->enabled',
            'perms'    => '$user->rights->geminvoice->read',
            'target'   => '',
            'user'     => 2
        );

        $r++;
        // Sub-menu: Factures en attente
        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=billing,fk_leftmenu=geminvoice',
            'type'     => 'left',
            'titre'    => $langs->trans("GeminvoicePendingInvoicesMenu"),
            'mainmenu' => 'billing',
            'leftmenu' => 'geminvoice_pending',
            'url'      => '/geminvoice/index.php',
            'langs'    => 'geminvoice@geminvoice',
            'position' => 1001,
            'enabled'  => '$conf->geminvoice->enabled',
            'perms'    => '$user->rights->geminvoice->read',
            'target'   => '',
            'user'     => 2
        );

        $r++;
        // Sub-menu: Gestion des Mappings
        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=billing,fk_leftmenu=geminvoice',
            'type'     => 'left',
            'titre'    => $langs->trans("GeminvoiceMappingsMenu"),
            'mainmenu' => 'billing',
            'leftmenu' => 'geminvoice_mappings',
            'url'      => '/geminvoice/mappings.php',
            'langs'    => 'geminvoice@geminvoice',
            'position' => 1002,
            'enabled'  => '$conf->geminvoice->enabled',
            'perms'    => '$user->rights->geminvoice->read',
            'target'   => '',
            'user'     => 2
        );
        $r++;
        // Sub-menu: Documentation
        $this->menu[$r] = array(
            'fk_menu'  => 'fk_mainmenu=billing,fk_leftmenu=geminvoice',
            'type'     => 'left',
            'titre'    => $langs->trans("GeminvoiceDocsMenu"),
            'mainmenu' => 'billing',
            'leftmenu' => 'geminvoice_docs',
            'url'      => '/geminvoice/docs.php',
            'langs'    => 'geminvoice@geminvoice',
            'position' => 1003,
            'enabled'  => '$conf->geminvoice->enabled',
            'perms'    => '$user->rights->geminvoice->read',
            'target'   => '',
            'user'     => 2
        );

        // Cron jobs — registered in llx_cronjobs when the module is activated
        $this->cronjobs = array(
            array(
                'label'         => $langs->trans("GeminvoiceCronSync"),
                'jobtype'       => 'method',
                'class'         => '/geminvoice/class/cron.class.php',
                'objectname'    => 'GeminvoiceCron',
                'method'        => 'runSync',
                'parameters'    => '',
                'comment'       => $langs->trans("GeminvoiceCronSyncDesc"),
                'frequency'     => 1,
                'unitfrequency' => 3600,   // toutes les heures
                'priority'      => 50,
                'status'        => 0,      // désactivé par défaut, à activer manuellement
                'test'          => 'isModEnabled("geminvoice")',
            ),
        );
    }

    /**
     *  Function called when module is enabled.
     *  Creates tables, adds constants, boxes, permissions and menus.
     *
     *  @param      string  $options    Options when enabling module ('', 'noboxes')
     *  @return     int                 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $sql = array();
        
        // Let Dolibarr natively parse and execute the .sql files located in the sql/ directory
        // Removing file_get_contents() as it causes silent syntax errors with multi-line statements and comments
        
        // Alpha12 migration (ignoreerror: column/key may already exist on upgrades)
        $sql[] = array('sql' => "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_line_mapping ADD COLUMN fk_product INT DEFAULT NULL AFTER vat_rate", 'ignoreerror' => 1);
        $sql[] = array('sql' => "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_line_mapping ADD KEY idx_fk_product (fk_product)", 'ignoreerror' => 1);
        // Alpha15 migration — error tracking
        $sql[] = array('sql' => "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_staging ADD COLUMN error_message TEXT DEFAULT NULL AFTER status", 'ignoreerror' => 1);
        // Alpha16 migration — multi-source support
        $sql[] = array('sql' => "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_staging ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'gdrive' AFTER entity", 'ignoreerror' => 1);
        // Alpha18 migration — duplicate warning at staging time
        $sql[] = array('sql' => "ALTER TABLE " . MAIN_DB_PREFIX . "geminvoice_staging ADD COLUMN duplicate_warning VARCHAR(255) DEFAULT NULL AFTER error_message", 'ignoreerror' => 1);
        
        $result = $this->_init($sql, $options);
        return $result;
    }

    /**
     *  Function called when module is disabled.
     *  Data directories are not deleted.
     *
     *  @param      string  $options    Options when disabling module ('', 'noboxes')
     *  @return     int                 1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
