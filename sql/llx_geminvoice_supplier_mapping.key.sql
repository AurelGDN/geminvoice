-- ============================================================
-- Indexes for table: llx_geminvoice_supplier_mapping
-- ============================================================

ALTER TABLE llx_geminvoice_supplier_mapping ADD UNIQUE INDEX uk_vendor_entity (vendor_name, entity);
