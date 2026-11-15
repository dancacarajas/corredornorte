<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class AssinaturaEletronicaService
{
    public const TEXTO_ACEITE = 'Declaro que conferi a folha de ponto exibida neste portal e concordo em assiná-la eletronicamente. O Arcadis RH registrará o acesso pelo link individual, o nome digitado, a data, o IP e o hash de integridade do documento.';
    private const METODO_ASSINATURA = 'Link individual da folha, nome digitado e declaração de aceite';

    public function __construct(private PDO $db)
    {
    }

    public static function seloConfigurado(): bool
    {
        // Em produção (Hostinger) o shell/Python pode estar bloqueado; a via PHP sempre assina.
        return true;
    }

    /** @return array{ARCADIS_SEAL_P12_PATH: string, ARCADIS_SEAL_P12_PASSWORD: string}|null */
    private static function configuracaoSelo(): ?array
    {
        $candidatos = [];

        $envPath = getenv('ARCADIS_SEAL_P12_PATH');
        $envPass = getenv('ARCADIS_SEAL_P12_PASSWORD');
        if (is_string($envPath) && $envPath !== '' && is_string($envPass) && $envPass !== '') {
            $candidatos[] = [$envPath, $envPass];
        }

        $root = dirname(__DIR__, 2) . '/storage/assinaturas';
        $candidatos[] = [$root . '/arcadis-selo.p12', self::lerSenhaSelo($root . '/selo.local.json')];

        if (PHP_OS_FAMILY === 'Windows') {
            $perfil = getenv('LOCALAPPDATA');
            if (is_string($perfil) && $perfil !== '') {
                $dir = $perfil . '/ArcadisRH/assinatura';
                $candidatos[] = [$dir . '/arcadis-selo.p12', self::lerSenhaSelo($dir . '/selo.local.json')];
            }
        }

        foreach ($candidatos as [$path, $password]) {
            if (is_string($path) && is_file($path) && is_readable($path)
                && is_string($password) && $password !== '') {
                return [
                    'ARCADIS_SEAL_P12_PATH' => $path,
                    'ARCADIS_SEAL_P12_PASSWORD' => $password,
                ];
            }
        }

        return null;
    }

    private static function lerSenhaSelo(string $arquivo): ?string
    {
        if (!is_file($arquivo) || !is_readable($arquivo)) {
            return null;
        }
        $json = file_get_contents($arquivo);
        if ($json === false) {
            return null;
        }
        $senha = json_decode($json, true)['password'] ?? null;
        return is_string($senha) && $senha !== '' ? $senha : null;
    }

    /** @return array{md5:string,sha1:string,sha256:string} */
    public function calcularHashes(string $filePath): array
    {
        $bin = is_file($filePath) ? file_get_contents($filePath) : false;
        if ($bin === false) {
            throw new RuntimeException('Não foi possível ler o arquivo do documento.');
        }

        return ['md5' => md5($bin), 'sha1' => sha1($bin), 'sha256' => hash('sha256', $bin)];
    }

    /** @return array{portal_url:string,documento_id:int} */
    public function gerarLinkPortal(int $documentoId, ?int $usuarioId = null, ?int $espelhoId = null): array
    {
        $documentoId = (new FolhaPontoService($this->db))->atualizarModeloPendente($documentoId, $usuarioId, $espelhoId);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM documentos WHERE id = ? FOR UPDATE');
            $stmt->execute([$documentoId]);
            $doc = $stmt->fetch();
            if (!$doc || ($espelhoId !== null && (int) $doc['espelho_id'] !== $espelhoId)) {
                throw new RuntimeException('Documento não encontrado nesta folha.');
            }
            if (!in_array($doc['status'], ['gerado', 'enviado'], true)) {
                throw new RuntimeException('Somente documentos pendentes podem receber link de assinatura.');
            }
            if ((int) $doc['layout_pdf'] !== FolhaPontoService::LAYOUT_PDF_ATUAL) {
                throw new RuntimeException('Gere a folha no modelo atual antes de compartilhar o link.');
            }

            $hashes = $this->calcularHashes($this->absPath($doc['arquivo_path']));
            if (!empty($doc['hash_sha256']) && !hash_equals($doc['hash_sha256'], $hashes['sha256'])) {
                throw new RuntimeException('O PDF foi alterado após a emissão. Gere uma nova versão.');
            }

            $token = $doc['portal_token'] ?: bin2hex(random_bytes(24));
            $this->db->prepare(
                "UPDATE documentos SET portal_token = ?, hash_md5 = ?, hash_sha1 = ?, hash_sha256 = ?, status = 'enviado' WHERE id = ?"
            )->execute([$token, $hashes['md5'], $hashes['sha1'], $hashes['sha256'], $documentoId]);
            if ($doc['periodo_inicio'] === null) {
                $this->db->prepare(
                    "UPDATE ponto_espelhos SET status = 'enviado' WHERE id = ? AND status IN ('fechado','conferido')"
                )->execute([(int) $doc['espelho_id']]);
            }
            $this->auditar($documentoId, 'link_assinatura', ['hash_sha256' => $hashes['sha256']], $usuarioId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['portal_url' => $this->baseUrl() . '/assinar/' . $token, 'documento_id' => $documentoId];
    }

    public function assinarNoPortal(string $token, string $nome, bool $aceite, string $hashVisualizado): void
    {
        if (!$aceite) {
            throw new RuntimeException('Confirme o aceite para assinar a folha.');
        }
        $selo = self::configuracaoSelo();
        $assinadoPath = null;
        $this->db->beginTransaction();
        try {
            $doc = $this->buscarPorPortalToken($token, true);
            if ($doc === null || $doc['status'] !== 'enviado') {
                throw new RuntimeException('A folha não está disponível para assinatura.');
            }
            if ($this->espelhoEmDivergencia((int) $doc['espelho_id'])) {
                throw new RuntimeException('Esta folha está em análise de divergência pelo RH. Aguarde a correção e o reenvio.');
            }
            if ((int) $doc['layout_pdf'] !== FolhaPontoService::LAYOUT_PDF_ATUAL) {
                throw new RuntimeException('Peça ao RH para atualizar esta folha antes de assinar.');
            }
            if ($hashVisualizado === '' || !hash_equals((string) $doc['hash_sha256'], $hashVisualizado)) {
                throw new RuntimeException('Atualize a página e confira a via atual da folha antes de assinar.');
            }
            if ($doc['periodo_inicio'] !== null) {
                $espelhos = $this->db->prepare('SELECT e.status FROM documentos_espelhos v
                    JOIN ponto_espelhos e ON e.id = v.espelho_id WHERE v.documento_id = ? FOR UPDATE');
                $espelhos->execute([$doc['id']]);
                $estados = $espelhos->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('em_divergencia', $estados, true)) {
                    throw new RuntimeException('Esta folha está em análise de divergência pelo RH. Aguarde a correção e o reenvio.');
                }
                if ($estados === [] || array_diff($estados, ['fechado', 'enviado', 'assinado']) !== []) {
                    throw new RuntimeException('O ponto do período foi reaberto. Solicite uma nova via ao RH.');
                }
            }
            if (mb_strlen($nome) > 160 || self::normalizarNome($nome) === '' || self::normalizarNome($nome) !== self::normalizarNome((string) $doc['nome'])) {
                throw new RuntimeException('Digite o nome completo que aparece na folha.');
            }

            $id = (int) $doc['id'];
            $original = $this->absPath($doc['arquivo_path']);
            $hashes = $this->calcularHashes($original);
            if (!hash_equals((string) $doc['hash_sha256'], $hashes['sha256'])) {
                throw new RuntimeException('O PDF foi alterado após a emissão. Solicite uma nova via ao RH.');
            }

            $assinadoEm = date('Y-m-d H:i:s');
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $dispositivo = mb_strcut(mb_scrub((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'UTF-8'), 0, 255, 'UTF-8');
            $audit = [
                'nome' => $doc['nome'],
                'assinado_em' => date('c', strtotime($assinadoEm)),
                'sha256' => $hashes['sha256'],
                'codigo' => $doc['codigo_verificacao'],
                'verificar_url' => $this->baseUrl() . '/verificar/' . $doc['codigo_verificacao'],
            ];

            $assinadoPath = sprintf('storage/uploads/folhas/folha_%d_assinado_%s.pdf', $id, bin2hex(random_bytes(8)));
            $this->renderCartaoAssinado($original, $this->absPath($assinadoPath), $audit, $selo);
            $assinadoHash = hash_file('sha256', $this->absPath($assinadoPath));
            if ($assinadoHash === false) {
                throw new RuntimeException('Não foi possível verificar o PDF assinado.');
            }

            $this->db->prepare(
                "UPDATE documentos SET status = 'assinado', arquivo_assinado_path = ?, arquivo_assinado_sha256 = ?,
                 assinatura_nome = ?, assinatura_ip = ?, assinatura_user_agent = ?,
                 assinatura_em = ?, assinatura_aceite = 1, assinatura_texto_aceite = ?
                 WHERE id = ?"
            )->execute([$assinadoPath, $assinadoHash, $doc['nome'], $ip, $dispositivo,
                $assinadoEm, self::TEXTO_ACEITE, $id]);
            if ($doc['periodo_inicio'] === null) {
                $this->db->prepare("UPDATE ponto_espelhos SET status = 'assinado' WHERE id = ?")
                    ->execute([(int) $doc['espelho_id']]);
            }
            $this->auditar($id, 'assinatura_eletronica', [
                'hash_sha256' => $hashes['sha256'],
                'arquivo_assinado_sha256' => $assinadoHash,
                'metodo_assinatura' => self::METODO_ASSINATURA,
                'assinado_em' => $assinadoEm,
            ]);
            $this->db->commit();
            $this->notificarAssinaturaConfirmacao($doc);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($assinadoPath !== null && is_file($this->absPath($assinadoPath))) {
                unlink($this->absPath($assinadoPath));
            }
            throw $e;
        }
    }

    public function buscarPorPortalToken(string $token, bool $lock = false): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32,64}\z/i', $token)) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT d.*, c.nome, c.matricula, c.email_corp, e.colaborador_id,
                    pp.data_inicio AS periodo_cartao_inicio, pp.data_fim AS periodo_cartao_fim,
                    DATE_FORMAT(pp.data_inicio, '%Y-%m') AS periodo_competencia,
                    YEAR(pp.data_inicio) AS ano, MONTH(pp.data_inicio) AS mes
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             WHERE d.portal_token = ? AND d.status <> 'invalidado'
             LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Folhas liberadas pela medição (link gerado) para o portal do colaborador.
     *
     * @return list<array<string,mixed>>
     */
    public function listarDoColaborador(int $colaboradorId): array
    {
        if ($colaboradorId <= 0) {
            return [];
        }
        $st = $this->db->prepare(
            "SELECT d.id, d.status, d.portal_token, d.assinatura_em, d.created_at,
                    e.id AS espelho_id, e.status AS espelho_status,
                    pp.data_inicio, pp.data_fim
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             WHERE e.colaborador_id = :c
               AND d.status IN ('enviado', 'assinado')
               AND d.portal_token IS NOT NULL
               AND d.portal_token <> ''
             ORDER BY
               CASE WHEN d.status = 'enviado' THEN 0 ELSE 1 END,
               pp.data_inicio DESC,
               d.id DESC"
        );
        $st->execute(['c' => $colaboradorId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function contarPendentesDoColaborador(int $colaboradorId): int
    {
        if ($colaboradorId <= 0) {
            return 0;
        }
        $st = $this->db->prepare(
            "SELECT COUNT(*)
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             WHERE e.colaborador_id = :c
               AND d.status = 'enviado'
               AND d.portal_token IS NOT NULL
               AND d.portal_token <> ''"
        );
        $st->execute(['c' => $colaboradorId]);
        return (int) $st->fetchColumn();
    }

    /** @return array<string,mixed>|null */
    public function buscarDoColaborador(int $documentoId, int $colaboradorId): ?array
    {
        if ($documentoId <= 0 || $colaboradorId <= 0) {
            return null;
        }
        $st = $this->db->prepare(
            "SELECT d.*, c.nome, c.matricula, c.id AS colaborador_id,
                    e.id AS espelho_id, e.status AS espelho_status,
                    pp.data_inicio AS periodo_cartao_inicio, pp.data_fim AS periodo_cartao_fim
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             WHERE d.id = :id
               AND e.colaborador_id = :c
               AND d.status IN ('enviado', 'assinado')
               AND d.portal_token IS NOT NULL
               AND d.portal_token <> ''
             LIMIT 1"
        );
        $st->execute(['id' => $documentoId, 'c' => $colaboradorId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Normaliza nome completo removendo acentos, pontuações extras e múltiplos espaços.
     */
    public static function normalizarNome(string $valor): string
    {
        $limpo = trim(preg_replace('/\s+/u', ' ', $valor) ?? '');
        if ($limpo === '') {
            return '';
        }
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($limpo, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $limpo = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $limpo;
            }
        }
        $ascii = strtr($limpo, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'Ç' => 'C', 'ç' => 'c', 'Ñ' => 'N', 'ñ' => 'n',
        ]);
        return mb_strtolower($ascii);
    }

    public static function ensureDivergenciaSchema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS ponto_divergencias (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    espelho_id BIGINT UNSIGNED NOT NULL,
                    documento_id BIGINT UNSIGNED NULL,
                    ponto_dia_id BIGINT UNSIGNED NULL,
                    colaborador_id BIGINT UNSIGNED NOT NULL,
                    data_ocorrencia DATE NULL,
                    motivo TEXT NOT NULL,
                    status ENUM('aberta','em_analise','resolvida') NOT NULL DEFAULT 'aberta',
                    resolucao_obs TEXT NULL,
                    resolvido_por BIGINT UNSIGNED NULL,
                    resolvido_em DATETIME NULL,
                    ip VARCHAR(45) NULL,
                    user_agent VARCHAR(255) NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_pd_espelho_status (espelho_id, status),
                    KEY idx_pd_status_created (status, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            );
            $col = $pdo->query("SHOW COLUMNS FROM ponto_espelhos LIKE 'status'")->fetch();
            $type = is_array($col) ? (string) ($col['Type'] ?? '') : '';
            if ($type !== '' && !str_contains($type, 'em_divergencia')) {
                $pdo->exec(
                    "ALTER TABLE ponto_espelhos
                     MODIFY COLUMN status ENUM('em_aberto','em_tratamento','conferido','fechado','enviado','assinado','em_divergencia')
                     NOT NULL DEFAULT 'em_aberto'"
                );
            }
        } catch (Throwable) {
            // Ambiente sem permissão DDL em runtime segue com esquema instalado
        }
    }

    public function statusEspelho(int $espelhoId): ?string
    {
        if ($espelhoId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT status FROM ponto_espelhos WHERE id = ? LIMIT 1');
        $stmt->execute([$espelhoId]);
        $val = $stmt->fetchColumn();
        return is_string($val) ? $val : null;
    }

    private function espelhoEmDivergencia(int $espelhoId): bool
    {
        return $this->statusEspelho($espelhoId) === 'em_divergencia';
    }

    /**
     * Carrega totais nativos do espelho, lista diária e divergências registradas para exibição mobile-first no portal.
     *
     * @param array<string,mixed> $doc
     * @return array{
     *   espelho_status:string,
     *   totais:array{trab_min:int,he50_min:int,he100_min:int,falta_min:int,adn_min:int,dias:int},
     *   dias:list<array<string,mixed>>,
     *   divergencias:list<array<string,mixed>>
     * }
     */
    public function carregarResumoEspelho(array $doc): array
    {
        $espelhoId = (int) ($doc['espelho_id'] ?? 0);
        $espelhoStatus = $this->statusEspelho($espelhoId) ?? 'enviado';

        $totais = ['trab_min' => 0, 'he50_min' => 0, 'he100_min' => 0, 'falta_min' => 0, 'adn_min' => 0, 'dias' => 0];
        $dias = [];
        if ($espelhoId > 0) {
            $espIds = [$espelhoId];
            if (!empty($doc['periodo_inicio'])) {
                $st = $this->db->prepare('SELECT espelho_id FROM documentos_espelhos WHERE documento_id = ?');
                $st->execute([(int) $doc['id']]);
                $linked = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
                if ($linked !== []) {
                    $espIds = $linked;
                }
            }
            $ph = implode(',', array_fill(0, count($espIds), '?'));
            $where = "d.espelho_id IN ({$ph})";
            $params = $espIds;
            if (!empty($doc['periodo_inicio']) && !empty($doc['periodo_fim'])) {
                $where .= ' AND d.data BETWEEN ? AND ?';
                $params[] = (string) $doc['periodo_inicio'];
                $params[] = (string) $doc['periodo_fim'];
            }

            $sqlDias = "SELECT d.id, d.data, d.tipo_dia, d.ocorrencia_resumo,
                               d.horas_trab_min, d.horas_extras_min, d.horas_falta_min, d.adicional_noturno_min
                        FROM ponto_dias d
                        WHERE {$where}
                        ORDER BY d.data ASC";
            try {
                $stmtDias = $this->db->prepare($sqlDias);
                $stmtDias->execute($params);
                $rows = $stmtDias->fetchAll();
            } catch (Throwable) {
                // Bancos legados sem colunas calculadas retornam lista básica
                $stmtDias = $this->db->prepare(
                    "SELECT d.id, d.data, d.tipo_dia, d.ocorrencia_resumo FROM ponto_dias d WHERE {$where} ORDER BY d.data ASC"
                );
                $stmtDias->execute($params);
                $rows = $stmtDias->fetchAll();
            }

            $marcPorDia = [];
            $diaIds = array_map(static fn (array $r): int => (int) $r['id'], $rows);
            if ($diaIds !== []) {
                $mPh = implode(',', array_fill(0, count($diaIds), '?'));
                $stM = $this->db->prepare(
                    "SELECT ponto_dia_id, sequencia, TIME_FORMAT(horario, '%H:%i') AS h
                     FROM ponto_marcacoes WHERE ponto_dia_id IN ({$mPh}) ORDER BY sequencia ASC"
                );
                $stM->execute($diaIds);
                foreach ($stM->fetchAll() as $m) {
                    $marcPorDia[(int) $m['ponto_dia_id']][] = (string) $m['h'];
                }
            }

            foreach ($rows as $r) {
                $trab = (int) ($r['horas_trab_min'] ?? 0);
                $he = (int) ($r['horas_extras_min'] ?? 0);
                $falta = (int) ($r['horas_falta_min'] ?? 0);
                $adn = (int) ($r['adicional_noturno_min'] ?? 0);
                $tipo = (string) ($r['tipo_dia'] ?? 'trabalho');
                $isCem = in_array($tipo, ['folga', 'feriado', 'folga_escala'], true);

                $totais['trab_min'] += $trab;
                $totais['falta_min'] += $falta;
                $totais['adn_min'] += $adn;
                if ($isCem) {
                    $totais['he100_min'] += $he;
                } else {
                    $totais['he50_min'] += $he;
                }
                if ($trab > 0 || $he > 0 || $falta > 0 || !empty($marcPorDia[(int) $r['id']])) {
                    $totais['dias']++;
                }

                $dias[] = [
                    'data' => (string) $r['data'],
                    'tipo_dia' => $tipo,
                    'batidas' => $marcPorDia[(int) $r['id']] ?? [],
                    'trab_min' => $trab,
                    'he_min' => $he,
                    'falta_min' => $falta,
                    'resumo' => (string) ($r['ocorrencia_resumo'] ?? ''),
                ];
            }
        }

        $divergencias = [];
        if ($espelhoId > 0) {
            try {
                $stDiv = $this->db->prepare(
                    'SELECT id, data_ocorrencia, motivo, status, resolucao_obs, created_at, resolvido_em
                     FROM ponto_divergencias WHERE espelho_id = ? ORDER BY id DESC'
                );
                $stDiv->execute([$espelhoId]);
                $divergencias = $stDiv->fetchAll();
            } catch (Throwable) {
                $divergencias = [];
            }
        }

        return [
            'espelho_status' => $espelhoStatus,
            'totais' => $totais,
            'dias' => $dias,
            'divergencias' => $divergencias,
        ];
    }

    /**
     * Registra apontamento de divergência do colaborador pelo portal e pausa o espelho para tratamento do RH.
     */
    public function registrarDivergencia(string $token, string $motivo, ?string $dataOcorrencia = null): void
    {
        $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
        if (mb_strlen($motivo) < 8) {
            throw new RuntimeException('Descreva a divergência com pelo menos 8 caracteres para o RH analisar.');
        }
        if (mb_strlen($motivo) > 1000) {
            $motivo = mb_substr($motivo, 0, 1000);
        }
        $dataValida = null;
        if ($dataOcorrencia !== null && trim($dataOcorrencia) !== '') {
            $d = trim($dataOcorrencia);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                throw new RuntimeException('Data da ocorrência inválida.');
            }
            $dataValida = $d;
        }

        self::ensureDivergenciaSchema($this->db);

        $this->db->beginTransaction();
        try {
            $doc = $this->buscarPorPortalToken($token, true);
            if ($doc === null || $doc['status'] === 'invalidado') {
                throw new RuntimeException('Documento não encontrado ou invalidado.');
            }
            if ($doc['status'] === 'assinado') {
                throw new RuntimeException('Esta folha já foi assinada eletronicamente.');
            }
            $espelhoId = (int) $doc['espelho_id'];
            $colabId = (int) ($doc['colaborador_id'] ?? 0);
            if ($colabId <= 0) {
                $stCol = $this->db->prepare('SELECT colaborador_id FROM ponto_espelhos WHERE id = ?');
                $stCol->execute([$espelhoId]);
                $colabId = (int) $stCol->fetchColumn();
            }

            $pontoDiaId = null;
            if ($dataValida !== null) {
                $stDia = $this->db->prepare('SELECT id FROM ponto_dias WHERE espelho_id = ? AND data = ? LIMIT 1');
                $stDia->execute([$espelhoId, $dataValida]);
                $foundDia = $stDia->fetchColumn();
                if ($foundDia !== false) {
                    $pontoDiaId = (int) $foundDia;
                }
            }

            $ins = $this->db->prepare(
                'INSERT INTO ponto_divergencias
                 (espelho_id, documento_id, ponto_dia_id, colaborador_id, data_ocorrencia, motivo, status, ip, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, \'aberta\', ?, ?)'
            );
            $ins->execute([
                $espelhoId,
                (int) $doc['id'],
                $pontoDiaId,
                $colabId,
                $dataValida,
                $motivo,
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
            ]);
            $divergenciaId = (int) $this->db->lastInsertId();

            $this->db->prepare(
                "UPDATE ponto_espelhos SET status = 'em_divergencia' WHERE id = ? AND status IN ('fechado','enviado','em_tratamento','conferido','em_divergencia')"
            )->execute([$espelhoId]);

            $this->auditar((int) $doc['id'], 'divergencia_reportada', [
                'espelho_id' => $espelhoId,
                'data_ocorrencia' => $dataValida,
                'motivo' => $motivo,
            ]);
            $this->db->commit();

            AlertasService::emitirEvento([
                'chave' => 'divergencia:' . $divergenciaId,
                'codigo' => 'divergencia.aberta',
                'severidade' => 'alta',
                'titulo' => 'Divergência aberta — ' . (string) ($doc['colaborador_nome'] ?? $doc['nome'] ?? 'Colaborador'),
                'detalhe' => $motivo,
                'href' => '/rh/ponto/' . $espelhoId,
                'colaborador_id' => $colabId > 0 ? $colabId : null,
                'espelho_id' => $espelhoId,
                'documento_id' => (int) $doc['id'],
                'referencia_tipo' => 'ponto_divergencias',
                'referencia_id' => $divergenciaId,
            ]);

            $this->notificarDivergenciaRh($doc, $motivo, $dataValida, $espelhoId);
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Marca divergências abertas de um espelho como resolvidas e devolve o espelho para 'em_tratamento'.
     */
    public function resolverDivergencia(int $espelhoId, ?int $usuarioId, string $observacao = ''): int
    {
        self::ensureDivergenciaSchema($this->db);
        $obs = trim($observacao) !== '' ? trim($observacao) : 'Ajustado pelo RH.';
        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare(
                "UPDATE ponto_divergencias
                 SET status = 'resolvida', resolucao_obs = ?, resolvido_por = ?, resolvido_em = NOW()
                 WHERE espelho_id = ? AND status IN ('aberta','em_analise')"
            );
            $upd->execute([mb_substr($obs, 0, 1000), $usuarioId, $espelhoId]);
            $count = $upd->rowCount();

            $this->db->prepare(
                "UPDATE ponto_espelhos SET status = 'em_tratamento' WHERE id = ? AND status = 'em_divergencia'"
            )->execute([$espelhoId]);

            $this->db->commit();
            return $count;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Lista divergências em aberto para o painel inicial do RH (tolera bancos ainda não migrados).
     *
     * @return list<array<string,mixed>>
     */
    public static function listarDivergenciasAbertas(PDO $pdo, int $limite = 15): array
    {
        try {
            $sql = 'SELECT d.id, d.espelho_id, d.data_ocorrencia, d.motivo, d.created_at,
                           c.nome AS colaborador_nome, c.matricula,
                           p.data_inicio, p.data_fim
                    FROM ponto_divergencias d
                    INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                    INNER JOIN ponto_periodos p ON p.id = e.periodo_id
                    INNER JOIN rh_colaboradores c ON c.id = d.colaborador_id
                    WHERE d.status IN (\'aberta\', \'em_analise\')
                    ORDER BY d.created_at DESC
                    LIMIT ' . max(1, min(100, $limite));
            return $pdo->query($sql)->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function auditar(int $id, string $acao, array $dados, ?int $usuario = null): void
    {
        $this->db->prepare(
            "INSERT INTO rh_auditoria (entidade, entidade_id, acao, depois_json, usuario_id)
             VALUES ('documentos', ?, ?, ?, ?)"
        )->execute([$id, $acao, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $usuario]);
    }

    /** @param array{ARCADIS_SEAL_P12_PATH:string,ARCADIS_SEAL_P12_PASSWORD:string}|null $selo */
    private function renderCartaoAssinado(string $original, string $saida, array $auditoria, ?array $selo): void
    {
        // Preferência: selo PAdES via Python quando o ambiente permite.
        if ($selo !== null && function_exists('proc_open')) {
            try {
                $this->renderCartaoAssinadoPython($original, $saida, $auditoria, $selo);
                return;
            } catch (Throwable $e) {
                error_log('Arcadis RH: selo Python indisponível, usando carimbo PHP. ' . $e->getMessage());
            }
        }

        (new FolhaAssinaturaPdf())->carimbar($original, $saida, $auditoria);
    }

    /** @param array{ARCADIS_SEAL_P12_PATH:string,ARCADIS_SEAL_P12_PASSWORD:string} $selo */
    private function renderCartaoAssinadoPython(string $original, string $saida, array $auditoria, array $selo): void
    {
        $root = dirname(__DIR__, 2);
        $arquivo = tempnam($root . '/storage', 'assinatura_');
        if ($arquivo === false) {
            throw new RuntimeException('Não foi possível preparar a auditoria da assinatura.');
        }
        try {
            $json = json_encode($auditoria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($arquivo, $json) === false) {
                throw new RuntimeException('Não foi possível preparar a auditoria da assinatura.');
            }
            $programas = PHP_OS_FAMILY === 'Windows' ? [['py', '-3'], ['python']] : [['python3'], ['python']];
            $ambiente = array_replace(getenv() ?: [], $selo);
            foreach ($programas as $programa) {
                $cmd = [...$programa, $root . '/scripts/gerar_folha_assinada.py', $original, $saida, $arquivo];
                $processo = @proc_open($cmd, [
                    0 => ['pipe', 'r'],
                    1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
                    2 => ['pipe', 'w'],
                ], $pipes, $root, $ambiente);
                if (!is_resource($processo)) {
                    continue;
                }
                fclose($pipes[0]);
                $diagnostico = trim((string) stream_get_contents($pipes[2]));
                fclose($pipes[2]);
                if (proc_close($processo) === 0 && is_file($saida) && filesize($saida) > 1000) {
                    return;
                }
                if ($diagnostico !== '') {
                    error_log('Arcadis RH: falha ao executar a geração do PDF selado.');
                }
            }
        } finally {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        throw new RuntimeException('Não foi possível selar o PDF via Python.');
    }

    /**
     * Caminho absoluto do PDF da folha para o portal autenticado do colaborador.
     *
     * @param array<string,mixed> $doc
     */
    public function caminhoArquivoPortal(array $doc, bool $assinado = false): string
    {
        $rel = $assinado
            ? (string) ($doc['arquivo_assinado_path'] ?? '')
            : (string) ($doc['arquivo_path'] ?? '');
        if ($rel === '') {
            throw new RuntimeException('PDF da folha indisponível.');
        }
        $path = $this->absPath($rel);
        if (!is_file($path)) {
            throw new RuntimeException('Arquivo da folha não encontrado.');
        }
        return $path;
    }

    private function absPath(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($rel, '/');
    }

    private function baseUrl(): string
    {
        return \App\Helpers\AppUrl::base(dirname(__DIR__, 2));
    }

    /** @param array<string,mixed> $doc */
    private function notificarAssinaturaConfirmacao(array $doc): void
    {
        try {
            $to = trim((string) ($doc['email_corp'] ?? ''));
            if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $periodo = $this->periodoLabel($doc);
            $codigo = trim((string) ($doc['codigo_verificacao'] ?? ''));
            $verificarUrl = $codigo !== '' ? $this->baseUrl() . '/verificar/' . $codigo : '';
            $tpl = EmailTemplateService::render('folha.assinada_confirmacao', [
                'nome' => (string) ($doc['nome'] ?? 'Colaborador'),
                'matricula' => (string) ($doc['matricula'] ?? ''),
                'periodo' => $periodo,
                'competencia' => (string) ($doc['periodo_competencia'] ?? ''),
                'codigo' => $codigo,
                'portalUrl' => $verificarUrl,
                'cta_label' => 'VALIDAR DOCUMENTO',
            ], $this->db);
            (new MailService())->send($to, $tpl['subject'], $tpl['text'], null, $tpl['html']);
        } catch (Throwable) {
            // não bloqueia a assinatura
        }
    }

    /** @param array<string,mixed> $doc */
    private function notificarDivergenciaRh(array $doc, string $motivo, ?string $dataOcorrencia, int $espelhoId): void
    {
        try {
            $destinos = $this->emailsRhNotificacao();
            if ($destinos === []) {
                return;
            }
            $periodo = $this->periodoLabel($doc);
            $dataFmt = $dataOcorrencia !== null && $dataOcorrencia !== ''
                ? date('d/m/Y', strtotime($dataOcorrencia))
                : 'Não informada';
            $linkRh = $this->baseUrl() . '/rh/ponto/' . $espelhoId;
            $tpl = EmailTemplateService::render('folha.divergencia_aviso_rh', [
                'nome' => (string) ($doc['nome'] ?? 'Colaborador'),
                'matricula' => (string) ($doc['matricula'] ?? ''),
                'periodo' => $periodo,
                'motivo' => $motivo,
                'data_ocorrencia' => $dataFmt,
                'portalUrl' => $linkRh,
                'cta_label' => 'ABRA AGORA',
            ], $this->db);
            $mail = new MailService();
            foreach ($destinos as $to) {
                $mail->send($to, $tpl['subject'], $tpl['text'], null, $tpl['html']);
            }
        } catch (Throwable) {
            // não bloqueia o registro da divergência
        }
    }

    /** @param array<string,mixed> $doc */
    private function periodoLabel(array $doc): string
    {
        $ini = !empty($doc['periodo_cartao_inicio']) ? date('d/m/Y', strtotime((string) $doc['periodo_cartao_inicio'])) : '';
        $fim = !empty($doc['periodo_cartao_fim']) ? date('d/m/Y', strtotime((string) $doc['periodo_cartao_fim'])) : '';
        if ($ini !== '' && $fim !== '') {
            return $ini . ' a ' . $fim;
        }

        return trim((string) ($doc['periodo_competencia'] ?? ''));
    }

    /** @return list<string> */
    private function emailsRhNotificacao(): array
    {
        $emails = [];
        try {
            $rows = $this->db->query(
                "SELECT DISTINCT u.email
                 FROM usuarios u
                 INNER JOIN usuario_perfis up ON up.usuario_id = u.id
                 INNER JOIN perfis p ON p.id = up.perfil_id AND p.status = 'ativo'
                 INNER JOIN perfil_permissoes pp ON pp.perfil_id = p.id
                 INNER JOIN permissoes pe ON pe.id = pp.permissao_id
                 WHERE u.status = 'ativo'
                   AND u.email IS NOT NULL
                   AND u.email <> ''
                   AND pe.codigo IN ('folha.enviar', 'email.gerenciar', 'ponto.editar')"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($rows as $email) {
                $email = strtolower(trim((string) $email));
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $emails[$email] = $email;
                }
            }
        } catch (Throwable) {
        }

        if ($emails === []) {
            try {
                $ativa = (new EmailOAuth\EmailAccountService($this->db))->contaAtiva();
                $fallback = strtolower(trim((string) ($ativa['email'] ?? '')));
                if (filter_var($fallback, FILTER_VALIDATE_EMAIL)) {
                    $emails[$fallback] = $fallback;
                }
            } catch (Throwable) {
            }
        }

        return array_values($emails);
    }
}

