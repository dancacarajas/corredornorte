-- Relatório de Atividades + Vale Refeição
CREATE TABLE IF NOT EXISTS ra_relatorios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colaborador_id BIGINT UNSIGNED NOT NULL,
  periodo_id BIGINT UNSIGNED NOT NULL,
  espelho_id BIGINT UNSIGNED NULL,
  ordem_servico_id BIGINT UNSIGNED NULL,
  contrato_id BIGINT UNSIGNED NULL,
  gestor_nome VARCHAR(160) NULL,
  fiscal_vale VARCHAR(160) NULL,
  status ENUM('rascunho','enviado','aprovado') NOT NULL DEFAULT 'rascunho',
  enviado_em DATETIME NULL,
  aprovado_em DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ra_colab_periodo (colaborador_id, periodo_id),
  KEY idx_ra_status (status),
  KEY idx_ra_periodo (periodo_id),
  CONSTRAINT fk_ra_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id),
  CONSTRAINT fk_ra_periodo FOREIGN KEY (periodo_id) REFERENCES ponto_periodos(id),
  CONSTRAINT fk_ra_espelho FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id) ON DELETE SET NULL,
  CONSTRAINT fk_ra_os FOREIGN KEY (ordem_servico_id) REFERENCES rh_ordens_servico(id),
  CONSTRAINT fk_ra_contrato FOREIGN KEY (contrato_id) REFERENCES rh_contratos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ra_dias (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  relatorio_id BIGINT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  descricao TEXT NULL,
  area_atendimento VARCHAR(255) NULL,
  municipio VARCHAR(160) NULL,
  projeto_obra VARCHAR(255) NULL,
  centro_custo VARCHAR(80) NULL,
  area_classificada TINYINT(1) NOT NULL DEFAULT 0,
  ambiente ENUM('remoto','site','viagem') NULL,
  observacoes VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ra_dia (relatorio_id, data),
  KEY idx_ra_dias_ambiente (ambiente),
  CONSTRAINT fk_ra_dia_rel FOREIGN KEY (relatorio_id) REFERENCES ra_relatorios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vr_config (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  valor_dia DECIMAL(10,2) NOT NULL,
  tipo ENUM('dias_trabalhados','dias_uteis') NOT NULL DEFAULT 'dias_trabalhados',
  vigencia_inicio DATE NOT NULL,
  vigencia_fim DATE NULL,
  todos_contratos TINYINT(1) NOT NULL DEFAULT 1,
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_vr_vigencia (vigencia_inicio, vigencia_fim, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vr_config_contratos (
  config_id BIGINT UNSIGNED NOT NULL,
  contrato_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (config_id, contrato_id),
  KEY idx_vr_cc_contrato (contrato_id),
  CONSTRAINT fk_vr_cc_config FOREIGN KEY (config_id) REFERENCES vr_config(id) ON DELETE CASCADE,
  CONSTRAINT fk_vr_cc_contrato FOREIGN KEY (contrato_id) REFERENCES rh_contratos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
