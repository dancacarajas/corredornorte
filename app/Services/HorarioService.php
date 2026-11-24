<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Horários no modelo RHiD: grade semanal ou cíclica com 4 batidas por dia.
 * Alimenta previstos do ponto (e, por reflexo, Relatório de HE).
 */
final class HorarioService
{
    private PDO $db;
    private static bool $schemaOk = false;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function ensureSchema(): void
    {
        if (self::$schemaOk) {
            return;
        }
        // Só DDL se faltar algo. ALTER em tabela quente durante geração de espelho
        // congela o request (metadata lock) e a UI fica em 0%.
        if (!$this->tableExists('rh_horarios') || !$this->tableExists('rh_horario_dias')) {
            $sql = file_get_contents(dirname(__DIR__, 2) . '/database/schema_horarios_rhid.sql');
            if (!is_string($sql) || $sql === '') {
                throw new RuntimeException('Schema de horários não encontrado.');
            }
            $this->db->exec($sql);
        }
        if (!$this->hasColumn('rh_lotacoes', 'horario_id')) {
            $this->db->exec(
                'ALTER TABLE rh_lotacoes
                 ADD COLUMN horario_id BIGINT UNSIGNED NULL AFTER escala_id'
            );
        }
        if (!$this->hasColumn('rh_colaborador_excecoes', 'horario_id')) {
            try {
                $this->db->exec(
                    'ALTER TABLE rh_colaborador_excecoes
                     ADD COLUMN horario_id BIGINT UNSIGNED NULL AFTER escala_id'
                );
            } catch (Throwable) {
            }
        }
        // Lotação passa a depender só de horário; legado fica opcional.
        if ($this->columnIsNotNullable('rh_lotacoes', 'jornada_id')
            || $this->columnIsNotNullable('rh_lotacoes', 'escala_id')
        ) {
            try {
                $this->db->exec(
                    'ALTER TABLE rh_lotacoes
                     MODIFY jornada_id BIGINT UNSIGNED NULL,
                     MODIFY escala_id BIGINT UNSIGNED NULL'
                );
            } catch (Throwable) {
            }
        }
        self::$schemaOk = true;
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
        );
        $st->execute([$table]);

