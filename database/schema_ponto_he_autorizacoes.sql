-- Autorizações de HE anexadas pelo RH no cartão de ponto (comprovante SharePoint).
CREATE TABLE IF NOT EXISTS ponto_he_autorizacoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  espelho_id BIGINT UNSIGNED NOT NULL,
  ponto_dia_id BIGINT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  minutos_he INT UNSIGNED NOT NULL DEFAULT 0,
  arquivo_path VARCHAR(500) NOT NULL,
  mime VARCHAR(120) NULL,
  original_name VARCHAR(255) NULL,
  justificativa_texto TEXT NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_he_auth_dia (ponto_dia_id),
  KEY idx_he_auth_espelho (espelho_id),
  KEY idx_he_auth_data (data),
  CONSTRAINT fk_he_auth_espelho FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id) ON DELETE CASCADE,
  CONSTRAINT fk_he_auth_dia FOREIGN KEY (ponto_dia_id) REFERENCES ponto_dias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
