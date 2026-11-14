<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;
use Throwable;

final class HeClienteService
{
    private PDO $db;
    private HeAutorizacaoService $he;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->he = new HeAutorizacaoService($this->db);
    }

    public function colaboradorUsaFluxoCliente(int $colaboradorId): bool
    {
        if (!$this->temColunaHeFluxo()) {
            return false;
        }
        $st = $this->db->prepare('SELECT he_fluxo FROM rh_colaboradores WHERE id = :id');
        $st->execute(['id' => $colaboradorId]);
        return (string) $st->fetchColumn() === 'cliente';
    }

    /** @return list<array{id:int,nome:string,cliente:?string}> */
    public function listarGestoresCliente(): array
    {
        if (!$this->tableExists('rh_gestores_cliente')) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT id, nome, cliente FROM rh_gestores_cliente WHERE status = 'ativo' ORDER BY nome"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'nome' => (string) $r['nome'],
            'cliente' => $r['cliente'] !== null ? (string) $r['cliente'] : null,
        ], $rows);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listarDeclaracoesDoEspelho(int $espelhoId): array
    {
        if (!$this->temColunasRaHe()) {
            return [];
        }
        $st = $this->db->prepare(
            "SELECT d.id AS ra_dia_id, d.data, d.he_hora_inicio, d.he_hora_fim, d.he_status,
                    d.he_aplicada_em, d.he_sai2_anterior, d.observacoes,
                    r.id AS relatorio_id, r.status AS relatorio_status,
                    c.he_fluxo, gc.nome AS gestor_cliente_nome
             FROM ponto_espelhos e
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             INNER JOIN ra_relatorios r
                ON r.colaborador_id = e.colaborador_id AND r.periodo_id = e.periodo_id
             INNER JOIN ra_dias d ON d.relatorio_id = r.id
             LEFT JOIN rh_gestores_cliente gc ON gc.id = c.gestor_cliente_id
             WHERE e.id = :e
               AND d.he_hora_inicio IS NOT NULL
               AND d.he_hora_fim IS NOT NULL
               AND d.he_status IN ('declarada','aplicada','divergente')
             ORDER BY d.data, d.id"
        );
        $st->execute(['e' => $espelhoId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $key = (string) $row['data'] . ':' . (int) $row['ra_dia_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $ini = substr((string) $row['he_hora_inicio'], 0, 8);
            $fim = substr((string) $row['he_hora_fim'], 0, 8);
            $out[] = [
                'ra_dia_id' => (int) $row['ra_dia_id'],
                'data' => (string) $row['data'],
                'he_hora_inicio' => $ini,
                'he_hora_fim' => $fim,
                'minutos' => $this->diffMinutos($ini, $fim),
                'he_status' => (string) $row['he_status'],
                'he_aplicada_em' => $row['he_aplicada_em'] !== null ? (string) $row['he_aplicada_em'] : null,
                'he_sai2_anterior' => $row['he_sai2_anterior'] !== null ? (string) $row['he_sai2_anterior'] : null,
                'relatorio_id' => (int) $row['relatorio_id'],
                'relatorio_status' => (string) $row['relatorio_status'],
                'gestor_cliente_nome' => $row['gestor_cliente_nome'] !== null ? (string) $row['gestor_cliente_nome'] : null,
            ];
        }
        return $out;
    }

    public function aplicarDoRaDia(int $raDiaId, int $usuarioId): void
    {
        if (!$this->temColunasRaHe()) {
            throw new RuntimeException('Migração de HE Cliente pendente.');
        }
        $st = $this->db->prepare(
            'SELECT d.*, r.colaborador_id, c.he_fluxo
             FROM ra_dias d
             INNER JOIN ra_relatorios r ON r.id = d.relatorio_id
             INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
             WHERE d.id = :id
             LIMIT 1'
        );
        $st->execute(['id' => $raDiaId]);
        $dia = $st->fetch(PDO::FETCH_ASSOC);
        if ($dia === false) {
            throw new RuntimeException('Dia do relatório não encontrado.');
        }
        if ((string) ($dia['he_fluxo'] ?? 'off') !== 'cliente') {
            throw new RuntimeException('Colaborador não está no fluxo HE Cliente.');
        }
        if ((string) ($dia['he_status'] ?? '') === 'aplicada') {
            throw new RuntimeException('HE deste dia já foi aplicada no ponto.');
        }
        $ini = $this->normHora((string) ($dia['he_hora_inicio'] ?? ''));
        $fim = $this->normHora((string) ($dia['he_hora_fim'] ?? ''));
        if ($ini === null || $fim === null) {
            throw new RuntimeException('Informe início e fim da HE no relatório.');
        }
        $minutos = $this->diffMinutos($ini, $fim);
        if ($minutos <= 0) {
            throw new RuntimeException('Hora final deve ser após a inicial.');
        }

        $colaboradorId = (int) $dia['colaborador_id'];
        $data = (string) $dia['data'];

        $resultado = $this->he->aplicarExtraNoCartao(
            $colaboradorId,
            $data,
            $fim,
            $minutos,
            'HE Cliente (RA #' . (int) $dia['relatorio_id'] . ') — saída ajustada para ' . substr($fim, 0, 5)
        );
        $this->db->prepare(
            "UPDATE ra_dias
             SET he_status = 'aplicada',
                 he_aplicada_em = NOW(),
                 he_aplicada_por_usuario_id = :u,
                 he_ponto_dia_id = :dia,
                 he_sai2_anterior = :sai2,
                 updated_at = NOW()
             WHERE id = :id"
        )->execute([
            'u' => $usuarioId > 0 ? $usuarioId : null,
            'dia' => $resultado['ponto_dia_id'],
            'sai2' => $resultado['sai2_anterior'],
            'id' => $raDiaId,
        ]);
    }

    public function marcarDivergente(int $raDiaId, int $usuarioId): void
    {
        if (!$this->temColunasRaHe()) {
            throw new RuntimeException('Migração de HE Cliente pendente.');
        }
        $st = $this->db->prepare(
            "UPDATE ra_dias
             SET he_status = 'divergente',
                 he_aplicada_em = NOW(),
                 he_aplicada_por_usuario_id = :u,
                 updated_at = NOW()
             WHERE id = :id AND he_status IN ('declarada','divergente')"
        );
        $st->execute([
            'u' => $usuarioId > 0 ? $usuarioId : null,
            'id' => $raDiaId,
        ]);
        if ($st->rowCount() < 1) {
            throw new RuntimeException('Não foi possível marcar divergência neste dia.');
        }
    }

    /**
     * Normaliza payload HE do formulário do RA.
     * @param array<string,mixed>|null $diaPonto Dia do RA já enriquecido com batidas do ponto
     * @return array{he_hora_inicio:?string,he_hora_fim:?string,he_status:string}
     */
    public function normalizarCamposHeDia(
        array $item,
        string $statusAtual = 'nenhuma',
        ?array $diaPonto = null,
        ?string $dataRef = null
    ): array {
        $iniRaw = trim((string) ($item['he_hora_inicio'] ?? ''));
        $fimRaw = trim((string) ($item['he_hora_fim'] ?? ''));
        if ($iniRaw === '' && $fimRaw === '') {
            return [
                'he_hora_inicio' => null,
                'he_hora_fim' => null,
                'he_status' => $statusAtual === 'aplicada' ? 'aplicada' : 'nenhuma',
            ];
        }
        $ini = $this->normHora($iniRaw);
        $fim = $this->normHora($fimRaw);
        if ($ini === null || $fim === null) {
            throw new RuntimeException('HE: informe início e fim no formato HH:MM.');
        }
        if ($this->diffMinutos($ini, $fim) <= 0) {
            throw new RuntimeException('HE: a hora final deve ser após a inicial.');
        }

        $ultima = $diaPonto !== null ? $this->ultimaBatidaPonto($diaPonto) : null;
        if ($ultima !== null && $this->toMin($ini) < $this->toMin($ultima . ':00')) {
            $rotuloData = $dataRef ?? (string) ($diaPonto['data'] ?? '');
            $prefixo = $rotuloData !== '' ? 'Em ' . date('d/m', strtotime($rotuloData) ?: time()) . ': ' : '';
            throw new RuntimeException(
                $prefixo . 'HE início deve ser a partir da última batida do ponto (' . $ultima . ').'
            );
        }

        if ($statusAtual === 'aplicada') {
            return [
                'he_hora_inicio' => $ini,
                'he_hora_fim' => $fim,
                'he_status' => 'aplicada',
            ];
        }
        return [
            'he_hora_inicio' => $ini,
            'he_hora_fim' => $fim,
            'he_status' => 'declarada',
        ];
    }

    /**
     * Última marcação do cartão (ordem ent1 → sai1 → ent2 → sai2).
     * @param array<string,mixed> $dia
     */
    public function ultimaBatidaPonto(array $dia): ?string
    {
        $ultima = null;
        foreach (['ponto_ent1', 'ponto_sai1', 'ponto_ent2', 'ponto_sai2'] as $campo) {
            $hora = $this->horaCurta($dia[$campo] ?? null);
            if ($hora !== null) {
                $ultima = $hora;
            }
        }
        return $ultima;
    }

    /**
     * Rótulo amigável das batidas, ex.: "07:00 → 12:00 · 13:00 → 17:00".
     * @param array<string,mixed> $dia
     */
    public function rotuloBatidasPonto(array $dia): string
    {
        $pares = [];
        $e1 = $this->horaCurta($dia['ponto_ent1'] ?? null);
        $s1 = $this->horaCurta($dia['ponto_sai1'] ?? null);
        $e2 = $this->horaCurta($dia['ponto_ent2'] ?? null);
        $s2 = $this->horaCurta($dia['ponto_sai2'] ?? null);
        if ($e1 !== null || $s1 !== null) {
            $pares[] = ($e1 ?? '—') . ' → ' . ($s1 ?? '—');
        }
        if ($e2 !== null || $s2 !== null) {
            $pares[] = ($e2 ?? '—') . ' → ' . ($s2 ?? '—');
        }
        return $pares === [] ? '' : implode(' · ', $pares);
    }

    private function horaCurta(mixed $hora): ?string
    {
        if ($hora === null) {
            return null;
        }
        $txt = trim((string) $hora);
        if ($txt === '') {
            return null;
        }
        if (preg_match('/^(\d{2}:\d{2})/', $txt, $m)) {
            return $m[1];
        }
        return null;
    }

    private function toMin(string $hora): int
    {
        $parts = explode(':', substr($hora, 0, 8));
        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }

    private function temColunaHeFluxo(): bool
    {
        return $this->hasColumn('rh_colaboradores', 'he_fluxo');
    }

    private function temColunasRaHe(): bool
    {
        return $this->hasColumn('ra_dias', 'he_hora_inicio') && $this->hasColumn('ra_dias', 'he_status');
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $st->execute(['t' => $table]);
        return (bool) $st->fetchColumn();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $st = $this->db->prepare(
            'SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $st->execute(['t' => $table, 'c' => $column]);
        return (bool) $st->fetchColumn();
    }

    private function normHora(string $hora): ?string
    {
        $hora = trim($hora);
        if (preg_match('/^\d{2}:\d{2}$/', $hora)) {
            return $hora . ':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hora)) {
            return $hora;
        }
        return null;
    }

    private function diffMinutos(string $ini, string $fim): int
    {
        $a = strtotime('1970-01-01 ' . substr($ini, 0, 8));
        $b = strtotime('1970-01-01 ' . substr($fim, 0, 8));
        if ($a === false || $b === false) {
            return 0;
        }
        if ($b < $a) {
            $b += 86400;
        }
        return (int) (($b - $a) / 60);
    }
}
