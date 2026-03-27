-- ============================================================
-- Migration 001 — Geminvoice
-- Introduced: Alpha12
-- Description: Add fk_product column to llx_geminvoice_line_mapping
--              Safe to run multiple times (errors on existing column are tolerated)
-- ============================================================

ALTER TABLE llx_geminvoice_line_mapping
    ADD COLUMN fk_product INT DEFAULT NULL AFTER vat_rate,
    ADD KEY idx_fk_product (fk_product);
