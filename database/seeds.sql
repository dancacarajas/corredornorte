USE arcadis_rh;

-- Admin: senha definida por scripts/install_db.php (admin123)
INSERT INTO usuarios (id, nome, email, senha_hash, status)
VALUES (
  1,
  'Administrador',
  'admin@arcadis.local',
  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
  'ativo'
)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO usuario_perfis (usuario_id, perfil_id)
SELECT 1, id FROM perfis WHERE codigo = 'admin'
ON DUPLICATE KEY UPDATE perfil_id = VALUES(perfil_id);

INSERT INTO rh_empresas (id, nome, cnpj, status)
VALUES (1, 'Arcadis Serviços', '00000000000191', 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_contratos (id, empresa_id, codigo, nome, inicio, status)
VALUES (1, 1, 'CTR-XYZ', 'Contrato XYZ Mineração', '2026-01-01', 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_centros_custo (id, contrato_id, codigo, nome, status) VALUES
(1, 1, 'CC-1234', 'Administrativo Parauapebas', 'ativo'),
(2, 1, 'CC-200', 'Operacional Parauapebas', 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_ordens_servico (id, centro_custo_id, codigo, nome, status) VALUES
(1, 1, 'OS-001', 'OS Administrativa', 'ativo'),
(2, 1, 'OS-002', 'OS Apoio', 'ativo'),
(3, 2, 'OS-003', 'OS Operação', 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_localidades (id, nome, municipio, uf, status) VALUES
(1, 'Parauapebas Sede', 'Parauapebas', 'PA', 'ativo'),
(2, 'Marabá Base', 'Marabá', 'PA', 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_jornadas (id, codigo, nome, entrada, intervalo_inicio, intervalo_fim, saida, carga_diaria_min, carga_semanal_min, trabalha_sabado, trabalha_domingo, trabalha_feriado, adicional_noturno, status)
VALUES
(1, 'ADM-44H', 'Administrativa 44h', '08:00:00', '12:00:00', '13:00:00', '17:48:00', 528, 2640, 0, 0, 0, 0, 'ativo'),
(2, 'OP-12X36', 'Operacional 12x36', '07:00:00', '12:00:00', '13:00:00', '19:00:00', 720, 2520, 1, 1, 1, 1, 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_escalas (id, codigo, nome, tipo, status) VALUES
(1, 'SEG-SEX', 'Segunda a Sexta', 'seg_sex', 'ativo'),
(2, '12X36', 'Escala 12x36', '12x36', 'ativo'),
(3, 'CICLO-4', 'Ciclo personalizado 4 dias', 'ciclo', 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_escala_ciclos (escala_id, dia_ciclo, tipo_dia, ordem) VALUES
(3, 1, 'trabalho', 1),
(3, 2, 'trabalho', 2),
(3, 3, 'folga', 3),
(3, 4, 'trabalho', 4)
ON DUPLICATE KEY UPDATE tipo_dia = VALUES(tipo_dia);

INSERT INTO rh_calendarios (id, codigo, nome, escopo, uf, localidade_id, contrato_id, status) VALUES
(1, 'NACIONAL', 'Feriados Nacionais', 'nacional', NULL, NULL, NULL, 'ativo'),
(2, 'PA-ESTADUAL', 'Feriados Pará', 'estadual', 'PA', NULL, NULL, 'ativo'),
(3, 'PARAUAPEBAS', 'Feriados Parauapebas', 'municipal', 'PA', 1, NULL, 'ativo'),
(5, 'MARABA-2', 'Feriados Marabá', 'municipal', 'PA', 2, NULL, 'ativo'),
(4, 'CTR-XYZ-CAL', 'Dias especiais Contrato XYZ', 'contrato', NULL, NULL, 1, 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_feriados (calendario_id, data, nome, tipo, recorrente_ano) VALUES
(1, '2026-01-01', 'Confraternização Universal', 'feriado', 1),
(1, '2026-04-21', 'Tiradentes', 'feriado', 1),
(1, '2026-05-01', 'Dia do Trabalho', 'feriado', 1),
(1, '2026-09-07', 'Independência do Brasil', 'feriado', 1),
(1, '2026-10-12', 'Nossa Senhora Aparecida', 'feriado', 1),
(1, '2026-11-02', 'Finados', 'feriado', 1),
(1, '2026-11-15', 'Proclamação da República', 'feriado', 1),
(1, '2026-12-25', 'Natal', 'feriado', 1),
(2, '2026-08-15', 'Adesão do Pará à Independência', 'feriado', 1),
(3, '2026-05-18', 'Aniversário de Parauapebas', 'feriado', 1),
(5, '2026-12-05', 'Aniversário de Marabá', 'feriado', 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_lotacoes (id, codigo, nome, contrato_id, centro_custo_id, ordem_servico_id, localidade_id, jornada_id, escala_id, calendario_id, status) VALUES
(1, 'LOT-ADM-PBE', 'ADM Parauapebas CC-1234 OS-001', 1, 1, 1, 1, 1, 1, 4, 'ativo'),
(2, 'LOT-OP-PBE', 'Operação Parauapebas CC-200 OS-003', 1, 2, 3, 1, 2, 2, 4, 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_colaboradores (id, empresa_id, matricula, nome, cpf, email_corp, telefone, data_admissao, cargo, funcao, status)
VALUES
(1, 1, '10021', 'João Silva', '52998224725', 'joao.silva@arcadis.local', '94999990001', '2026-01-10', 'Assistente Administrativo', 'Administrativo', 'ativo'),
(2, 1, '10045', 'Maria Silva', '39053344705', 'maria.silva@arcadis.local', '94999990002', '2026-02-01', 'Analista', 'Administrativo', 'ativo'),
(3, 1, '10088', 'Carlos Souza', '15350946056', 'carlos.souza@arcadis.local', '94999990003', '2026-03-15', 'Operador', 'Operação', 'ativo')
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

INSERT INTO rh_colaborador_lotacoes (colaborador_id, lotacao_id, vigencia_inicio, vigencia_fim, escala_ancora_data, motivo, observacao, usuario_id)
SELECT 1, 1, '2026-01-10', NULL, '2026-01-10', 'admissao', 'Seed inicial', 1
WHERE NOT EXISTS (
  SELECT 1 FROM rh_colaborador_lotacoes WHERE colaborador_id = 1 AND vigencia_fim IS NULL
);

INSERT INTO rh_colaborador_lotacoes (colaborador_id, lotacao_id, vigencia_inicio, vigencia_fim, escala_ancora_data, motivo, observacao, usuario_id)
SELECT 2, 1, '2026-02-01', NULL, '2026-02-01', 'admissao', 'Seed inicial', 1
WHERE NOT EXISTS (
  SELECT 1 FROM rh_colaborador_lotacoes WHERE colaborador_id = 2 AND vigencia_fim IS NULL
);

INSERT INTO rh_colaborador_lotacoes (colaborador_id, lotacao_id, vigencia_inicio, vigencia_fim, escala_ancora_data, motivo, observacao, usuario_id)
SELECT 3, 2, '2026-03-15', NULL, '2026-03-15', 'admissao', 'Seed inicial', 1
WHERE NOT EXISTS (
  SELECT 1 FROM rh_colaborador_lotacoes WHERE colaborador_id = 3 AND vigencia_fim IS NULL
);

INSERT INTO ponto_periodos (data_inicio, data_fim, status)
VALUES ('2026-11-01', '2026-11-30', 'aberta')
ON DUPLICATE KEY UPDATE status = VALUES(status);
