-- Assinatura digital Autentique (MP 2.200-2 / validade jurídica eletrônica)
ALTER TABLE documentos
  ADD COLUMN IF NOT EXISTS portal_token CHAR(64) NULL AFTER codigo_verificacao,
  ADD COLUMN IF NOT EXISTS autentique_document_id VARCHAR(64) NULL AFTER portal_token,
  ADD COLUMN IF NOT EXISTS autentique_signature_id VARCHAR(64) NULL AFTER autentique_document_id,
  ADD COLUMN IF NOT EXISTS autentique_link VARCHAR(500) NULL AFTER autentique_signature_id,
  ADD COLUMN IF NOT EXISTS autentique_status VARCHAR(40) NULL AFTER autentique_link,
  ADD COLUMN IF NOT EXISTS autentique_enviado_em DATETIME NULL AFTER autentique_status,
  ADD COLUMN IF NOT EXISTS autentique_assinado_em DATETIME NULL AFTER autentique_enviado_em;

CREATE UNIQUE INDEX IF NOT EXISTS uk_documentos_portal_token ON documentos (portal_token);
