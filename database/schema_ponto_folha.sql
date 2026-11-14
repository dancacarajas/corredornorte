-- Arcadis RH — Gestão de Ponto + Folha
USE arcadis_rh;

CREATE TABLE IF NOT EXISTS ponto_periodos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  data_inicio DATE NOT NULL,
  data_fim DATE NOT NULL,
  status ENUM('aberta','em_tratamento','conferida','fechada') NOT NULL DEFAULT 'aberta',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_periodo (data_inicio, data_fim)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ponto_espelhos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  periodo_id BIGINT UNSIGNED NOT NULL,
  colaborador_id BIGINT UNSIGNED NOT NULL,
  lotacao_id BIGINT UNSIGNED NULL,
  status ENUM('em_aberto','em_tratamento','conferido','fechado','enviado','assinado','em_divergencia') NOT NULL DEFAULT 'em_aberto',
  total_he_min INT NOT NULL DEFAULT 0,
  total_falta_min INT NOT NULL DEFAULT 0,
  total_atraso_min INT NOT NULL DEFAULT 0,
  total_adn_min INT NOT NULL DEFAULT 0,
  versao INT UNSIGNED NOT NULL DEFAULT 1,
  fechado_em DATETIME NULL,
  fechado_por BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_espelho (periodo_id, colaborador_id),
  CONSTRAINT fk_esp_periodo FOREIGN KEY (periodo_id) REFERENCES ponto_periodos(id),
  CONSTRAINT fk_esp_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id),
  CONSTRAINT fk_esp_lot FOREIGN KEY (lotacao_id) REFERENCES rh_lotacoes(id),
  CONSTRAINT fk_esp_user FOREIGN KEY (fechado_por) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ponto_dias (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  espelho_id BIGINT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  tipo_dia ENUM('trabalho','folga','feriado','folga_escala') NOT NULL DEFAULT 'trabalho',
  previsto_entrada TIME NULL,
  previsto_intervalo_inicio TIME NULL,
  previsto_intervalo_fim TIME NULL,
  previsto_saida TIME NULL,
  ocorrencia_resumo VARCHAR(120) NULL,
  UNIQUE KEY uk_dia (espelho_id, data),
  CONSTRAINT fk_dia_esp FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ponto_marcacoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ponto_dia_id BIGINT UNSIGNED NOT NULL,
  sequencia TINYINT UNSIGNED NOT NULL,
  horario TIME NOT NULL,
  origem ENUM('previsto','gerado','relogio','manual','importado') NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_marc (ponto_dia_id, sequencia),
  CONSTRAINT fk_marc_dia FOREIGN KEY (ponto_dia_id) REFERENCES ponto_dias(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ponto_ocorrencias (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ponto_dia_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('falta','falta_justificada','atestado','hora_extra','atraso','saida_antecipada','adicional_noturno','trabalho_feriado','folga','outros') NOT NULL,
  minutos INT NOT NULL DEFAULT 0,
  observacao TEXT NULL,
  anexo_path VARCHAR(255) NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ocorr_dia FOREIGN KEY (ponto_dia_id) REFERENCES ponto_dias(id) ON DELETE CASCADE,
  CONSTRAINT fk_ocorr_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ponto_ajustes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  espelho_id BIGINT UNSIGNED NOT NULL,
  motivo VARCHAR(255) NOT NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_aj_esp FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id) ON DELETE CASCADE,
  CONSTRAINT fk_aj_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ponto_fechamentos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  espelho_id BIGINT UNSIGNED NOT NULL,
  status_anterior VARCHAR(40) NOT NULL,
  status_novo VARCHAR(40) NOT NULL,
  justificativa VARCHAR(255) NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fech_esp FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id) ON DELETE CASCADE,
  CONSTRAINT fk_fech_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documentos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo ENUM('folha_ponto') NOT NULL DEFAULT 'folha_ponto',
  espelho_id BIGINT UNSIGNED NOT NULL,
  codigo_verificacao CHAR(32) NOT NULL UNIQUE,
  periodo_inicio DATE NULL,
  periodo_fim DATE NULL,
  arquivo_path VARCHAR(255) NOT NULL,
  layout_pdf SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  substituido_por_id BIGINT UNSIGNED NULL,
  status ENUM('gerado','enviado','assinado','invalidado') NOT NULL DEFAULT 'gerado',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_esp FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documentos_espelhos (
  documento_id BIGINT UNSIGNED NOT NULL,
  espelho_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (documento_id, espelho_id),
  KEY idx_de_espelho (espelho_id),
  CONSTRAINT fk_de_documento FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_de_espelho FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documentos_versoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  documento_id BIGINT UNSIGNED NOT NULL,
  versao INT UNSIGNED NOT NULL,
  arquivo_path VARCHAR(255) NOT NULL,
  motivo VARCHAR(255) NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_doc_ver (documento_id, versao),
  CONSTRAINT fk_dv_doc FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_dv_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS emails_envios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  documento_id BIGINT UNSIGNED NOT NULL,
  destinatario VARCHAR(160) NOT NULL,
  status ENUM('aguardando','enviado','falha') NOT NULL DEFAULT 'aguardando',
  tentativa INT UNSIGNED NOT NULL DEFAULT 1,
  erro_mensagem VARCHAR(255) NULL,
  enviado_em DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_email_doc FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ponto_divergencias (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  espelho_id BIGINT UNSIGNED NOT NULL,
  documento_id BIGINT UNSIGNED NULL,
  ponto_dia_id BIGINT UNSIGNED NULL,
  colaborador_id BIGINT UNSIGNED NOT NULL,
  data_ocorrencia DATE NULL,
  motivo TEXT NOT NULL,
  status ENUM('aberta','em_analise','resolvida') NOT NULL DEFAULT 'aberta',
  resolucao_obs TEXT NULL,
  resolvido_por BIGINT UNSIGNED NULL,
  resolvido_em DATETIME NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pd_espelho_status (espelho_id, status),
  KEY idx_pd_status_created (status, created_at),
  CONSTRAINT fk_pd_espelho FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id) ON DELETE CASCADE,
  CONSTRAINT fk_pd_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id)
) ENGINE=InnoDB;
