-- Horários no modelo RHiD (Control iD): semanal ou cíclico, com batidas por dia.
-- Complementa (e passa a alimentar) jornada/escala legadas via rh_lotacoes.horario_id.

CREATE TABLE IF NOT EXISTS rh_horarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(160) NOT NULL,
  tipo ENUM('semanal','ciclico') NOT NULL DEFAULT 'semanal',
  dias_ciclo SMALLINT UNSIGNED NULL COMMENT 'Só cíclico: tamanho do ciclo (ex. 2=12x36)',
  trabalha_feriado TINYINT(1) NOT NULL DEFAULT 0,
  adicional_noturno TINYINT(1) NOT NULL DEFAULT 0,
  almoco_livre TINYINT(1) NOT NULL DEFAULT 0,
  compensado TINYINT(1) NOT NULL DEFAULT 0,
  neutro TINYINT(1) NOT NULL DEFAULT 0,
  percentual_he SMALLINT UNSIGNED NULL,
  jornada_origem_id BIGINT UNSIGNED NULL,
  escala_origem_id BIGINT UNSIGNED NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_rh_horarios_codigo (codigo),
  KEY idx_rh_horarios_origem (jornada_origem_id, escala_origem_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rh_horario_dias (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  horario_id BIGINT UNSIGNED NOT NULL,
  indice SMALLINT UNSIGNED NOT NULL COMMENT 'Semanal: 1=seg..7=dom; Cíclico: 1..N',
  tipo_dia ENUM('trabalho','folga') NOT NULL DEFAULT 'trabalho',
  ent1 TIME NULL,
  sai1 TIME NULL,
  ent2 TIME NULL,
  sai2 TIME NULL,
  UNIQUE KEY uk_rh_horario_dia (horario_id, indice),
  CONSTRAINT fk_rh_horario_dias_horario
    FOREIGN KEY (horario_id) REFERENCES rh_horarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
