-- Relatório de HE: saldos pendentes preenchíveis (meses anteriores)
CREATE TABLE IF NOT EXISTS he_saldos_pendentes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colaborador_id BIGINT UNSIGNED NOT NULL,
  competencia CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  adn_min INT NOT NULL DEFAULT 0,
  he_diurno_50_min INT NOT NULL DEFAULT 0,
  he_diurno_60_min INT NOT NULL DEFAULT 0,
  he_diurno_100_min INT NOT NULL DEFAULT 0,
  he_noturno_50_min INT NOT NULL DEFAULT 0,
  he_noturno_60_min INT NOT NULL DEFAULT 0,
  he_noturno_100_min INT NOT NULL DEFAULT 0,
  observacao VARCHAR(255) NULL,
  usuario_id BIGINT UNSIGNED NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_he_pend_colab_comp (colaborador_id, competencia),
  INDEX idx_he_pend_comp (competencia),
  CONSTRAINT fk_he_pend_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
