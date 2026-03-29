-- ============================================================
-- Migration 003 — Geminvoice
-- Introduced: Alpha18
-- Description: Add duplicate_warning column to llx_geminvoice_staging.
--              Stores the ref of a Dolibarr invoice detected as a potential
--              duplicate at staging time (NULL = no duplicate detected).
--              Safe to run multiple times.
-- ============================================================

ALTER TABLE llx_geminvoice_staging
    ADD COLUMN duplicate_warning VARCHAR(255) DEFAULT NULL AFTER error_message;
