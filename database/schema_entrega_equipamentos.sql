-- Entrega de Equipamentos (termo COR-JUR-FM-005)

CREATE TABLE IF NOT EXISTS ti_item_tipos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  categoria ENUM('equipamento','periferico') NOT NULL DEFAULT 'equipamento',
  status ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ti_item_tipo (nome, categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ti_entregas (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(32) NOT NULL,
  colaborador_id BIGINT UNSIGNED NULL,
  status ENUM('rascunho','emitido','assinado_anexado','devolvido','cancelado') NOT NULL DEFAULT 'rascunho',
  -- snapshot colaborador
  colab_nome VARCHAR(160) NOT NULL,
  colab_cpf VARCHAR(20) NOT NULL DEFAULT '',
  colab_rg VARCHAR(40) NULL,
  colab_razao_social VARCHAR(160) NULL,
  colab_cnpj VARCHAR(20) NULL,
  colab_endereco VARCHAR(255) NULL,
  colab_bairro VARCHAR(120) NULL,
  colab_municipio VARCHAR(120) NULL,
  colab_telefone VARCHAR(40) NULL,
  colab_email VARCHAR(160) NULL,
  -- termo
  local_termo VARCHAR(160) NULL,
  data_termo DATE NULL,
  -- gestor (cadastros + snapshot)
  gestor_arcadis_id BIGINT UNSIGNED NULL,
  supervisor_id BIGINT UNSIGNED NULL,
  gestor_nome VARCHAR(160) NULL,
  gestor_cpf VARCHAR(20) NULL,
  supervisor_nome VARCHAR(160) NULL,
  projeto_nome VARCHAR(255) NULL,
  centro_custo VARCHAR(80) NULL,
  -- PDF emitido
  arquivo_path VARCHAR(255) NULL,
  arquivo_sha256 CHAR(64) NULL,
  emitido_em DATETIME NULL,
  emitido_por BIGINT UNSIGNED NULL,
  -- anexo assinado (atalho do último)
  arquivo_assinado_path VARCHAR(255) NULL,
  assinado_anexado_em DATETIME NULL,
  assinado_anexado_por BIGINT UNSIGNED NULL,
  -- devolução
  devolucao_data DATE NULL,
  devolucao_condicao ENUM('bom_estado','com_defeito') NULL,
  devolucao_descricao TEXT NULL,
  devolucao_nome VARCHAR(160) NULL,
  devolucao_em DATETIME NULL,
  devolucao_por BIGINT UNSIGNED NULL,
  observacao VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_ti_entrega_codigo (codigo),
  INDEX idx_ti_entrega_status (status),
  INDEX idx_ti_entrega_colab (colaborador_id),
  CONSTRAINT fk_ti_entrega_colab FOREIGN KEY (colaborador_id) REFERENCES rh_colaboradores(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ti_entrega_itens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entrega_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('equipamento','periferico') NOT NULL,
  quantidade INT UNSIGNED NOT NULL DEFAULT 1,
  descricao VARCHAR(255) NOT NULL,
  tombamento VARCHAR(120) NULL,
  ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ti_item_entrega (entrega_id, tipo, ordem),
  CONSTRAINT fk_ti_item_entrega FOREIGN KEY (entrega_id) REFERENCES ti_entregas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ti_entrega_anexos (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entrega_id BIGINT UNSIGNED NOT NULL,
  tipo ENUM('termo_assinado','devolucao','outro') NOT NULL DEFAULT 'termo_assinado',
  arquivo_path VARCHAR(255) NOT NULL,
  arquivo_nome VARCHAR(255) NOT NULL,
  mime VARCHAR(120) NULL,
  tamanho INT UNSIGNED NULL,
  sha256 CHAR(64) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ti_anexo_entrega (entrega_id, tipo),
  CONSTRAINT fk_ti_anexo_entrega FOREIGN KEY (entrega_id) REFERENCES ti_entregas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO ti_item_tipos (nome, categoria) VALUES
('Notebook', 'equipamento'),
('Celular', 'equipamento'),
('Tablet', 'equipamento'),
('Monitor', 'equipamento'),
('Carregador', 'periferico'),
('Mouse', 'periferico'),
('Teclado', 'periferico'),
('Headset', 'periferico'),
('Mousepad', 'periferico'),
('Suporte ergonômico', 'periferico');
