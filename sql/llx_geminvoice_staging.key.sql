-- ============================================================
-- Indexes for table: llx_geminvoice_staging
-- ============================================================

ALTER TABLE llx_geminvoice_staging ADD INDEX idx_staging_status (status);
ALTER TABLE llx_geminvoice_staging ADD INDEX idx_staging_gdrive_id (gdrive_file_id);
ALTER TABLE llx_geminvoice_staging ADD INDEX idx_staging_entity (entity);
