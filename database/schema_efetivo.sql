-- Arcadis RH — Schema do Efetivo
-- MySQL 8+ / utf8mb4

SET NAMES utf8mb4;
SET time_zone = '-03:00';

CREATE DATABASE IF NOT EXISTS arcadis_rh
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE arcadis_rh;

-- ---------------------------------------------------------------------------
-- Auth / auditoria base
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS usuarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  senha_hash VARCHAR(255) NOT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS perfis (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usuario_perfis (
  usuario_id BIGINT UNSIGNED NOT NULL,
  perfil_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (usuario_id, perfil_id),
  CONSTRAINT fk_up_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
  CONSTRAINT fk_up_perfil FOREIGN KEY (perfil_id) REFERENCES perfis(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_auditoria (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entidade VARCHAR(80) NOT NULL,
  entidade_id BIGINT UNSIGNED NULL,
  acao VARCHAR(40) NOT NULL,
  antes_json JSON NULL,
  depois_json JSON NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_aud_entidade (entidade, entidade_id),
  CONSTRAINT fk_aud_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Organização
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rh_empresas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(160) NOT NULL,
  cnpj CHAR(14) NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_empresa_cnpj (cnpj)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_contratos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(160) NOT NULL,
  inicio DATE NULL,
  fim DATE NULL,
  status ENUM('ativo','inativo','encerrado') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_contrato_empresa_codigo (empresa_id, codigo),
  CONSTRAINT fk_contrato_empresa FOREIGN KEY (empresa_id) REFERENCES rh_empresas(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_centros_custo (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  contrato_id BIGINT UNSIGNED NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(160) NOT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_cc_contrato_codigo (contrato_id, codigo),
  CONSTRAINT fk_cc_contrato FOREIGN KEY (contrato_id) REFERENCES rh_contratos(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_ordens_servico (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  centro_custo_id BIGINT UNSIGNED NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nome VARCHAR(160) NOT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_os_cc_codigo (centro_custo_id, codigo),
  CONSTRAINT fk_os_cc FOREIGN KEY (centro_custo_id) REFERENCES rh_centros_custo(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_localidades (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  municipio VARCHAR(120) NOT NULL,
  uf CHAR(2) NOT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_localidade (municipio, uf, nome)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Jornadas / Escalas / Calendários
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rh_jornadas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(120) NOT NULL,
  entrada TIME NOT NULL,
  intervalo_inicio TIME NULL,
  intervalo_fim TIME NULL,
  saida TIME NOT NULL,
  carga_diaria_min INT UNSIGNED NOT NULL,
  carga_semanal_min INT UNSIGNED NOT NULL,
  trabalha_sabado TINYINT(1) NOT NULL DEFAULT 0,
  trabalha_domingo TINYINT(1) NOT NULL DEFAULT 0,
  trabalha_feriado TINYINT(1) NOT NULL DEFAULT 0,
  adicional_noturno TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_escalas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(120) NOT NULL,
  tipo ENUM('5x2','6x1','12x36','seg_sex','seg_sab','ciclo','personalizada') NOT NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_escala_ciclos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  escala_id BIGINT UNSIGNED NOT NULL,
  dia_ciclo SMALLINT UNSIGNED NOT NULL,
  tipo_dia ENUM('trabalho','folga') NOT NULL,
  ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uk_escala_dia (escala_id, dia_ciclo),
  CONSTRAINT fk_ciclo_escala FOREIGN KEY (escala_id) REFERENCES rh_escalas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_calendarios (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(40) NOT NULL UNIQUE,
  nome VARCHAR(120) NOT NULL,
  escopo ENUM('nacional','estadual','municipal','contrato') NOT NULL,
  uf CHAR(2) NULL,
  localidade_id BIGINT UNSIGNED NULL,
  contrato_id BIGINT UNSIGNED NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cal_localidade FOREIGN KEY (localidade_id) REFERENCES rh_localidades(id),
  CONSTRAINT fk_cal_contrato FOREIGN KEY (contrato_id) REFERENCES rh_contratos(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_feriados (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calendario_id BIGINT UNSIGNED NOT NULL,
  data DATE NOT NULL,
  nome VARCHAR(160) NOT NULL,
  tipo ENUM('feriado','ponto_facultativo','dia_especial') NOT NULL DEFAULT 'feriado',
  recorrente_ano TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_feriado (calendario_id, data, nome),
  CONSTRAINT fk_feriado_cal FOREIGN KEY (calendario_id) REFERENCES rh_calendarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Lotação (pacote operacional)
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rh_lotacoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(60) NOT NULL UNIQUE,
  nome VARCHAR(160) NOT NULL,
  contrato_id BIGINT UNSIGNED NOT NULL,
  centro_custo_id BIGINT UNSIGNED NOT NULL,
  ordem_servico_id BIGINT UNSIGNED NOT NULL,
  localidade_id BIGINT UNSIGNED NOT NULL,
  jornada_id BIGINT UNSIGNED NOT NULL,
  escala_id BIGINT UNSIGNED NOT NULL,
  calendario_id BIGINT UNSIGNED NULL,
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_lot_contrato FOREIGN KEY (contrato_id) REFERENCES rh_contratos(id),
  CONSTRAINT fk_lot_cc FOREIGN KEY (centro_custo_id) REFERENCES rh_centros_custo(id),
  CONSTRAINT fk_lot_os FOREIGN KEY (ordem_servico_id) REFERENCES rh_ordens_servico(id),
  CONSTRAINT fk_lot_localidade FOREIGN KEY (localidade_id) REFERENCES rh_localidades(id),
  CONSTRAINT fk_lot_jornada FOREIGN KEY (jornada_id) REFERENCES rh_jornadas(id),
  CONSTRAINT fk_lot_escala FOREIGN KEY (escala_id) REFERENCES rh_escalas(id),
  CONSTRAINT fk_lot_calendario FOREIGN KEY (calendario_id) REFERENCES rh_calendarios(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Colaboradores + vigência
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rh_colaboradores (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id BIGINT UNSIGNED NOT NULL,
  matricula VARCHAR(40) NOT NULL,
  nome VARCHAR(160) NOT NULL,
  cpf CHAR(11) NOT NULL,
  email_corp VARCHAR(160) NULL,
  email_alt VARCHAR(160) NULL,
  telefone VARCHAR(30) NULL,
  data_admissao DATE NULL,
  cargo VARCHAR(120) NULL,
  funcao VARCHAR(120) NULL,
  status ENUM('ativo','inativo','afastado','desligado') NOT NULL DEFAULT 'ativo',
  gestor_id BIGINT UNSIGNED NULL,
  supervisor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_colab_empresa_matricula (empresa_id, matricula),
  UNIQUE KEY uk_colab_cpf (cpf),
  CONSTRAINT fk_colab_empresa FOREIGN KEY (empresa_id) REFERENCES rh_empresas(id),
  CONSTRAINT fk_colab_gestor FOREIGN KEY (gestor_id) REFERENCES rh_colaboradores(id),
  CONSTRAINT fk_colab_supervisor FOREIGN KEY (supervisor_id) REFERENCES rh_colaboradores(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_colaborador_lotacoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colaborador_id BIGINT UNSIGNED NOT NULL,
  lotacao_id BIGINT UNSIGNED NOT NULL,
  vigencia_inicio DATE NOT NULL,
  vigencia_fim DATE NULL,
  escala_ancora_data DATE NULL,
  motivo ENUM('admissao','transferencia','correcao','importacao') NOT NULL DEFAULT 'admissao',
  observacao VARCHAR(255) NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vigente_unica TINYINT
    GENERATED ALWAYS AS (CASE WHEN vigencia_fim IS NULL THEN 1 ELSE NULL END) VIRTUAL,
  INDEX idx_colab_vigencia (colaborador_id, vigencia_inicio, vigencia_fim),
  UNIQUE KEY uk_colab_lotacao_aberta (colaborador_id, vigente_unica),
  CONSTRAINT fk_cl_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id),
  CONSTRAINT fk_cl_lotacao FOREIGN KEY (lotacao_id) REFERENCES rh_lotacoes(id),
  CONSTRAINT fk_cl_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_colaborador_excecoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  colaborador_id BIGINT UNSIGNED NOT NULL,
  vigencia_inicio DATE NOT NULL,
  vigencia_fim DATE NOT NULL,
  jornada_id BIGINT UNSIGNED NULL,
  escala_id BIGINT UNSIGNED NULL,
  calendario_id BIGINT UNSIGNED NULL,
  observacao VARCHAR(255) NULL,
  usuario_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exc_colab (colaborador_id, vigencia_inicio, vigencia_fim),
  CONSTRAINT fk_exc_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id),
  CONSTRAINT fk_exc_jornada FOREIGN KEY (jornada_id) REFERENCES rh_jornadas(id),
  CONSTRAINT fk_exc_escala FOREIGN KEY (escala_id) REFERENCES rh_escalas(id),
  CONSTRAINT fk_exc_calendario FOREIGN KEY (calendario_id) REFERENCES rh_calendarios(id),
  CONSTRAINT fk_exc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Importação
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rh_importacoes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  arquivo_nome VARCHAR(255) NOT NULL,
  arquivo_path VARCHAR(255) NOT NULL,
  usuario_id BIGINT UNSIGNED NULL,
  status ENUM('lido','validado','confirmado','cancelado') NOT NULL DEFAULT 'lido',
  total_registros INT UNSIGNED NOT NULL DEFAULT 0,
  total_iguais INT UNSIGNED NOT NULL DEFAULT 0,
  total_atualizar INT UNSIGNED NOT NULL DEFAULT 0,
  total_criar INT UNSIGNED NOT NULL DEFAULT 0,
  total_erros INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at DATETIME NULL,
  CONSTRAINT fk_imp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_importacoes_linhas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  importacao_id BIGINT UNSIGNED NOT NULL,
  linha INT UNSIGNED NOT NULL,
  chave VARCHAR(80) NOT NULL,
  acao ENUM('igual','atualizar','criar','erro') NOT NULL,
  diff_json JSON NULL,
  colaborador_id BIGINT UNSIGNED NULL,
  CONSTRAINT fk_impl_imp FOREIGN KEY (importacao_id) REFERENCES rh_importacoes(id) ON DELETE CASCADE,
  CONSTRAINT fk_impl_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rh_importacoes_erros (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  importacao_id BIGINT UNSIGNED NOT NULL,
  linha INT UNSIGNED NOT NULL,
  campo VARCHAR(80) NULL,
  mensagem VARCHAR(255) NOT NULL,
  CONSTRAINT fk_impe_imp FOREIGN KEY (importacao_id) REFERENCES rh_importacoes(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seeds mínimos
INSERT INTO perfis (codigo, nome) VALUES
  ('admin', 'Administrador'),
  ('rh', 'RH'),
  ('gestor', 'Gestor')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_calendarios (codigo, nome, escopo, status)
VALUES ('NACIONAL', 'Feriados Nacionais', 'nacional', 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);
