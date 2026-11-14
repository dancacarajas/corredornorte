-- Vias e assinaturas eletrônicas dos relatórios de atividades.
CREATE TABLE IF NOT EXISTS ra_documentos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  relatorio_id BIGINT UNSIGNED NOT NULL,
  codigo_verificacao CHAR(32) NOT NULL,
  portal_token VARCHAR(64) NOT NULL,
  arquivo_path VARCHAR(255) NOT NULL,
  hash_sha256 CHAR(64) NOT NULL,
  arquivo_assinado_path VARCHAR(255) NULL,
  arquivo_assinado_sha256 CHAR(64) NULL,
  assinatura_nome VARCHAR(160) NULL,
  assinatura_ip VARCHAR(64) NULL,
  assinatura_user_agent VARCHAR(255) NULL,
  assinatura_em DATETIME NULL,
  assinatura_aceite TINYINT(1) NULL,
  assinatura_texto_aceite TEXT NULL,
  status ENUM('enviado','assinado','invalidado') NOT NULL DEFAULT 'enviado',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ra_documentos_codigo (codigo_verificacao),
  UNIQUE KEY uk_ra_documentos_token (portal_token),
  KEY idx_ra_documentos_relatorio (relatorio_id, id),
  CONSTRAINT fk_ra_documentos_relatorio FOREIGN KEY (relatorio_id)
    REFERENCES ra_relatorios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
