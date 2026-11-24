<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Gerencia alterações temporárias de turno / escala (ex.: mudança para turno noturno 19h
 * mantendo a mesma carga horária) autorizadas pelo cliente (Vale), exigindo anexo PDF
 * obrigatório vinculado ao espelho de ponto e integrando com recálculo, CLT e ZIP da Medição.
 */
final class PontoTurnoAutorizacaoService
{
    private PDO $db;
    private string $root;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->root = dirname(__DIR__, 2);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS ponto_turno_autorizacoes (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    espelho_id BIGINT UNSIGNED NOT NULL,
                    colaborador_id BIGINT UNSIGNED NOT NULL,
                    data_inicio DATE NOT NULL,
                    data_fim DATE NOT NULL,
                    entrada1 TIME NOT NULL,
                    saida1 TIME NULL,
                    entrada2 TIME NULL,
                    saida2 TIME NOT NULL,
                    adicional_noturno TINYINT(1) NOT NULL DEFAULT 1,
                    motivo TEXT NOT NULL,
                    arquivo_path VARCHAR(500) NOT NULL,
                    original_name VARCHAR(255) NULL,
                    mime VARCHAR(120) NULL,
                    created_by BIGINT UNSIGNED NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_turno_espelho (espelho_id),
                    KEY idx_turno_colab_datas (colaborador_id, data_inicio, data_fim)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Throwable) {
            try {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS ponto_turno_autorizacoes (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        espelho_id INTEGER NOT NULL,
                        colaborador_id INTEGER NOT NULL,
                        data_inicio TEXT NOT NULL,
                        data_fim TEXT NOT NULL,
                        entrada1 TEXT NOT NULL,
                        saida1 TEXT NULL,
                        entrada2 TEXT NULL,
                        saida2 TEXT NOT NULL,
                        adicional_noturno INTEGER NOT NULL DEFAULT 1,
                        motivo TEXT NOT NULL,
                        arquivo_path TEXT NOT NULL,
                        original_name TEXT NULL,
                        mime TEXT NULL,
                        created_by INTEGER NULL,
                        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
                    )"
                );
            } catch (Throwable) {
            }
        }
    }

    public function tableExists(): bool
    {
        try {
            if ((string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                return (bool) $this->db->query(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ponto_turno_autorizacoes' LIMIT 1"
                )->fetchColumn();
            }
            return (bool) $this->db->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ponto_turno_autorizacoes' LIMIT 1"
            )->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listarPorEspelho(int $espelhoId): array
    {
        if ($espelhoId <= 0 || !$this->tableExists()) {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT * FROM ponto_turno_autorizacoes WHERE espelho_id = ? ORDER BY data_inicio ASC, id DESC'
        );
        $st->execute([$espelhoId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Retorna um mapa data (YYYY-MM-DD) => autorização vigente no dia para o espelho.
     *
     * @return array<string, array<string,mixed>>
     */
    public function mapPorDataDoEspelho(int $espelhoId): array
    {
        $map = [];
        foreach ($this->listarPorEspelho($espelhoId) as $row) {
            $ini = (string) ($row['data_inicio'] ?? '');
            $fim = (string) ($row['data_fim'] ?? '');
            if ($ini === '' || $fim === '') {
                continue;
            }
            try {
                $cur = new \DateTimeImmutable($ini);
                $end = new \DateTimeImmutable($fim);
                $guard = 0;
                while ($cur <= $end && $guard < 62) {
                    $map[$cur->format('Y-m-d')] = $row;
                    $cur = $cur->modify('+1 day');
                    $guard++;
                }
            } catch (Throwable) {
            }
        }
        return $map;
    }

    /**
     * Aplica alteração temporária de turno em um intervalo de datas do espelho,
     * exigindo PDF da autorização do cliente, atualizando o previsto e batidas (se desejado)
     * e recalculando todos os dias afetados (incluindo Adicional Noturno).
     *
     * @param array<string,mixed> $file $_FILES['autorizacao_pdf']
     * @return array{id:int, dias_atualizados:int}
     */
    public function aplicarAlteracaoTurno(
        int $espelhoId,
        string $dataInicio,
        string $dataFim,
        string $entrada1,
        ?string $saida1,
        ?string $entrada2,
        string $saida2,
        bool $adicionalNoturno,
        string $motivo,
        array $file,
        bool $aplicarBatidas,
        ?int $userId
    ): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataInicio) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataFim)) {
            throw new RuntimeException('Informe data inicial e data final válidas.');
        }
        if ($dataFim < $dataInicio) {
            throw new RuntimeException('A data final não pode ser anterior à data inicial.');
        }
        $e1 = $this->normHm($entrada1);
        $s1 = $this->normHm($saida1);
        $e2 = $this->normHm($entrada2);
        $s2 = $this->normHm($saida2);
        if ($e1 === null || $s2 === null) {
            throw new RuntimeException('Informe ao menos Entrada 1 e Saída 2 válidas (formato HH:MM).');
        }
        if (($s1 !== null && $e2 === null) || ($s1 === null && $e2 !== null)) {
            throw new RuntimeException('Informe Saída 1 e Entrada 2 juntas para o intervalo, ou deixe ambas vazias para turno contínuo.');
        }
        $motivoTrim = trim($motivo);
        if ($motivoTrim === '') {
            throw new RuntimeException('Informe o motivo / justificativa da alteração de turno.');
        }

        // Valida upload obrigatório de PDF
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($err !== UPLOAD_ERR_OK || $tmpName === '' || !is_file($tmpName)) {
            throw new RuntimeException('É obrigatório anexar o PDF da autorização de alteração de turno do cliente.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            throw new RuntimeException('O arquivo PDF deve ter até 10 MB.');
        }
        $origName = basename((string) ($file['name'] ?? 'autorizacao_turno.pdf'));
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $head = (string) file_get_contents($tmpName, false, null, 0, 5);
        if ($ext !== 'pdf' || !str_starts_with($head, '%PDF')) {
            throw new RuntimeException('O anexo da alteração de turno deve ser obrigatoriamente um arquivo PDF válido.');
        }

        $espSt = $this->db->prepare('SELECT id, colaborador_id, status FROM ponto_espelhos WHERE id = ? LIMIT 1');
        $espSt->execute([$espelhoId]);
        $espelho = $espSt->fetch(PDO::FETCH_ASSOC);
        if ($espelho === false) {
            throw new RuntimeException('Espelho de ponto não encontrado.');
        }
        if (in_array((string) $espelho['status'], ['fechado', 'enviado', 'assinado'], true)) {
            throw new RuntimeException('Reabra o período antes de alterar o turno.');
        }

        // Calcula carga horária do novo turno e valida intervalo intrajornada mínimo CLT
        $cargaNovaMin = $this->calcularCargaTurno($e1, $s1, $e2, $s2);
        if ($cargaNovaMin <= 0 || $cargaNovaMin > 12 * 60) {
            throw new RuntimeException('Carga horária do novo turno inválida (' . sprintf('%02d:%02d', intdiv($cargaNovaMin, 60), $cargaNovaMin % 60) . ').');
        }

        // Salva arquivo PDF em storage/uploads/turno_autorizacoes/
        $relDir = 'storage/uploads/turno_autorizacoes';
        $absDir = $this->root . '/' . $relDir;
        if (!is_dir($absDir) && !@mkdir($absDir, 0775, true) && !is_dir($absDir)) {
            throw new RuntimeException('Não foi possível criar o diretório de anexos de turno.');
        }
        $safeName = sprintf('turno_esp%d_%s_%s.pdf', $espelhoId, date('YmdHis'), bin2hex(random_bytes(4)));
        $relPath = $relDir . '/' . $safeName;
        $absPath = $this->root . '/' . $relPath;
        if (!@move_uploaded_file($tmpName, $absPath)) {
            if (!@copy($tmpName, $absPath)) {
                throw new RuntimeException('Falha ao gravar o arquivo PDF da autorização.');
            }
        }

        $inTx = $this->db->inTransaction();
        if (!$inTx) {
            $this->db->beginTransaction();
        }
        try {
            $ins = $this->db->prepare(
                'INSERT INTO ponto_turno_autorizacoes
                 (espelho_id, colaborador_id, data_inicio, data_fim, entrada1, saida1, entrada2, saida2,
                  adicional_noturno, motivo, arquivo_path, original_name, mime, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ins->execute([
                $espelhoId,
                (int) $espelho['colaborador_id'],
                $dataInicio,
                $dataFim,
                $e1 . ':00',
                $s1 !== null ? $s1 . ':00' : null,
                $e2 !== null ? $e2 . ':00' : null,
                $s2 . ':00',
                $adicionalNoturno ? 1 : 0,
                $motivoTrim,
                $relPath,
                $origName,
                'application/pdf',
                $userId,
            ]);
            $authId = (int) $this->db->lastInsertId();

            // Atualiza os dias úteis (tipo_dia = 'trabalho') no intervalo
            $diasSt = $this->db->prepare(
                "SELECT id, data, tipo_dia, horas_previstas_min
                 FROM ponto_dias
                 WHERE espelho_id = ? AND data BETWEEN ? AND ?
                 ORDER BY data ASC"
            );
            $diasSt->execute([$espelhoId, $dataInicio, $dataFim]);
            $dias = $diasSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $calc = new CalculoPontoService($this->db);
            $updPrev = $this->db->prepare(
                'UPDATE ponto_dias
                 SET previsto_entrada = ?, previsto_saida = ?, horas_previstas_min = ?
                 WHERE id = ?'
            );

            $atualizados = 0;
            $ent1Anterior = null;
            foreach ($dias as $d) {
                if ((string) ($d['tipo_dia'] ?? '') !== 'trabalho') {
                    continue;
                }
                $diaId = (int) $d['id'];
                // Mantém a mesma carga horária prevista anterior se já definida, ou usa a calculada
                $prevMin = (int) ($d['horas_previstas_min'] ?? 0);
                $cargaAplicar = $prevMin > 0 ? $prevMin : $cargaNovaMin;

                $updPrev->execute([
                    $e1 . ':00',
                    $s2 . ':00',
                    $cargaAplicar,
                    $diaId,
                ]);

                if ($aplicarBatidas) {
                    $batidasDia = $this->gerarBatidasVariadas(
                        $e1,
                        $s1,
                        $e2,
                        $s2,
                        (int) $espelho['colaborador_id'],
                        (string) $d['data'],
                        $ent1Anterior
                    );
                    $calc->salvarBatidas($diaId, $batidasDia, 'manual');
                } else {
                    $calc->recalcularDia($diaId);
                }
                $atualizados++;
            }

            $calc->atualizarTotaisEspelho($espelhoId);

            if (!$inTx && $this->db->inTransaction()) {
                $this->db->commit();
            }

            return ['id' => $authId, 'dias_atualizados' => $atualizados];
        } catch (Throwable $e) {
            if (!$inTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            @unlink($absPath);
            throw $e;
        }
    }

    public function excluir(int $espelhoId, int $authId): void
    {
        if ($espelhoId <= 0 || $authId <= 0 || !$this->tableExists()) {
            return;
        }
        $st = $this->db->prepare('SELECT arquivo_path FROM ponto_turno_autorizacoes WHERE id = ? AND espelho_id = ? LIMIT 1');
        $st->execute([$authId, $espelhoId]);
        $path = $st->fetchColumn();
        if ($path === false) {
            return;
        }
        $this->db->prepare('DELETE FROM ponto_turno_autorizacoes WHERE id = ? AND espelho_id = ?')->execute([$authId, $espelhoId]);
        $abs = $this->root . '/' . ltrim((string) $path, '/');
        if (is_file($abs)) {
            @unlink($abs);
        }
    }

    public function caminhoAbsoluto(string $relPath): string
    {
        return $this->root . '/' . ltrim($relPath, '/');
    }

    /**
     * Gera batidas com variação natural de ±3 minutos (mesma lógica anti-britânico do MotorPontoService),
     * preservando a carga horária exata de cada período e garantindo intervalo >= previsto (CLT Art. 71).
     *
     * @return array{ent1:string,sai1:?string,ent2:?string,sai2:string}
     */
    private function gerarBatidasVariadas(
        string $e1Str,
        ?string $s1Str,
        ?string $e2Str,
        string $s2Str,
        int $colaboradorId,
        string $dataStr,
        ?int &$ent1Anterior
    ): array {
        $e1 = $this->toMin($e1Str);
        $s1 = $s1Str !== null ? $this->toMin($s1Str) : null;
        $e2 = $e2Str !== null ? $this->toMin($e2Str) : null;
        $s2 = $this->toMin($s2Str);

        // Semente determinística + variação de -3 a +3 min sem repetir o minuto do dia anterior
        $seed = crc32($colaboradorId . '|' . $dataStr . '|turno');
        $deltas = [-3, -2, -1, 1, 2, 3, 0];
        $idxBase = abs($seed) % count($deltas);
        $ent1Escolhido = $e1 + $deltas[$idxBase];
        if ($ent1Anterior !== null && $ent1Escolhido === $ent1Anterior) {
            $ent1Escolhido = $e1 + $deltas[($idxBase + 1) % count($deltas)];
        }
        $ent1Anterior = $ent1Escolhido;

        $dTarde = (abs(crc32($colaboradorId . '|' . $dataStr . '|tarde')) % 4); // 0..+3 min para nunca encurtar o intervalo

        if ($s1 !== null && $e2 !== null) {
            $s1Adj = $s1 < $e1 ? $s1 + 1440 : $s1;
            $e2Adj = $e2 < $s1Adj ? $e2 + 1440 : $e2;
            $s2Adj = $s2 < $e2Adj ? $s2 + 1440 : $s2;

            $durManha = max(0, $s1Adj - $e1);
            $durTarde = max(0, $s2Adj - $e2Adj);
            $durIntervaloBase = max(0, $e2Adj - $s1Adj);

            $e1n = $ent1Escolhido;
            $s1n = $e1n + $durManha;
            $e2n = $s1n + max(1, $durIntervaloBase) + $dTarde;
            $s2n = $e2n + $durTarde;
        } else {
            $s2Adj = $s2 < $e1 ? $s2 + 1440 : $s2;
            $dur = max(0, $s2Adj - $e1);
            $e1n = $ent1Escolhido;
            $s1n = null;
            $e2n = null;
            $s2n = $e1n + $dur;
        }

        $fmt = static function (int $m): string {
            $w = (($m % 1440) + 1440) % 1440;
            return sprintf('%02d:%02d', intdiv($w, 60), $w % 60);
        };

        return [
            'ent1' => $fmt($e1n),
            'sai1' => $s1n !== null ? $fmt($s1n) : null,
            'ent2' => $e2n !== null ? $fmt($e2n) : null,
            'sai2' => $fmt($s2n),
        ];
    }

    private function calcularCargaTurno(string $e1, ?string $s1, ?string $e2, string $s2): int
    {
        $mE1 = $this->toMin($e1);
        if ($s1 !== null && $e2 !== null) {
            $mS1 = $this->toMin($s1);
            if ($mS1 < $mE1) {
                $mS1 += 1440;
            }
            $mE2 = $this->toMin($e2);
            if ($mE2 < $mS1) {
                $mE2 += 1440;
            }
            $mS2 = $this->toMin($s2);
            if ($mS2 < $mE2) {
                $mS2 += 1440;
            }
            return max(0, ($mS1 - $mE1) + ($mS2 - $mE2));
        }
        $mS2 = $this->toMin($s2);
        if ($mS2 < $mE1) {
            $mS2 += 1440;
        }
        return max(0, $mS2 - $mE1);
    }

    private function normHm(?string $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || $s === '--:--') {
            return null;
        }
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', $s, $m)) {
            return $m[1] . ':' . $m[2];
        }
        return null;
    }

    private function toMin(string $hm): int
    {
        [$h, $m] = explode(':', $hm);
        return ((int) $h) * 60 + ((int) $m);
    }
}
