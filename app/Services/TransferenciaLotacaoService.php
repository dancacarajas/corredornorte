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

        $this->db->beginTransaction();
        try {
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

            $this->db->commit();

            return [
                'fechada_id' => $fechadaId,
                'nova_id' => $novaId,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
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
    public function atualizarPercentualHe(int $colaboradorId, ?int $percentualHe): void
    {
        if ($percentualHe !== null && ($percentualHe < 1 || $percentualHe > 200)) {
            throw new InvalidArgumentException('Informe o percentual de HE entre 1 e 200.');
        }

        $aberta = $this->buscarAberta($colaboradorId);
        if ($aberta === null) {
            throw new InvalidArgumentException('Colaborador sem lotação vigente.');
        }

        $stmt = $this->db->prepare(
            'UPDATE rh_colaborador_lotacoes SET percentual_he = :pct WHERE id = :id'
        );
        $stmt->execute([
            'pct' => $percentualHe,
            'id' => $aberta['id'],
        ]);
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
        $stmt = $this->db->prepare(
            'INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
             VALUES (:entidade, :entidade_id, :acao, :antes, :depois, :usuario_id)'
        );
        $stmt->execute([
            'entidade' => 'rh_colaboradores',
            'entidade_id' => $colaboradorId,
            'acao' => 'transferencia_lotacao',
            'antes' => json_encode(['colaborador_lotacao_id' => $fechadaId], JSON_UNESCAPED_UNICODE),
            'depois' => json_encode(['colaborador_lotacao_id' => $novaId], JSON_UNESCAPED_UNICODE),
            'usuario_id' => $usuarioId,
        ]);
    }
}
