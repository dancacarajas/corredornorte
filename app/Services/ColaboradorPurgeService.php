<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Exclusão permanente de colaborador e de todo o rastro operacional no BD/arquivos.
 */
final class ColaboradorPurgeService
{
    private PDO $db;

    /** @var list<string> */
    private array $filesToUnlink = [];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{id:int,matricula:string,nome:string}
     */
    public function apagar(int $colaboradorId): array
    {
        if ($colaboradorId <= 0) {
            throw new RuntimeException('Colaborador inválido.');
        }

        $owns = !$this->db->inTransaction();
        if ($owns) {
            $this->db->beginTransaction();
        }

        try {
            $st = $this->db->prepare('SELECT id, matricula, nome FROM rh_colaboradores WHERE id = :id LIMIT 1');
            $st->execute(['id' => $colaboradorId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new RuntimeException('Colaborador não encontrado.');
            }

            $this->desvincularReferencias($colaboradorId);
            $this->apagarEspelhosEDocumentos($colaboradorId);
            $this->apagarFilhosDiretos($colaboradorId);

            $del = $this->db->prepare('DELETE FROM rh_colaboradores WHERE id = :id');
            $del->execute(['id' => $colaboradorId]);
            if ($del->rowCount() <= 0) {
                throw new RuntimeException('Falha ao apagar o colaborador no banco.');
            }

            if ($owns) {
                $this->db->commit();
                $this->apagarArquivosPendentes();
            }
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($owns) {
                $this->filesToUnlink = [];
            }
            throw $e;
        }

        return [
            'id' => (int) $row['id'],
            'matricula' => (string) $row['matricula'],
            'nome' => (string) $row['nome'],
        ];
    }

    /** Remove arquivos de PDF após commit externo (exclusão em massa). */
    public function apagarArquivosPendentes(): void
    {
        foreach (array_unique($this->filesToUnlink) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->filesToUnlink = [];
    }

    private function desvincularReferencias(int $id): void
    {
        // HE de terceiros onde este colaborador era gestor/aprovador.
        $this->execSafe(
            'UPDATE he_solicitacoes SET aprovado_por_colaborador_id = NULL WHERE aprovado_por_colaborador_id = :id AND colaborador_id <> :id2',
            ['id' => $id, 'id2' => $id]
        );
        $fallbackGestor = (int) ($this->db->query(
            "SELECT id FROM rh_colaboradores WHERE id <> {$id} AND status = 'ativo' ORDER BY id LIMIT 1"
        )->fetchColumn() ?: 0);
        if ($fallbackGestor > 0) {
            $this->execSafe(
                'UPDATE he_solicitacoes SET gestor_id = :g WHERE gestor_id = :id AND colaborador_id <> :id2',
                ['g' => $fallbackGestor, 'id' => $id, 'id2' => $id]
            );
        } else {
            $this->execSafe(
                'DELETE FROM he_solicitacoes WHERE gestor_id = :id AND colaborador_id <> :id2',
                ['id' => $id, 'id2' => $id]
            );
        }

        // Linhas de importação: preserva histórico da importação, remove vínculo
        $this->execSafe('UPDATE rh_importacoes_linhas SET colaborador_id = NULL WHERE colaborador_id = :id', ['id' => $id]);

        // Usuário portal vinculado (se existir a coluna)
        if ($this->hasColumn('usuarios', 'colaborador_id')) {
            $this->execSafe('UPDATE usuarios SET colaborador_id = NULL WHERE colaborador_id = :id', ['id' => $id]);
        }
    }

    private function apagarEspelhosEDocumentos(int $colaboradorId): void
    {
        $esp = $this->db->prepare('SELECT id FROM ponto_espelhos WHERE colaborador_id = :id');
        $esp->execute(['id' => $colaboradorId]);
        $espelhoIds = array_map('intval', $esp->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $docIds = [];
        if ($espelhoIds !== []) {
            $in = implode(',', $espelhoIds);
            $docIds = array_map(
                'intval',
                $this->db->query(
                    "SELECT DISTINCT documento_id FROM documentos_espelhos WHERE espelho_id IN ($in)
                     UNION
                     SELECT id FROM documentos WHERE espelho_id IN ($in)"
                )->fetchAll(PDO::FETCH_COLUMN) ?: []
            );
        }

        // Documentos RA do colaborador
        $raDocs = $this->db->prepare(
            'SELECT d.id, d.arquivo_path, d.arquivo_assinado_path
             FROM ra_documentos d
             INNER JOIN ra_relatorios r ON r.id = d.relatorio_id
             WHERE r.colaborador_id = :id'
        );
        try {
            $raDocs->execute(['id' => $colaboradorId]);
            foreach ($raDocs->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
                $this->coletarArquivo($d['arquivo_path'] ?? null);
                $this->coletarArquivo($d['arquivo_assinado_path'] ?? null);
            }
        } catch (Throwable) {
            // schema sem colunas de arquivo assinado
        }

        if ($docIds !== []) {
            $inDoc = implode(',', array_unique($docIds));
            $paths = $this->db->query(
                "SELECT arquivo_path, arquivo_assinado_path FROM documentos WHERE id IN ($inDoc)"
            );
            try {
                foreach ($paths->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
                    $this->coletarArquivo($d['arquivo_path'] ?? null);
                    $this->coletarArquivo($d['arquivo_assinado_path'] ?? null);
                }
            } catch (Throwable) {
                $paths = $this->db->query("SELECT arquivo_path FROM documentos WHERE id IN ($inDoc)");
                foreach ($paths->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) {
                    $this->coletarArquivo($d['arquivo_path'] ?? null);
                }
            }

            $this->execSafe("DELETE FROM emails_envios WHERE documento_id IN ($inDoc)");
            $this->execSafe("DELETE FROM cobranca_envios WHERE documento_id IN ($inDoc)");
            $this->execSafe("DELETE FROM alerta_eventos WHERE documento_id IN ($inDoc)");
            $this->execSafe("DELETE FROM ponto_divergencias WHERE documento_id IN ($inDoc)");
            $this->execSafe("DELETE FROM documentos_versoes WHERE documento_id IN ($inDoc)");
            $this->execSafe("DELETE FROM documentos_espelhos WHERE documento_id IN ($inDoc)");
        }

        if ($espelhoIds !== []) {
            $in = implode(',', $espelhoIds);

            // RA ligados ao espelho / colaborador
            $raIds = array_map(
                'intval',
                $this->db->query(
                    "SELECT id FROM ra_relatorios WHERE colaborador_id = {$colaboradorId} OR espelho_id IN ($in)"
                )->fetchAll(PDO::FETCH_COLUMN) ?: []
            );
            if ($raIds !== []) {
                $inRa = implode(',', $raIds);
                $this->execSafe("DELETE FROM ra_dias WHERE relatorio_id IN ($inRa)");
                $this->execSafe("DELETE FROM ra_documentos WHERE relatorio_id IN ($inRa)");
                $this->execSafe("DELETE FROM ra_relatorios WHERE id IN ($inRa)");
            }

            $diaIds = array_map(
                'intval',
                $this->db->query("SELECT id FROM ponto_dias WHERE espelho_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) ?: []
            );
            if ($diaIds !== []) {
                $inDia = implode(',', $diaIds);
                $this->execSafe("DELETE FROM ponto_marcacoes WHERE ponto_dia_id IN ($inDia)");
                $this->execSafe("DELETE FROM ponto_ocorrencias WHERE ponto_dia_id IN ($inDia)");
                $this->execSafe("DELETE FROM ponto_justificativas WHERE ponto_dia_id IN ($inDia)");
                $this->execSafe("UPDATE he_solicitacoes SET ponto_dia_id = NULL WHERE ponto_dia_id IN ($inDia)");
            }

            $this->execSafe("DELETE FROM ponto_ajustes WHERE espelho_id IN ($in)");
            $this->execSafe("DELETE FROM ponto_fechamentos WHERE espelho_id IN ($in)");
            $this->execSafe("DELETE FROM ponto_justificativas WHERE espelho_id IN ($in)");
            $this->execSafe("DELETE FROM ponto_divergencias WHERE espelho_id IN ($in)");
            $this->execSafe("DELETE FROM alerta_eventos WHERE espelho_id IN ($in)");
            $this->execSafe("DELETE FROM documentos_espelhos WHERE espelho_id IN ($in)");
            $this->execSafe("DELETE FROM documentos WHERE espelho_id IN ($in)");
            if ($diaIds !== []) {
                $this->execSafe('DELETE FROM ponto_dias WHERE id IN (' . implode(',', $diaIds) . ')');
            }
            $this->execSafe("DELETE FROM ponto_espelhos WHERE id IN ($in)");
        }

        // RA restantes só por colaborador (sem espelho)
        $raIds = array_map(
            'intval',
            $this->db->query(
                "SELECT id FROM ra_relatorios WHERE colaborador_id = {$colaboradorId}"
            )->fetchAll(PDO::FETCH_COLUMN) ?: []
        );
        if ($raIds !== []) {
            $inRa = implode(',', $raIds);
            $this->execSafe("DELETE FROM ra_dias WHERE relatorio_id IN ($inRa)");
            $this->execSafe("DELETE FROM ra_documentos WHERE relatorio_id IN ($inRa)");
            $this->execSafe("DELETE FROM ra_relatorios WHERE id IN ($inRa)");
        }

        if ($docIds !== []) {
            $this->execSafe('DELETE FROM documentos WHERE id IN (' . implode(',', array_unique($docIds)) . ')');
        }
    }

    private function apagarFilhosDiretos(int $id): void
    {
        $this->execSafe('DELETE FROM he_solicitacoes WHERE colaborador_id = :id', ['id' => $id]);
        $this->execSafe('DELETE FROM ponto_divergencias WHERE colaborador_id = :id', ['id' => $id]);
        $this->execSafe('DELETE FROM alerta_eventos WHERE colaborador_id = :id', ['id' => $id]);
        $this->execSafe('DELETE FROM rh_colaborador_excecoes WHERE colaborador_id = :id', ['id' => $id]);
        $this->execSafe('DELETE FROM rh_colaborador_lotacoes WHERE colaborador_id = :id', ['id' => $id]);
        $this->execSafe(
            "DELETE FROM rh_auditoria WHERE entidade = 'rh_colaboradores' AND entidade_id = :id",
            ['id' => $id]
        );
    }

    /** @param array<string,mixed> $params */
    private function execSafe(string $sql, array $params = []): void
    {
        try {
            $st = $this->db->prepare($sql);
            $st->execute($params);
        } catch (Throwable) {
            // Tabela/coluna ausente em ambientes parciais: segue o purge do que existir.
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $st = $this->db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
            );
            $st->execute(['t' => $table, 'c' => $column]);
            return (bool) $st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    private function coletarArquivo(mixed $relativo): void
    {
        $rel = trim(str_replace('\\', '/', (string) $relativo));
        if ($rel === '' || str_contains($rel, '..')) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $abs = $root . '/' . ltrim($rel, '/');
        $this->filesToUnlink[] = $abs;
    }

}
