<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class TransferenciaLotacaoService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Fecha a lotação vigente e abre outra a partir da data informada.
     *
     * @return array{fechada_id:?int, nova_id:int}
     */
    public function transferir(
        int $colaboradorId,
        int $novaLotacaoId,
        DateTimeImmutable|string $dataTransferencia,
        string $motivo = 'transferencia',
        ?string $observacao = null,
        ?int $usuarioId = null,
        ?DateTimeImmutable $escalaAncora = null,
        ?int $percentualHe = null
    ): array {
        $inicio = $dataTransferencia instanceof DateTimeImmutable
            ? $dataTransferencia
            : new DateTimeImmutable($dataTransferencia);

        $motivos = ['admissao', 'transferencia', 'correcao', 'importacao'];
        if (!in_array($motivo, $motivos, true)) {
            throw new InvalidArgumentException('Motivo de transferência inválido.');
        }

        $this->assertLotacaoAtiva($novaLotacaoId);

        return $this->withTransaction(function () use (
            $colaboradorId,
            $novaLotacaoId,
            $inicio,
            $motivo,
            $observacao,
            $usuarioId,
            $escalaAncora,
            $percentualHe
        ): array {
            $aberta = $this->buscarAberta($colaboradorId);

            $fechadaId = null;
            if ($aberta !== null) {
                if ((int) $aberta['lotacao_id'] === $novaLotacaoId) {
                    throw new InvalidArgumentException('Colaborador já está nesta lotação.');
                }

                $inicioAtual = new DateTimeImmutable($aberta['vigencia_inicio']);
                if ($inicio < $inicioAtual) {
                    throw new InvalidArgumentException('Data de transferência anterior ao início da lotação vigente.');
                }

                $fim = $inicio->sub(new DateInterval('P1D'));
                if ($fim < $inicioAtual) {
                    // transferência no mesmo dia do início: encerra no próprio dia
                    $fim = $inicioAtual;
                }

                $stmt = $this->db->prepare(
                    'UPDATE rh_colaborador_lotacoes SET vigencia_fim = :fim WHERE id = :id'
                );
                $stmt->execute([
                    'fim' => $fim->format('Y-m-d'),
                    'id' => $aberta['id'],
                ]);
                $fechadaId = (int) $aberta['id'];
            }

            if ($percentualHe !== null && ($percentualHe < 1 || $percentualHe > 200)) {
                throw new InvalidArgumentException('Informe o percentual de HE entre 1 e 200.');
            }

            $stmt = $this->db->prepare(
                'INSERT INTO rh_colaborador_lotacoes
                (colaborador_id, lotacao_id, vigencia_inicio, vigencia_fim, escala_ancora_data, percentual_he, motivo, observacao, usuario_id)
                VALUES
                (:colaborador_id, :lotacao_id, :inicio, NULL, :ancora, :percentual_he, :motivo, :observacao, :usuario_id)'
            );
            $stmt->execute([
                'colaborador_id' => $colaboradorId,
                'lotacao_id' => $novaLotacaoId,
                'inicio' => $inicio->format('Y-m-d'),
                'ancora' => $escalaAncora?->format('Y-m-d'),
                'percentual_he' => $percentualHe,
                'motivo' => $motivo,
                'observacao' => $observacao,
                'usuario_id' => $usuarioId,
            ]);

            $novaId = (int) $this->db->lastInsertId();

            $this->auditar($colaboradorId, $fechadaId, $novaId, $usuarioId);

            return [
                'fechada_id' => $fechadaId,
                'nova_id' => $novaId,
            ];
        });
    }

    /**
     * Encerra a lotação aberta (ex.: desligamento). Não abre vínculo novo.
     */
    public function encerrarLotacaoAberta(
        int $colaboradorId,
        DateTimeImmutable|string|null $dataFim = null,
        ?int $usuarioId = null
    ): bool {
        $fim = $dataFim instanceof DateTimeImmutable
            ? $dataFim
            : new DateTimeImmutable(is_string($dataFim) && $dataFim !== '' ? $dataFim : 'today');

        return (bool) $this->withTransaction(function () use ($colaboradorId, $fim, $usuarioId): bool {
            $aberta = $this->buscarAberta($colaboradorId);
            if ($aberta === null) {
                return false;
            }

            $inicioAtual = new DateTimeImmutable((string) $aberta['vigencia_inicio']);
            if ($fim < $inicioAtual) {
                $fim = $inicioAtual;
            }

            $stmt = $this->db->prepare(
                'UPDATE rh_colaborador_lotacoes SET vigencia_fim = :fim WHERE id = :id AND vigencia_fim IS NULL'
            );
            $stmt->execute([
                'fim' => $fim->format('Y-m-d'),
                'id' => (int) $aberta['id'],
            ]);

            if ($stmt->rowCount() > 0) {
                $this->db->prepare(
                    'INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
                     VALUES (\'rh_colaboradores\', :id, \'encerrar_lotacao\', :antes, :depois, :u)'
                )->execute([
                    'id' => $colaboradorId,
                    'antes' => json_encode(['colaborador_lotacao_id' => (int) $aberta['id']], JSON_UNESCAPED_UNICODE),
                    'depois' => json_encode([
                        'colaborador_lotacao_id' => (int) $aberta['id'],
                        'vigencia_fim' => $fim->format('Y-m-d'),
                        'resumo' => 'Lotação vigente encerrada em ' . $fim->format('Y-m-d') . ' (desligamento).',
                    ], JSON_UNESCAPED_UNICODE),
                    'u' => $usuarioId,
                ]);
            }

            return $stmt->rowCount() > 0;
        });
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buscarAberta(int $colaboradorId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM rh_colaborador_lotacoes
             WHERE colaborador_id = :id AND vigencia_fim IS NULL
             LIMIT 1'
        );
        $stmt->execute(['id' => $colaboradorId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Atualiza o % de HE do vínculo vigente (NULL = herda da lotação).
     */
    public function atualizarPercentualHe(int $colaboradorId, ?int $percentualHe, ?int $usuarioId = null): void
    {
        if ($percentualHe !== null && ($percentualHe < 1 || $percentualHe > 200)) {
            throw new InvalidArgumentException('Informe o percentual de HE entre 1 e 200.');
        }

        $this->withTransaction(function () use ($colaboradorId, $percentualHe, $usuarioId): void {
            $aberta = $this->buscarAberta($colaboradorId);
            if ($aberta === null) {
                throw new InvalidArgumentException('Colaborador sem lotação vigente.');
            }

            $antes = $aberta['percentual_he'] ?? null;
            $stmt = $this->db->prepare(
                'UPDATE rh_colaborador_lotacoes SET percentual_he = :pct WHERE id = :id'
            );
            $stmt->execute([
                'pct' => $percentualHe,
                'id' => $aberta['id'],
            ]);

            $de = $antes === null || $antes === '' ? 'herdado da lotação' : ((int) $antes . '%');
            $para = $percentualHe === null ? 'herdado da lotação' : ($percentualHe . '%');
            $this->db->prepare(
                'INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
                 VALUES (\'rh_colaboradores\', :id, \'percentual_he\', :antes, :depois, :u)'
            )->execute([
                'id' => $colaboradorId,
                'antes' => json_encode(['percentual_he' => $antes], JSON_UNESCAPED_UNICODE),
                'depois' => json_encode([
                    'percentual_he' => $percentualHe,
                    'resumo' => "Percentual de HE: {$de} → {$para}.",
                ], JSON_UNESCAPED_UNICODE),
                'u' => $usuarioId,
            ]);
        });
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function withTransaction(callable $fn): mixed
    {
        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }
        try {
            $result = $fn();
            if ($owns) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function assertLotacaoAtiva(int $lotacaoId): void
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM rh_lotacoes WHERE id = :id AND status = 'ativo' LIMIT 1"
        );
        $stmt->execute(['id' => $lotacaoId]);
        if ($stmt->fetch() === false) {
            throw new RuntimeException('Lotação inexistente ou inativa.');
        }
    }

    private function auditar(int $colaboradorId, ?int $fechadaId, int $novaId, ?int $usuarioId): void
    {
        $depois = [
            'colaborador_lotacao_id' => $novaId,
            'nova_id' => $novaId,
        ];

        $st = $this->db->prepare(
            'SELECT l.codigo, l.nome,
                    TRIM(CONCAT(COALESCE(loc.municipio, \'\'), \'/\', COALESCE(loc.uf, \'\'))) AS localidade,
                    cl.vigencia_inicio, cl.motivo, cl.percentual_he
             FROM rh_colaborador_lotacoes cl
             INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
             LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
             WHERE cl.id = :id
             LIMIT 1'
        );
        $st->execute(['id' => $novaId]);
        $info = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($info)) {
            $codigo = (string) ($info['codigo'] ?? '');
            $nome = (string) ($info['nome'] ?? '');
            $local = rtrim((string) ($info['localidade'] ?? ''), '/');
            $inicio = (string) ($info['vigencia_inicio'] ?? '');
            $motivo = (string) ($info['motivo'] ?? '');
            $pct = $info['percentual_he'] ?? null;
            $motivos = [
                'admissao' => 'Admissão',
                'transferencia' => 'Transferência',
                'correcao' => 'Correção',
                'importacao' => 'Importação',
            ];
            $resumo = 'Nova lotação: ' . $codigo . ($nome !== '' ? ' — ' . $nome : '');
            if ($local !== '' && $local !== '/') {
                $resumo .= ' · ' . $local;
            }
            if ($inicio !== '') {
                $resumo .= ' · a partir de ' . $inicio;
            }
            if ($motivo !== '') {
                $resumo .= ' · motivo ' . ($motivos[$motivo] ?? $motivo);
            }
            if ($pct !== null && $pct !== '') {
                $resumo .= ' · HE ' . (int) $pct . '%';
            }
            $depois['resumo'] = $resumo;
            $depois['lotacao_codigo'] = $codigo;
            $depois['lotacao_nome'] = $nome;
            $depois['localidade'] = $local;
            $depois['vigencia_inicio'] = $inicio;
            $depois['motivo'] = $motivo;
            $depois['percentual_he'] = $pct !== null && $pct !== '' ? (int) $pct : null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
             VALUES (:entidade, :entidade_id, :acao, :antes, :depois, :usuario_id)'
        );
        $stmt->execute([
            'entidade' => 'rh_colaboradores',
            'entidade_id' => $colaboradorId,
            'acao' => 'transferencia_lotacao',
            'antes' => json_encode(['colaborador_lotacao_id' => $fechadaId], JSON_UNESCAPED_UNICODE),
            'depois' => json_encode($depois, JSON_UNESCAPED_UNICODE),
            'usuario_id' => $usuarioId,
        ]);
    }
}
