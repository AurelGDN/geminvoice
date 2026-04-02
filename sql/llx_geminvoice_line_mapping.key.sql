-- ============================================================
-- Indexes for table: llx_geminvoice_line_mapping
-- ============================================================

ALTER TABLE llx_geminvoice_line_mapping ADD UNIQUE INDEX uk_keyword_entity (keyword, entity);
ALTER TABLE llx_geminvoice_line_mapping ADD INDEX idx_fk_product (fk_product);
