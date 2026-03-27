-- ============================================================
-- Migration 002 — Geminvoice
-- Introduced: Alpha14 (source), Alpha15 (error_message)
-- Description: Add source and error_message columns to llx_geminvoice_staging.
--              source      : invoice origin ('gdrive', 'upload', 'facturx')
--              error_message: last OCR/mapping error detail for STATUS_ERROR rows
--              Safe to run multiple times (errors on existing column are tolerated)
-- ============================================================

ALTER TABLE llx_geminvoice_staging
    ADD COLUMN source        VARCHAR(32)  NOT NULL DEFAULT 'gdrive' AFTER entity,
    ADD COLUMN error_message TEXT         DEFAULT NULL AFTER status;
