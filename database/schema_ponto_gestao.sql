USE arcadis_rh;

-- Campos operacionais por dia (tela de gestão)
ALTER TABLE ponto_dias
  ADD COLUMN IF NOT EXISTS ent1 TIME NULL AFTER previsto_saida,
  ADD COLUMN IF NOT EXISTS sai1 TIME NULL AFTER ent1,
  ADD COLUMN IF NOT EXISTS ent2 TIME NULL AFTER sai1,
  ADD COLUMN IF NOT EXISTS sai2 TIME NULL AFTER ent2,
  ADD COLUMN IF NOT EXISTS total_trab_min INT NOT NULL DEFAULT 0 AFTER sai2,
  ADD COLUMN IF NOT EXISTS horas_previstas_min INT NOT NULL DEFAULT 0 AFTER total_trab_min,
  ADD COLUMN IF NOT EXISTS dia_falta TINYINT NOT NULL DEFAULT 0 AFTER horas_previstas_min,
  ADD COLUMN IF NOT EXISTS horas_falta_min INT NOT NULL DEFAULT 0 AFTER dia_falta,
  ADD COLUMN IF NOT EXISTS atraso_bruto_min INT NOT NULL DEFAULT 0 AFTER horas_falta_min,
  ADD COLUMN IF NOT EXISTS horas_extras_min INT NOT NULL DEFAULT 0 AFTER atraso_bruto_min,
  ADD COLUMN IF NOT EXISTS desconto_min INT NOT NULL DEFAULT 0 AFTER horas_extras_min,
  ADD COLUMN IF NOT EXISTS ok_status ENUM('ok','pendente','folga','feriado','falta') NOT NULL DEFAULT 'pendente' AFTER desconto_min;

CREATE TABLE IF NOT EXISTS ponto_justificativa_tipos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(120) NOT NULL,
  abona_falta TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ponto_justificativas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  espelho_id BIGINT UNSIGNED NOT NULL,
  ponto_dia_id BIGINT UNSIGNED NULL,
  tipo_id BIGINT UNSIGNED NOT NULL,
  data_inicio DATE NOT NULL,
  data_fim DATE NOT NULL,
  observacao TEXT NULL,
  anexo_path VARCHAR(255) NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_just_esp FOREIGN KEY (espelho_id) REFERENCES ponto_espelhos(id) ON DELETE CASCADE,
  CONSTRAINT fk_just_dia FOREIGN KEY (ponto_dia_id) REFERENCES ponto_dias(id) ON DELETE SET NULL,
  CONSTRAINT fk_just_tipo FOREIGN KEY (tipo_id) REFERENCES ponto_justificativa_tipos(id),
  CONSTRAINT fk_just_user FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

INSERT INTO ponto_justificativa_tipos (codigo, nome, abona_falta, status) VALUES
('ATST', 'Atestado médico', 1, 'ativo'),
('DECL', 'Declaração', 1, 'ativo'),
('FOLG', 'Folga compensatória', 1, 'ativo'),
('FALTA', 'Falta injustificada', 0, 'ativo'),
('OUTROS', 'Outros', 0, 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);
