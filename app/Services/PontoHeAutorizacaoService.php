<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Anexos de autorização de HE no cartão de ponto (comprovante externo / SharePoint).
 * Bloqueia HE gerada pela edição RH sem anexo vinculado ao dia.
 */
final class PontoHeAutorizacaoService
{
    public const TOLERANCIA_HE_MIN = 5;

    private PDO $db;
    private string $root;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->root = dirname(__DIR__, 2);
    }

    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS ponto_he_autorizacoes (
              id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              espelho_id BIGINT UNSIGNED NOT NULL,
              ponto_dia_id BIGINT UNSIGNED NOT NULL,
              data DATE NOT NULL,
              minutos_he INT UNSIGNED NOT NULL DEFAULT 0,
              arquivo_path VARCHAR(500) NOT NULL,
              mime VARCHAR(120) NULL,
              original_name VARCHAR(255) NULL,
              justificativa_texto TEXT NULL,
              uploaded_by BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uk_he_auth_dia (ponto_dia_id),
              KEY idx_he_auth_espelho (espelho_id),
              KEY idx_he_auth_data (data)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        try {
            $has = $pdo->query(
                "SELECT 1 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'ponto_he_autorizacoes'
                   AND COLUMN_NAME = 'justificativa_texto'
                 LIMIT 1"
            )->fetchColumn();
            if (!$has) {
                $pdo->exec(
                    'ALTER TABLE ponto_he_autorizacoes
                     ADD COLUMN justificativa_texto TEXT NULL AFTER original_name'
                );
            }
        } catch (Throwable) {
        }
    }

    public function tableExists(): bool
    {
        try {
            if ((string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $st = $this->db->query(
                    "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'ponto_he_autorizacoes' LIMIT 1"
                );
                return (bool) $st->fetchColumn();
            }
            $st = $this->db->query(
                "SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ponto_he_autorizacoes' LIMIT 1"
            );
            return (bool) $st->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, array{id:int,path:string,minutos_he:int,data:string,original_name:?string}>
     */
    public function mapPorDiaDoEspelho(int $espelhoId): array
    {
        if ($espelhoId <= 0 || !$this->tableExists()) {
            return [];
        }
        $st = $this->db->prepare(
            'SELECT id, ponto_dia_id, data, minutos_he, arquivo_path, original_name, justificativa_texto
             FROM ponto_he_autorizacoes WHERE espelho_id = ?'
        );
        $st->execute([$espelhoId]);
        $map = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['ponto_dia_id']] = [
                'id' => (int) $row['id'],
                'path' => (string) $row['arquivo_path'],
                'minutos_he' => (int) $row['minutos_he'],
                'data' => (string) $row['data'],
                'original_name' => $row['original_name'] !== null ? (string) $row['original_name'] : null,
                'justificativa' => $row['justificativa_texto'] !== null ? (string) $row['justificativa_texto'] : null,
            ];
        }
        return $map;
    }

    public function temAnexo(int $pontoDiaId): bool
    {
        if ($pontoDiaId <= 0 || !$this->tableExists()) {
            return false;
        }
        $st = $this->db->prepare(
            'SELECT 1
             FROM ponto_he_autorizacoes a
             INNER JOIN ponto_dias d ON d.id = a.ponto_dia_id
             WHERE a.ponto_dia_id = ?
               AND COALESCE(d.horas_extras_min, 0) <= COALESCE(a.minutos_he, 0)
             LIMIT 1'
        );
        $st->execute([$pontoDiaId]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Dias do espelho com HE relevante sem anexo de autorização ou com HE lançada acima da autorizada.
     *
     * @return list<array{ponto_dia_id:int,data:string,minutos_he:int,minutos_autorizados:int}>
     */
    public function diasComHeSemAnexo(int $espelhoId): array
    {
        if ($espelhoId <= 0) {
            return [];
        }
        if (!$this->tableExists()) {
            self::ensureSchema($this->db);
        }
        $sql = 'SELECT d.id AS ponto_dia_id, d.data,
                       COALESCE(d.horas_extras_min, 0) AS minutos_he,
                       COALESCE(a.minutos_he, 0) AS minutos_autorizados
                FROM ponto_dias d
                LEFT JOIN ponto_he_autorizacoes a ON a.ponto_dia_id = d.id
                WHERE d.espelho_id = ?
                  AND COALESCE(d.horas_extras_min, 0) > ?
                  AND (a.id IS NULL OR COALESCE(d.horas_extras_min, 0) > COALESCE(a.minutos_he, 0))
                ORDER BY d.data';
        $st = $this->db->prepare($sql);
        $st->bindValue(1, $espelhoId, PDO::PARAM_INT);
        $st->bindValue(2, self::TOLERANCIA_HE_MIN, PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'ponto_dia_id' => (int) $row['ponto_dia_id'],
                'data' => (string) $row['data'],
                'minutos_he' => (int) $row['minutos_he'],
                'minutos_autorizados' => (int) $row['minutos_autorizados'],
            ];
        }
        return $out;
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{id:int,path:string,minutos_he:int,justificativa:?string}
     */
    public function anexar(
        int $espelhoId,
        int $pontoDiaId,
        array $file,
        ?int $usuarioId,
        ?int $minutosHe = null,
        ?string $justificativaTexto = null
    ): array {
        // Não chamar ensureSchema() aqui: DDL no MySQL encerra a transação do caller.

        $st = $this->db->prepare(
            'SELECT d.id, d.data, d.espelho_id, COALESCE(d.horas_extras_min, 0) AS he, e.colaborador_id, e.periodo_id
             FROM ponto_dias d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             WHERE d.id = ? AND d.espelho_id = ? LIMIT 1'
        );
        $st->execute([$pontoDiaId, $espelhoId]);
        $dia = $st->fetch(PDO::FETCH_ASSOC);
        if ($dia === false) {
            throw new RuntimeException('Dia não encontrado neste cartão.');
        }

        $justificativa = trim(preg_replace('/\s+/u', ' ', (string) ($justificativaTexto ?? '')) ?? '');
        if ($justificativa !== '' && mb_strlen($justificativa) > 2000) {
            $justificativa = mb_substr($justificativa, 0, 2000);
        }
        if ($justificativa === '') {
            throw new RuntimeException(
                'Informe a justificativa de HE (texto que o colaborador enviou ao gestor). Ela será lançada no Relatório de Atividades desse dia.'
            );
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Envie o PDF/imagem da autorização de hora extra.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Upload inválido da autorização de HE.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 8 * 1024 * 1024) {
            throw new RuntimeException('Arquivo de autorização deve ter até 8 MB.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string) ($finfo->file($tmp) ?: ($file['type'] ?? ''));
        $allowed = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
        if (!isset($allowed[$mime])) {
            throw new RuntimeException('Formato inválido. Use PDF, JPG, PNG ou WEBP.');
        }
        $ext = $allowed[$mime];
        $dir = $this->root . '/storage/uploads/he_autorizacoes';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar pasta de anexos HE.');
        }
        $nome = sprintf(
            'he_e%d_d%d_%s.%s',
            $espelhoId,
            $pontoDiaId,
            date('Ymd_His'),
            $ext
        );
        $dest = $dir . '/' . $nome;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Falha ao gravar o anexo de autorização de HE.');
        }
        $rel = 'storage/uploads/he_autorizacoes/' . $nome;
        $heMin = $minutosHe !== null ? max(0, $minutosHe) : (int) $dia['he'];
        $original = substr((string) ($file['name'] ?? $nome), 0, 255);

        // Substitui anexo anterior do mesmo dia.
        $old = $this->db->prepare('SELECT arquivo_path FROM ponto_he_autorizacoes WHERE ponto_dia_id = ?');
        $old->execute([$pontoDiaId]);
        $oldPath = $old->fetchColumn();
        if (is_string($oldPath) && $oldPath !== '') {
            $absOld = $this->absPath($oldPath);
            if (is_file($absOld)) {
                @unlink($absOld);
            }
            $this->db->prepare('DELETE FROM ponto_he_autorizacoes WHERE ponto_dia_id = ?')->execute([$pontoDiaId]);
        }

        $ins = $this->db->prepare(
            'INSERT INTO ponto_he_autorizacoes
             (espelho_id, ponto_dia_id, data, minutos_he, arquivo_path, mime, original_name, justificativa_texto, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            $espelhoId,
            $pontoDiaId,
            (string) $dia['data'],
            $heMin,
            $rel,
            $mime,
            $original !== '' ? $original : null,
            $justificativa,
            $usuarioId,
        ]);
        $anexoId = (int) $this->db->lastInsertId();

        // Prefill da atividade do dia no RA (evita contradição com a autorização de HE).
        $raSvc = new RelatorioAtividadesService($this->db);
        try {
            $raSvc->preencherDescricaoHeDoDia(
                (int) $dia['colaborador_id'],
                (int) $dia['periodo_id'],
                (string) $dia['data'],
                $justificativa,
                true
            );
        } catch (Throwable) {
        }
        try {
            $prevSt = $this->db->prepare(
                'SELECT previsto_saida, sai2, COALESCE(horas_extras_min, 0) AS he
                 FROM ponto_dias WHERE id = ? LIMIT 1'
            );
            $prevSt->execute([$pontoDiaId]);
            $pontoInfo = $prevSt->fetch(PDO::FETCH_ASSOC) ?: [];
            $raSvc->sincronizarHeDoPontoDia(
                (int) $dia['colaborador_id'],
                (int) $dia['periodo_id'],
                (string) $dia['data'],
                $pontoDiaId,
                isset($pontoInfo['previsto_saida']) ? (string) $pontoInfo['previsto_saida'] : null,
                isset($pontoInfo['sai2']) ? (string) $pontoInfo['sai2'] : null,
                (int) ($pontoInfo['he'] ?? $heMin),
                $usuarioId
            );
        } catch (Throwable) {
        }

        return [
            'id' => $anexoId,
            'path' => $rel,
            'minutos_he' => $heMin,
            'justificativa' => $justificativa,
        ];
    }

    /**
     * Atualiza só a justificativa (quando já há anexo) e sincroniza o RA do dia.
     */
    public function atualizarJustificativa(int $espelhoId, int $pontoDiaId, string $justificativa, ?int $usuarioId = null): void
    {
        $justificativa = trim(preg_replace('/\s+/u', ' ', $justificativa) ?? '');
        if ($justificativa === '') {
            throw new RuntimeException('Informe a justificativa de HE.');
        }
        if (mb_strlen($justificativa) > 2000) {
            $justificativa = mb_substr($justificativa, 0, 2000);
        }

        $st = $this->db->prepare(
            'SELECT d.data, e.colaborador_id, e.periodo_id, a.id AS anexo_id
             FROM ponto_dias d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             LEFT JOIN ponto_he_autorizacoes a ON a.ponto_dia_id = d.id
             WHERE d.id = ? AND d.espelho_id = ? LIMIT 1'
        );
        $st->execute([$pontoDiaId, $espelhoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false || empty($row['anexo_id'])) {
            throw new RuntimeException('Anexe a autorização de HE antes de gravar a justificativa.');
        }

        $this->db->prepare(
            'UPDATE ponto_he_autorizacoes SET justificativa_texto = ?, uploaded_by = COALESCE(?, uploaded_by) WHERE id = ?'
        )->execute([$justificativa, $usuarioId, (int) $row['anexo_id']]);

        $raSvc = new RelatorioAtividadesService($this->db);
        $raSvc->preencherDescricaoHeDoDia(
            (int) $row['colaborador_id'],
            (int) $row['periodo_id'],
            (string) $row['data'],
            $justificativa,
            true
        );
        try {
            $prevSt = $this->db->prepare(
                'SELECT previsto_saida, sai2, COALESCE(horas_extras_min, 0) AS he
                 FROM ponto_dias WHERE id = ? LIMIT 1'
            );
            $prevSt->execute([$pontoDiaId]);
            $pontoInfo = $prevSt->fetch(PDO::FETCH_ASSOC) ?: [];
            $raSvc->sincronizarHeDoPontoDia(
                (int) $row['colaborador_id'],
                (int) $row['periodo_id'],
                (string) $row['data'],
                $pontoDiaId,
                isset($pontoInfo['previsto_saida']) ? (string) $pontoInfo['previsto_saida'] : null,
                isset($pontoInfo['sai2']) ? (string) $pontoInfo['sai2'] : null,
                (int) ($pontoInfo['he'] ?? 0),
                $usuarioId
            );
        } catch (Throwable) {
        }
    }

    public function assertEspelhoPodeConferir(int $espelhoId): void
    {
        $pendentes = $this->diasComHeSemAnexo($espelhoId);
        if ($pendentes === []) {
            return;
        }
        $datas = array_map(
            static function (array $d): string {
                $dt = date('d/m/Y', strtotime($d['data']));
                if (($d['minutos_autorizados'] ?? 0) > 0 && $d['minutos_he'] > $d['minutos_autorizados']) {
                    return sprintf('%s (%d min lançados > %d min autorizados)', $dt, $d['minutos_he'], $d['minutos_autorizados']);
                }
                return $dt;
            },
            $pendentes
        );
        throw new \InvalidArgumentException(
            'Há hora extra sem autorização válida nos dias: ' . implode(', ', $datas)
            . '. Anexe ou atualize o comprovante (SharePoint) cobrindo o total de HE antes de conferir/fechar.'
        );
    }

    /**
     * @param list<int|string> $espelhoIds
     * @return array<int,true>
     */
    public function mapEspelhosComHeSemAnexo(array $espelhoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $espelhoIds))));
        if ($ids === [] || !$this->tableExists()) {
            return [];
        }
        $in = implode(',', $ids);
        $sql = "SELECT d.espelho_id
                FROM ponto_dias d
                LEFT JOIN ponto_he_autorizacoes a ON a.ponto_dia_id = d.id
                WHERE d.espelho_id IN ({$in})
                  AND COALESCE(d.horas_extras_min, 0) > " . self::TOLERANCIA_HE_MIN . "
                  AND (a.id IS NULL OR COALESCE(d.horas_extras_min, 0) > COALESCE(a.minutos_he, 0))
                GROUP BY d.espelho_id";
        $map = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['espelho_id']] = true;
        }
        return $map;
    }

    /**
     * @return list<array{id:int,espelho_id:int,ponto_dia_id:int,data:string,minutos_he:int,path:string,matricula:string,nome:string}>
     */
    public function listarParaDossie(array $espelhoIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $espelhoIds))));
        if ($ids === [] || !$this->tableExists()) {
            return [];
        }
        $in = implode(',', $ids);
        $sql = "SELECT a.id, a.espelho_id, a.ponto_dia_id, a.data, a.minutos_he, a.arquivo_path,
                       c.matricula, c.nome
                FROM ponto_he_autorizacoes a
                INNER JOIN ponto_espelhos e ON e.id = a.espelho_id
                INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                WHERE a.espelho_id IN ({$in})
                ORDER BY c.nome, a.data, a.id";
        $out = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'espelho_id' => (int) $row['espelho_id'],
                'ponto_dia_id' => (int) $row['ponto_dia_id'],
                'data' => (string) $row['data'],
                'minutos_he' => (int) $row['minutos_he'],
                'path' => (string) $row['arquivo_path'],
                'matricula' => (string) $row['matricula'],
                'nome' => (string) $row['nome'],
            ];
        }
        return $out;
    }

    public function absPath(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if (str_starts_with($rel, $this->root)) {
            return $rel;
        }
        return $this->root . '/' . ltrim($rel, '/');
    }
}