        return (bool) $st->fetchColumn();
    }

    private function columnIsNotNullable(string $table, string $column): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND IS_NULLABLE = 'NO'
             LIMIT 1"
        );
        $st->execute([$table, $column]);

        return (bool) $st->fetchColumn();
    }

    /**
     * Garante horário na lotação (migra de jornada+escala se necessário)
     * e sobrescreve no $cfg os campos do dia (entrada/intervalo/saída + tipo).
     *
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    public function aplicarDiaNoCfg(array $cfg, DateTimeImmutable $dia): array
    {
        $this->ensureSchema();
        $horarioId = (int) ($cfg['horario_id'] ?? 0);
        if ($horarioId <= 0) {
            $horarioId = $this->garantirHorarioParaLotacao($cfg);
            if ($horarioId > 0) {
                $cfg['horario_id'] = $horarioId;
            }
        }
        if ($horarioId <= 0) {
            return $cfg;
        }

        $horario = $this->buscar($horarioId);
        if ($horario === null) {
            return $cfg;
        }

        $diaCfg = $this->resolverDia($horario, $dia, $cfg);
        $cfg['horario_id'] = $horarioId;
        $cfg['horario_tipo'] = $horario['tipo'];
        $cfg['horario_dia_indice'] = $diaCfg['indice'];
        $cfg['horario_dia_tipo'] = $diaCfg['tipo_dia'];
        $cfg['trabalha_feriado'] = !empty($horario['trabalha_feriado']) ? 1 : 0;
        $cfg['adicional_noturno'] = !empty($horario['adicional_noturno']) ? 1 : 0;
        $temOverrideColab = isset($cfg['colaborador_percentual_he'])
            && $cfg['colaborador_percentual_he'] !== ''
            && (int) $cfg['colaborador_percentual_he'] > 0;
        $temPercentualLotacao = isset($cfg['lotacao_percentual_he'])
            && $cfg['lotacao_percentual_he'] !== ''
            && (int) $cfg['lotacao_percentual_he'] > 0;
        if (!$temOverrideColab && !$temPercentualLotacao && !empty($horario['percentual_he'])) {
            $cfg['percentual_he'] = (int) $horario['percentual_he'];
        }

        if ($diaCfg['tipo_dia'] === 'trabalho') {
            $cfg['entrada'] = $diaCfg['ent1'];
            $cfg['intervalo_inicio'] = $diaCfg['sai1'];
            $cfg['intervalo_fim'] = $diaCfg['ent2'];
            $cfg['saida'] = $diaCfg['sai2'];
        } else {
            $cfg['entrada'] = null;
            $cfg['intervalo_inicio'] = null;
            $cfg['intervalo_fim'] = null;
            $cfg['saida'] = null;
        }

        return $cfg;
    }

    /**
     * @param array<string,mixed> $horario
     * @param array<string,mixed> $cfg
     * @return array{indice:int,tipo_dia:string,ent1:?string,sai1:?string,ent2:?string,sai2:?string}
     */
    public function resolverDia(array $horario, DateTimeImmutable $dia, array $cfg = []): array
    {
        $tipo = (string) ($horario['tipo'] ?? 'semanal');
        $dias = $this->listarDias((int) $horario['id']);
        if ($dias === []) {
            return [
                'indice' => (int) $dia->format('N'),
                'tipo_dia' => 'folga',
                'ent1' => null, 'sai1' => null, 'ent2' => null, 'sai2' => null,
            ];
        }

        if ($tipo === 'ciclico') {
            $rawCiclo = (int) ($horario['dias_ciclo'] ?? 0);
            $n = $rawCiclo > 0 ? $rawCiclo : max(1, count($dias));
            $ancoraStr = (string) ($cfg['escala_ancora_data'] ?? $cfg['vigencia_inicio'] ?? $dia->format('Y-m-d'));
            $ancora = new DateTimeImmutable($ancoraStr);
            $diff = (int) $ancora->diff($dia)->format('%r%a');
            $idx = (($diff % $n) + $n) % $n; // 0-based
            $indice = $idx + 1;
            foreach ($dias as $d) {
                if ((int) $d['indice'] === $indice) {
                    return $this->normDiaRow($d);
                }
            }
            // fallback ordem
            $ordenado = array_values($dias);
            return $this->normDiaRow($ordenado[$idx] ?? $ordenado[0]);
        }

        $indice = (int) $dia->format('N'); // 1=seg..7=dom
        foreach ($dias as $d) {
            if ((int) $d['indice'] === $indice) {
                return $this->normDiaRow($d);
            }
        }
        return [
            'indice' => $indice,
            'tipo_dia' => $indice <= 5 ? 'trabalho' : 'folga',
            'ent1' => null, 'sai1' => null, 'ent2' => null, 'sai2' => null,
        ];
    }

    /** @return array<string,mixed>|null */
    public function buscar(int $id): ?array
    {
        $st = $this->db->prepare('SELECT * FROM rh_horarios WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function listarDias(int $horarioId): array
    {
        $st = $this->db->prepare(
            'SELECT * FROM rh_horario_dias WHERE horario_id = ? ORDER BY indice'
        );
        $st->execute([$horarioId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listarAtivos(): array
    {
        $this->ensureSchema();
        return $this->db->query(
            "SELECT h.*,
                    (SELECT COUNT(*) FROM rh_horario_dias d WHERE d.horario_id = h.id AND d.tipo_dia = 'trabalho') AS dias_trabalho
             FROM rh_horarios h
             WHERE h.status = 'ativo'
             ORDER BY h.nome"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $cabecalho
     * @param list<array<string,mixed>> $dias
     */
    public function salvar(int $id, array $cabecalho, array $dias): int
    {
        $this->ensureSchema();
        $codigo = trim((string) ($cabecalho['codigo'] ?? ''));
        $nome = trim((string) ($cabecalho['nome'] ?? ''));
        $tipo = (string) ($cabecalho['tipo'] ?? 'semanal');
        if ($codigo === '' || $nome === '') {
            throw new RuntimeException('Informe código e nome do horário.');
        }
        if (!in_array($tipo, ['semanal', 'ciclico'], true)) {
            $tipo = 'semanal';
        }
        $rawCiclo = (int) ($cabecalho['dias_ciclo'] ?? 0);
        $diasCiclo = $tipo === 'ciclico' ? ($rawCiclo > 0 ? $rawCiclo : max(1, count($dias))) : null;

        if ($id > 0) {
            $this->db->prepare(
                'UPDATE rh_horarios SET codigo=?, nome=?, tipo=?, dias_ciclo=?,
                    trabalha_feriado=?, adicional_noturno=?, almoco_livre=?, compensado=?, neutro=?,
                    percentual_he=?, status=?, updated_at=NOW()
                 WHERE id=?'
            )->execute([
                $codigo, $nome, $tipo, $diasCiclo,
                !empty($cabecalho['trabalha_feriado']) ? 1 : 0,
                !empty($cabecalho['adicional_noturno']) ? 1 : 0,
                !empty($cabecalho['almoco_livre']) ? 1 : 0,
                !empty($cabecalho['compensado']) ? 1 : 0,
                !empty($cabecalho['neutro']) ? 1 : 0,
                isset($cabecalho['percentual_he']) && $cabecalho['percentual_he'] !== ''
                    ? (int) $cabecalho['percentual_he'] : null,
                (string) ($cabecalho['status'] ?? 'ativo'),
                $id,
            ]);
            $horarioId = $id;
        } else {
            $this->db->prepare(
                'INSERT INTO rh_horarios
                 (codigo, nome, tipo, dias_ciclo, trabalha_feriado, adicional_noturno, almoco_livre, compensado, neutro, percentual_he, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $codigo, $nome, $tipo, $diasCiclo,
                !empty($cabecalho['trabalha_feriado']) ? 1 : 0,
                !empty($cabecalho['adicional_noturno']) ? 1 : 0,
                !empty($cabecalho['almoco_livre']) ? 1 : 0,
                !empty($cabecalho['compensado']) ? 1 : 0,
                !empty($cabecalho['neutro']) ? 1 : 0,
                isset($cabecalho['percentual_he']) && $cabecalho['percentual_he'] !== ''
                    ? (int) $cabecalho['percentual_he'] : null,
                (string) ($cabecalho['status'] ?? 'ativo'),
            ]);
            $horarioId = (int) $this->db->lastInsertId();
        }

        $this->db->prepare('DELETE FROM rh_horario_dias WHERE horario_id = ?')->execute([$horarioId]);
        $ins = $this->db->prepare(
            'INSERT INTO rh_horario_dias (horario_id, indice, tipo_dia, ent1, sai1, ent2, sai2)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($dias as $d) {
            $indice = (int) ($d['indice'] ?? 0);
            if ($indice <= 0) {
                continue;
            }
            $tipoDia = (string) ($d['tipo_dia'] ?? 'folga');
            if (!in_array($tipoDia, ['trabalho', 'folga'], true)) {
                $tipoDia = 'folga';
            }
            $ent1 = $this->normHora($d['ent1'] ?? null);
            $sai1 = $this->normHora($d['sai1'] ?? null);
            $ent2 = $this->normHora($d['ent2'] ?? null);
            $sai2 = $this->normHora($d['sai2'] ?? null);
            if ($tipoDia === 'folga') {
                $ent1 = $sai1 = $ent2 = $sai2 = null;
            }
            $ins->execute([$horarioId, $indice, $tipoDia, $ent1, $sai1, $ent2, $sai2]);
        }

        return $horarioId;
    }

    /**
     * Migra todas as lotações sem horario_id a partir de jornada+escala.
     */
    public function migrarLote(): int
    {
        $this->ensureSchema();
        $rows = $this->db->query(
            'SELECT id, jornada_id, escala_id, percentual_he FROM rh_lotacoes WHERE horario_id IS NULL'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $n = 0;
        foreach ($rows as $row) {
            $hid = $this->obterOuCriarDeJornadaEscala(
                (int) $row['jornada_id'],
                (int) $row['escala_id'],
                isset($row['percentual_he']) ? (int) $row['percentual_he'] : null
            );
            if ($hid > 0) {
                $this->db->prepare('UPDATE rh_lotacoes SET horario_id = ? WHERE id = ?')
                    ->execute([$hid, (int) $row['id']]);
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string,mixed> $cfg */
    private function garantirHorarioParaLotacao(array $cfg): int
    {
        $lotacaoId = (int) ($cfg['id'] ?? $cfg['lotacao_id'] ?? 0);
        $jornadaId = (int) ($cfg['jornada_id'] ?? 0);
        $escalaId = (int) ($cfg['escala_id'] ?? 0);
        if ($jornadaId <= 0 || $escalaId <= 0) {
            return 0;
        }
        $hid = $this->obterOuCriarDeJornadaEscala(
            $jornadaId,
            $escalaId,
            isset($cfg['lotacao_percentual_he']) ? (int) $cfg['lotacao_percentual_he'] : null
        );
        if ($hid > 0 && $lotacaoId > 0) {
            $this->db->prepare('UPDATE rh_lotacoes SET horario_id = ? WHERE id = ? AND horario_id IS NULL')
                ->execute([$hid, $lotacaoId]);
        }
        return $hid;
    }

    public function obterOuCriarDeJornadaEscala(int $jornadaId, int $escalaId, ?int $pctHe = null): int
    {
        $this->ensureSchema();
        $st = $this->db->prepare(
            'SELECT id FROM rh_horarios
             WHERE jornada_origem_id = ? AND escala_origem_id = ? AND status = \'ativo\'
             LIMIT 1'
        );
        $st->execute([$jornadaId, $escalaId]);
        $exist = (int) $st->fetchColumn();
        if ($exist > 0) {
            return $exist;
        }

        $j = $this->db->prepare('SELECT * FROM rh_jornadas WHERE id = ?');
        $j->execute([$jornadaId]);
        $jornada = $j->fetch(PDO::FETCH_ASSOC);
        $e = $this->db->prepare('SELECT * FROM rh_escalas WHERE id = ?');
        $e->execute([$escalaId]);
        $escala = $e->fetch(PDO::FETCH_ASSOC);
        if ($jornada === false || $escala === false) {
            return 0;
        }

        $escalaTipo = (string) ($escala['tipo'] ?? 'seg_sex');
        $isCiclo = in_array($escalaTipo, ['12x36', 'ciclo'], true);
        $codigo = 'H-' . (string) $jornada['codigo'] . '-' . (string) $escala['codigo'];
        $codigo = mb_substr(preg_replace('/\s+/', '', $codigo) ?? $codigo, 0, 40);
        // Evita colisão de código.
        $base = $codigo;
        $i = 1;
        while ($this->codigoExiste($codigo)) {
            $codigo = mb_substr($base, 0, 36) . '-' . $i++;
        }

        $nome = (string) $jornada['nome'] . ' · ' . (string) $escala['nome'];
        $this->db->prepare(
            'INSERT INTO rh_horarios
             (codigo, nome, tipo, dias_ciclo, trabalha_feriado, adicional_noturno, percentual_he,
              jornada_origem_id, escala_origem_id, status)
             VALUES (?,?,?,?,?,?,?,?,?,\'ativo\')'
        )->execute([
            $codigo,
            mb_substr($nome, 0, 160),
            $isCiclo ? 'ciclico' : 'semanal',
            $isCiclo ? ($escalaTipo === '12x36' ? 2 : null) : null,
            !empty($jornada['trabalha_feriado']) ? 1 : 0,
            !empty($jornada['adicional_noturno']) ? 1 : 0,
            $pctHe,
            $jornadaId,
            $escalaId,
        ]);
        $horarioId = (int) $this->db->lastInsertId();

        $ent1 = $this->normHora($jornada['entrada'] ?? null);
        $sai1 = $this->normHora($jornada['intervalo_inicio'] ?? null);
        $ent2 = $this->normHora($jornada['intervalo_fim'] ?? null);
        $sai2 = $this->normHora($jornada['saida'] ?? null);

        $ins = $this->db->prepare(
            'INSERT INTO rh_horario_dias (horario_id, indice, tipo_dia, ent1, sai1, ent2, sai2)
             VALUES (?,?,?,?,?,?,?)'
        );

        if ($escalaTipo === '12x36') {
            $ins->execute([$horarioId, 1, 'trabalho', $ent1, $sai1, $ent2, $sai2]);
            $ins->execute([$horarioId, 2, 'folga', null, null, null, null]);
            $this->db->prepare('UPDATE rh_horarios SET dias_ciclo = 2 WHERE id = ?')->execute([$horarioId]);
        } elseif ($escalaTipo === 'ciclo') {
            $ciclos = $this->db->prepare(
                'SELECT dia_ciclo, tipo_dia FROM rh_escala_ciclos WHERE escala_id = ? ORDER BY ordem, dia_ciclo'
            );
            $ciclos->execute([$escalaId]);
            $rows = $ciclos->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $n = 0;
            foreach ($rows as $c) {
                $n++;
                $td = (string) ($c['tipo_dia'] ?? 'trabalho') === 'folga' ? 'folga' : 'trabalho';
                $ins->execute([
                    $horarioId,
                    $n,
                    $td,
                    $td === 'trabalho' ? $ent1 : null,
                    $td === 'trabalho' ? $sai1 : null,
                    $td === 'trabalho' ? $ent2 : null,
                    $td === 'trabalho' ? $sai2 : null,
                ]);
            }
            if ($n > 0) {
                $this->db->prepare('UPDATE rh_horarios SET dias_ciclo = ? WHERE id = ?')->execute([$n, $horarioId]);
            }
        } else {
            // Semanal: 1=seg .. 7=dom
            $trabalhaSab = !empty($jornada['trabalha_sabado']) || in_array($escalaTipo, ['seg_sab', '6x1'], true);
            $trabalhaDom = !empty($jornada['trabalha_domingo']);
            for ($dow = 1; $dow <= 7; $dow++) {
                $td = 'folga';
                if ($dow <= 5) {
                    $td = 'trabalho';
                } elseif ($dow === 6 && $trabalhaSab) {
                    $td = 'trabalho';
                } elseif ($dow === 7 && $trabalhaDom) {
                    $td = 'trabalho';
                }
                $ins->execute([
                    $horarioId,
                    $dow,
                    $td,
                    $td === 'trabalho' ? $ent1 : null,
                    $td === 'trabalho' ? $sai1 : null,
                    $td === 'trabalho' ? $ent2 : null,
                    $td === 'trabalho' ? $sai2 : null,
                ]);
            }
        }

        return $horarioId;
    }

    private function codigoExiste(string $codigo): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM rh_horarios WHERE codigo = ? LIMIT 1');
        $st->execute([$codigo]);
        return (bool) $st->fetchColumn();
    }

    /**
     * @param array<string,mixed> $d
     * @return array{indice:int,tipo_dia:string,ent1:?string,sai1:?string,ent2:?string,sai2:?string}
     */
    private function normDiaRow(array $d): array
    {
        return [
            'indice' => (int) ($d['indice'] ?? 0),
            'tipo_dia' => (string) ($d['tipo_dia'] ?? 'folga'),
            'ent1' => $this->normHora($d['ent1'] ?? null),
            'sai1' => $this->normHora($d['sai1'] ?? null),
            'ent2' => $this->normHora($d['ent2'] ?? null),
            'sai2' => $this->normHora($d['sai2'] ?? null),
        ];
    }

    private function normHora(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^\d{2}:\d{2}$/', $s)) {
            return $s . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $s)) {
            return $s;
        }
        return null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
        );
        $st->execute([$table, $column]);
        return (bool) $st->fetchColumn();
    }
}
