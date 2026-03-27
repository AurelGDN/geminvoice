<?php
/**
 *  \file       class/sources/GeminvoiceSourceInterface.php
 *  \ingroup    geminvoice
 *  \brief      Contract that every invoice input source must implement (Alpha16)
 *
 *  Each source is responsible for fetching invoice documents from its origin,
 *  running OCR/parsing if needed, and persisting records in llx_geminvoice_staging
 *  via GeminvoiceStaging::create() with its own source identifier.
 *
 *  Result array returned by fetchAndStage():
 *  [
 *    'count'  => int,    // number of new staging records created
 *    'errors' => array,  // list of error strings (may be empty)
 *  ]
 */

interface GeminvoiceSourceInterface
{
    /**
     * Unique machine identifier stored in llx_geminvoice_staging.source.
     * Must be lowercase, no spaces (e.g. 'gdrive', 'upload', 'facturx').
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Human-readable label for display in the UI.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Font-Awesome or Dolibarr picto name used in dashboard tabs/icons.
     *
     * @return string
     */
    public function getIcon(): string;

    /**
     * Return true when the source has been configured (all required constants set).
     * Used by the dashboard to show a "not configured" notice instead of an error.
     *
     * @return bool
     */
    public function isConfigured(): bool;

    /**
     * Return true when the source is both configured and enabled by the administrator.
     *
     * @return bool
     */
    public function isEnabled(): bool;

    /**
     * Fetch new documents from the source, run analysis, and persist staging records.
     *
     * @return array{count: int, errors: array<string>}
     */
    public function fetchAndStage(): array;
}
