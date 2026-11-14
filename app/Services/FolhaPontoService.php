<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class FolhaPontoService
{
    public const LAYOUT_PDF_ATUAL = FolhaPdfRenderer::LAYOUT_VERSION;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * Gera PDF no padrão cartão-ponto e registra documento.
     *
     * @return array{documento_id:int,path:string,codigo:string}
     */
    public function gerarPdf(int $espelhoId, ?int $usuarioId = null, mixed $inicio = null, mixed $fim = null): array
    {
        $codigo = bin2hex(random_bytes(16));
        $dir = dirname(__DIR__, 2) . '/storage/uploads/folhas';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $filename = sprintf('folha_%d_%s.pdf', $espelhoId, substr($codigo, 0, 8));
        $path = $dir . '/' . $filename;

        $rel = 'storage/uploads/folhas/' . $filename;
        $transacaoPropria = !$this->db->inTransaction();
        try {
            if ($transacaoPropria) {
                $this->db->beginTransaction();
            }
            $dados = $this->carregar($espelhoId, $inicio, $fim, true);
            if ($dados === null) {
                throw new RuntimeException('Espelho não encontrado.');
            }
            if (PeriodoPonto::dias($dados['periodo']) > PeriodoPonto::MAX_DIAS_PDF) {
                throw new RuntimeException('Selecione até ' . PeriodoPonto::MAX_DIAS_PDF . ' dias para emitir o cartão em uma página.');
            }
            if ($dados['recorte'] && count($dados['dias']) !== PeriodoPonto::dias($dados['periodo'])) {
                throw new RuntimeException('Há dias sem espelho no período. Gere e confira os espelhos antes de emitir o cartão.');
            }
            // Recarrega status (podem ter sido fechados logo antes da emissão).
            $ids = array_map(static fn(array $e): int => (int) $e['id'], $dados['espelhos_periodo']);
            if ($ids !== []) {
                $in = implode(',', $ids);
                $statusRows = $this->db->query(
                    "SELECT id, status FROM ponto_espelhos WHERE id IN ({$in})"
                )->fetchAll();
                $dados['espelhos_periodo'] = $statusRows;
            }
            $permitidos = ['conferido', 'fechado', 'enviado', 'assinado'];
            foreach ($dados['espelhos_periodo'] as $espelho) {
                if (!in_array((string) $espelho['status'], $permitidos, true)) {
                    throw new RuntimeException(
                        'Ainda há espelho aberto no período. Use "Fechar período" na gestão de ponto ou clique novamente em Gerar PDF para fechar automaticamente.'
                    );
                }
            }
            $payload = $this->montarPayload($dados, $codigo);
            $this->renderPdf($payload, $path);

            $ins = $this->db->prepare(
                "INSERT INTO documentos (tipo, espelho_id, codigo_verificacao, arquivo_path, status, layout_pdf, periodo_inicio, periodo_fim)
                 VALUES ('folha_ponto', :e, :c, :p, 'gerado', :layout, :inicio, :fim)"
            );
            $ins->execute(['e' => $espelhoId, 'c' => $codigo, 'p' => $rel, 'layout' => self::LAYOUT_PDF_ATUAL,
                'inicio' => $dados['recorte'] ? $dados['periodo']['data_inicio'] : null,
                'fim' => $dados['recorte'] ? $dados['periodo']['data_fim'] : null]);
            $docId = (int) $this->db->lastInsertId();
            $vinculo = $this->db->prepare('INSERT INTO documentos_espelhos (documento_id, espelho_id) VALUES (?, ?)');
            foreach ($dados['espelhos_periodo'] as $espelho) {
                $vinculo->execute([$docId, $espelho['id']]);
            }

            $ver = $this->db->prepare(
                'INSERT INTO documentos_versoes (documento_id, versao, arquivo_path, motivo, usuario_id)
                 VALUES (:d, 1, :p, :m, :u)'
            );
            $ver->execute([
                'd' => $docId,
                'p' => $rel,
                'm' => 'Geração cartão-ponto',
                'u' => $usuarioId,
            ]);
            if ($transacaoPropria) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($transacaoPropria && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (is_file($path)) {
                unlink($path);
            }
            throw $e;
        }

        return ['documento_id' => $docId, 'path' => $rel, 'codigo' => $codigo];
    }

    /** Reemite uma via pendente obsoleta e conserva seu link, se houver. */
    public function atualizarModeloPendente(int $documentoId, ?int $usuarioId = null, ?int $espelhoId = null, ?array $novoPeriodo = null): int
    {
        $root = dirname(__DIR__, 2);
        $nova = null;
        $this->db->beginTransaction();
        try {
            $doc = $this->buscarViaAtualParaAtualizacao($documentoId, $espelhoId);
            $documentoId = (int) $doc['id'];
            $inicio = $novoPeriodo['data_inicio'] ?? $doc['periodo_inicio'];
            $fim = $novoPeriodo['data_fim'] ?? $doc['periodo_fim'];
            $mesmoPeriodo = $inicio === $doc['periodo_inicio'] && $fim === $doc['periodo_fim'];
            if ($novoPeriodo !== null && !in_array($doc['status'], ['gerado', 'enviado'], true)) {
                throw new RuntimeException('Somente uma via pendente pode receber outro período.');
            }
            if (!in_array($doc['status'], ['gerado', 'enviado'], true)
                || ($mesmoPeriodo && (int) $doc['layout_pdf'] >= self::LAYOUT_PDF_ATUAL)) {
                $this->db->commit();
                return $documentoId;
            }

            $espelhoId = (int) $doc['espelho_id'];
            $espelho = $this->db->prepare('SELECT status FROM ponto_espelhos WHERE id = ? FOR UPDATE');
            $espelho->execute([$espelhoId]);
            if (!in_array($espelho->fetchColumn(), ['conferido', 'fechado', 'enviado', 'assinado'], true)
                || !empty($doc['arquivo_assinado_path']) || !empty($doc['arquivo_assinado_sha256'])
                || !empty($doc['assinatura_em']) || !empty($doc['assinatura_aceite'])) {
                throw new RuntimeException('A via só pode ser atualizada se a folha estiver conferida ou fechada e ainda sem assinatura.');
            }
            $assinada = $this->db->prepare("SELECT id FROM documentos WHERE espelho_id = ? AND status = 'assinado'
                AND periodo_inicio <=> ? AND periodo_fim <=> ? LIMIT 1 FOR UPDATE");
            $assinada->execute([$espelhoId, $inicio, $fim]);
            if ($assinada->fetchColumn() !== false) {
                throw new RuntimeException('Esta folha já tem uma via assinada.');
            }

            $selo = new AssinaturaEletronicaService($this->db);
            $hashAnterior = $selo->calcularHashes($root . '/' . ltrim($doc['arquivo_path'], '/'))['sha256'];
            if (!empty($doc['hash_sha256']) && !hash_equals($doc['hash_sha256'], $hashAnterior)) {
                throw new RuntimeException('O PDF anterior foi alterado após a emissão.');
            }

            $nova = $this->gerarPdf($espelhoId, $usuarioId, $inicio, $fim);
            $hashes = $selo->calcularHashes($root . '/' . $nova['path']);
            $token = $doc['portal_token'] ?: null;
            $arquivar = $this->db->prepare(
                "UPDATE documentos SET status = 'invalidado', portal_token = NULL, substituido_por_id = ?
                 WHERE id = ? AND status IN ('gerado', 'enviado')"
            );
            $arquivar->execute([$nova['documento_id'], $documentoId]);
            $publicar = $this->db->prepare(
                "UPDATE documentos SET status = ?, portal_token = ?, hash_md5 = ?, hash_sha1 = ?, hash_sha256 = ?
                 WHERE id = ? AND layout_pdf = ? AND status = 'gerado'"
            );
            $publicar->execute([
                $doc['status'], $token, $hashes['md5'], $hashes['sha1'], $hashes['sha256'],
                $nova['documento_id'], self::LAYOUT_PDF_ATUAL,
            ]);
            if ($arquivar->rowCount() !== 1 || $publicar->rowCount() !== 1) {
                throw new RuntimeException('Não foi possível substituir a via da folha.');
            }

            $auditoria = $this->db->prepare(
                "INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
                VALUES ('documentos', ?, ?, ?, ?, ?)"
            );
            $auditoria->execute([
                $nova['documento_id'],
                $mesmoPeriodo ? 'atualizar_modelo_pdf' : 'corrigir_periodo_pdf',
                json_encode([
                    'documento_id' => $documentoId,
                    'layout_pdf' => (int) $doc['layout_pdf'],
                    'hash_sha256' => $hashAnterior,
                    'periodo_inicio' => $doc['periodo_inicio'], 'periodo_fim' => $doc['periodo_fim'],
                ], JSON_THROW_ON_ERROR),
                json_encode([
                    'documento_id' => $nova['documento_id'],
                    'layout_pdf' => self::LAYOUT_PDF_ATUAL,
                    'hash_sha256' => $hashes['sha256'],
                    'periodo_inicio' => $inicio, 'periodo_fim' => $fim,
                    'link_preservado' => $token !== null,
                ], JSON_THROW_ON_ERROR),
                $usuarioId,
            ]);
            $this->db->commit();
            return $nova['documento_id'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($nova !== null && is_file($root . '/' . $nova['path'])) {
                unlink($root . '/' . $nova['path']);
            }
            throw $e;
        }
    }

    private function buscarViaAtualParaAtualizacao(int $documentoId, ?int $espelhoId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM documentos WHERE id = ? FOR UPDATE');
        $visitados = [];
        while (!isset($visitados[$documentoId])) {
            $visitados[$documentoId] = true;
            $stmt->execute([$documentoId]);
            $doc = $stmt->fetch();
            if (!$doc || ($espelhoId !== null && (int) $doc['espelho_id'] !== $espelhoId)) {
                throw new RuntimeException('Documento não encontrado nesta folha.');
            }
            if ($doc['substituido_por_id'] === null) {
                return $doc;
            }
            $espelhoId = (int) $doc['espelho_id'];
            $documentoId = (int) $doc['substituido_por_id'];
        }
        throw new RuntimeException('Não foi possível localizar a via atual da folha.');
    }

    /**
     * @return array{ok:bool,erro:?string}
     */
    public function enviarDocumento(int $documentoId, ?string $destinatario = null, ?int $espelhoId = null): array
    {
        $documentoId = $this->atualizarModeloPendente($documentoId, null, $espelhoId);
        $stmt = $this->db->prepare(
            'SELECT d.*, c.email_corp, c.nome, c.matricula, e.id AS espelho_id,
                    DATE_FORMAT(p.data_inicio, \'%Y-%m\') AS competencia, p.data_inicio, p.data_fim
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             INNER JOIN ponto_periodos p ON p.id = e.periodo_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $documentoId]);
        $doc = $stmt->fetch();
        if ($doc === false) {
            throw new RuntimeException('Documento não encontrado.');
        }
        if (($doc['status'] ?? '') === 'invalidado') {
            throw new RuntimeException('Documento invalidado — gere novo PDF.');
        }

        $to = $destinatario ?: (string) ($doc['email_corp'] ?? '');
        if ($to === '') {
            throw new RuntimeException('Colaborador sem e-mail corporativo.');
        }

        $tentativa = (int) $this->db->query(
            'SELECT COALESCE(MAX(tentativa),0)+1 FROM emails_envios WHERE documento_id=' . (int) $documentoId
        )->fetchColumn();

        $abs = dirname(__DIR__, 2) . '/' . ltrim((string) $doc['arquivo_path'], '/');
        $portalUrl = null;
        if (!empty($doc['portal_token'])) {
            $app = require dirname(__DIR__, 2) . '/config/app.php';
            $base = rtrim(trim((string) ($app['url'] ?? '')), '/');
            if ($base === '') {
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                $base = ($https ? 'https' : 'http') . '://' . \App\Helpers\AppUrl::sanitizeHost((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080'));
            }
            if (str_ends_with(strtolower($base), '/public')) {
                $base = rtrim(substr($base, 0, -7), '/');
            }
            $portalUrl = $base . '/assinar/' . $doc['portal_token'];
        }

        $tplKey = $tentativa > 1 ? 'folha.reenvio_colaborador' : 'folha.envio_colaborador';
        $emailContent = self::montarEmailFolha($doc, $portalUrl, $this->db, $tplKey, [
            'tentativa' => (string) $tentativa,
        ]);
        $mail = new MailService();
        $result = $mail->send(
            $to,
            $emailContent['subject'],
            $emailContent['text'],
            is_file($abs) ? $abs : null,
            $emailContent['html']
        );

        $this->registrarEnvio($documentoId, $to, $result['ok'], $result['erro'], $tentativa);
        return $result;
    }

    /**
     * Envia lembrete de assinatura (folha já enviada, ainda não assinada).
     *
     * @return array{ok:bool,erro:?string}
     */
    public function enviarLembreteAssinatura(int $documentoId, ?int $espelhoId = null): array
    {
        $documentoId = $this->atualizarModeloPendente($documentoId, null, $espelhoId);
        $stmt = $this->db->prepare(
            'SELECT d.*, c.email_corp, c.nome, c.matricula, e.id AS espelho_id,
                    DATE_FORMAT(p.data_inicio, \'%Y-%m\') AS competencia, p.data_inicio, p.data_fim
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             INNER JOIN ponto_periodos p ON p.id = e.periodo_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             WHERE d.id = :id'
        );
        $stmt->execute(['id' => $documentoId]);
        $doc = $stmt->fetch();
        if ($doc === false) {
            throw new RuntimeException('Documento não encontrado.');
        }
        if (($doc['status'] ?? '') !== 'enviado') {
            throw new RuntimeException('Lembrete só é enviado quando a folha está com status “enviado”.');
        }
        if (empty($doc['portal_token'])) {
            throw new RuntimeException('Gere o link de assinatura antes do lembrete.');
        }

        $to = (string) ($doc['email_corp'] ?? '');
        if ($to === '') {
            throw new RuntimeException('Colaborador sem e-mail corporativo.');
        }

        $app = require dirname(__DIR__, 2) . '/config/app.php';
        $base = rtrim(trim((string) ($app['url'] ?? '')), '/');
        if ($base === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $base = ($https ? 'https' : 'http') . '://' . \App\Helpers\AppUrl::sanitizeHost((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080'));
        }
        if (str_ends_with(strtolower($base), '/public')) {
            $base = rtrim(substr($base, 0, -7), '/');
        }
        $portalUrl = $base . '/assinar/' . $doc['portal_token'];

        $emailContent = self::montarEmailFolha($doc, $portalUrl, $this->db, 'folha.lembrete_assinatura');
        $mail = new MailService();
        $result = $mail->send(
            $to,
            $emailContent['subject'],
            $emailContent['text'],
            null,
            $emailContent['html']
        );

        $tentativa = (int) $this->db->query(
            'SELECT COALESCE(MAX(tentativa),0)+1 FROM emails_envios WHERE documento_id=' . (int) $documentoId
        )->fetchColumn();
        $this->registrarEnvio($documentoId, $to, $result['ok'], $result['erro'], $tentativa);

        return $result;
    }

    /**
     * Monta assunto + texto + HTML via template de folha.
     *
     * @param array<string,mixed> $doc
     * @param array<string,mixed> $extra
     * @return array{subject:string,text:string,html:string}
     */
    public static function montarEmailFolha(
        array $doc,
        ?string $portalUrl,
        ?\PDO $pdo = null,
        string $templateKey = 'folha.envio_colaborador',
        array $extra = []
    ): array {
        $ini = !empty($doc['data_inicio']) ? date('d/m/Y', strtotime((string) $doc['data_inicio'])) : '';
        $fim = !empty($doc['data_fim']) ? date('d/m/Y', strtotime((string) $doc['data_fim'])) : '';
        $competencia = trim((string) ($doc['competencia'] ?? ''));
        $periodo = ($ini !== '' && $fim !== '') ? "{$ini} a {$fim}" : $competencia;

        return EmailTemplateService::render($templateKey, array_merge([
            'nome' => (string) ($doc['nome'] ?? 'Colaborador'),
            'matricula' => (string) ($doc['matricula'] ?? ''),
            'periodo' => $periodo,
            'competencia' => $competencia,
            'codigo' => (string) ($doc['codigo_verificacao'] ?? ''),
            'portalUrl' => $portalUrl,
        ], $extra), $pdo);
    }

    /**
     * Gera link do portal (só leitura) para registro do aceite do colaborador.
     *
     * @return array{portal_url:string,documento_id:int}
     */
    public function gerarLinkAssinaturaDigital(int $documentoId, ?int $usuarioId = null): array
    {
        return (new AssinaturaEletronicaService($this->db))->gerarLinkPortal($documentoId, $usuarioId);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function buscarPorPortalToken(string $token): ?array
    {
        return (new AssinaturaEletronicaService($this->db))->buscarPorPortalToken($token);
    }

    public function registrarEnvio(
        int $documentoId,
        string $destinatario,
        bool $sucesso = true,
        ?string $erro = null,
        int $tentativa = 1
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO emails_envios (documento_id, destinatario, status, tentativa, erro_mensagem, enviado_em)
             VALUES (:d, :dest, :status, :tent, :erro, CASE WHEN :status2 = \'enviado\' THEN NOW() ELSE NULL END)'
        );
        $status = $sucesso ? 'enviado' : 'falha';
        $stmt->execute([
            'd' => $documentoId,
            'dest' => $destinatario,
            'status' => $status,
            'status2' => $status,
            'tent' => $tentativa,
            'erro' => $erro,
        ]);

        if ($sucesso) {
            $this->db->prepare("UPDATE documentos SET status = 'enviado' WHERE id = :id AND status = 'gerado'")
                ->execute(['id' => $documentoId]);
            $esp = $this->db->prepare('SELECT espelho_id FROM documentos WHERE id = :id AND periodo_inicio IS NULL');
            $esp->execute(['id' => $documentoId]);
            $espelhoId = (int) $esp->fetchColumn();
            if ($espelhoId > 0) {
                $this->db->prepare("UPDATE ponto_espelhos SET status = 'enviado' WHERE id = :id AND status IN ('fechado','conferido')")
                    ->execute(['id' => $espelhoId]);
            }
        }
    }

    /**
     * @return array{espelho:array<string,mixed>,dias:list<array<string,mixed>>,marcacoes:array<int,array<int,array<string,mixed>>>}|null
     */
    public function carregar(int $espelhoId, mixed $inicio = null, mixed $fim = null, bool $bloquear = false): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.*, c.nome, c.matricula, c.cpf, c.cargo, c.funcao, c.email_corp,
                    pp.data_inicio AS periodo_inicio, pp.data_fim AS periodo_fim,
                    YEAR(pp.data_inicio) AS ano, MONTH(pp.data_inicio) AS mes,
                    l.codigo AS lotacao_codigo,
                    ct.codigo AS contrato_codigo, cc.codigo AS cc_codigo, os.codigo AS os_codigo,
                    loc.municipio, loc.uf,
                    esc.tipo AS escala_tipo, esc.codigo AS escala_codigo,
                    j.entrada AS jornada_entrada, j.intervalo_inicio AS jornada_intervalo_inicio,
                    j.intervalo_fim AS jornada_intervalo_fim, j.saida AS jornada_saida
             FROM ponto_espelhos e
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             LEFT JOIN rh_lotacoes l ON l.id = e.lotacao_id
             LEFT JOIN rh_escalas esc ON esc.id = l.escala_id
             LEFT JOIN rh_contratos ct ON ct.id = l.contrato_id
             LEFT JOIN rh_centros_custo cc ON cc.id = l.centro_custo_id
             LEFT JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
             LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
             LEFT JOIN rh_jornadas j ON j.id = l.jornada_id
             WHERE e.id = :id' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $stmt->execute(['id' => $espelhoId]);
        $espelho = $stmt->fetch();
        if ($espelho === false) {
            return null;
        }

        $cartao = [
            'data_inicio' => (string) $espelho['periodo_inicio'],
            'data_fim' => (string) $espelho['periodo_fim'],
        ];
        $periodo = PeriodoPonto::selecionar(
            $inicio ?? $cartao['data_inicio'],
            $fim ?? $cartao['data_fim'],
            (int) $espelho['ano'],
            (int) $espelho['mes']
        );
        if ($periodo['data_fim'] < $cartao['data_inicio'] || $periodo['data_inicio'] > $cartao['data_fim']) {
            throw new RuntimeException('Abra um espelho que faça parte do período selecionado.');
        }
        if ($periodo['data_inicio'] < $cartao['data_inicio']) {
            $periodo['data_inicio'] = $cartao['data_inicio'];
        }
        if ($periodo['data_fim'] > $cartao['data_fim']) {
            $periodo['data_fim'] = $cartao['data_fim'];
        }

        $espelhos = [['id' => $espelhoId, 'status' => $espelho['status']]];
        $diasStmt = $this->db->prepare(
            'SELECT * FROM ponto_dias WHERE espelho_id = ? AND data BETWEEN ? AND ? ORDER BY data'
        );
        $diasStmt->execute([$espelhoId, $periodo['data_inicio'], $periodo['data_fim']]);
        $dias = $diasStmt->fetchAll();
        $marcacoes = [];
        if ($dias !== []) {
            $ids = implode(',', array_map(static fn($d) => (int) $d['id'], $dias));
            foreach ($this->db->query("SELECT * FROM ponto_marcacoes WHERE ponto_dia_id IN ({$ids})") as $m) {
                $marcacoes[(int) $m['ponto_dia_id']][(int) $m['sequencia']] = $m;
            }
        }

        $recorte = $periodo['data_inicio'] !== $cartao['data_inicio']
            || $periodo['data_fim'] !== $cartao['data_fim'];

        return [
            'espelho' => $espelho,
            'dias' => $dias,
            'marcacoes' => $marcacoes,
            'periodo' => $periodo,
            'recorte' => $recorte,
            'espelhos_periodo' => $espelhos,
        ];
    }

    /**
     * @param array{espelho:array<string,mixed>,dias:list<array<string,mixed>>,marcacoes:array<int,array<int,array<string,mixed>>>} $dados
     * @return array<string,mixed>
     */
    private function montarPayload(array $dados, string $codigo): array
    {
        $e = $dados['espelho'];
        $diasSemana = [1 => 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SAB', 'DOM'];
        $atestados = $this->carregarAtestados(array_map(static fn(array $dia): int => (int) $dia['id'], $dados['dias']));
        $jornadas = [];
        $diasOut = [];
        $totais = array_fill_keys(['norm', 'trab', 'adn', 'prev', 'dias_falta', 'falta', 'atraso', 'he', 'desconto', 'atestado'], 0);

        foreach ($dados['dias'] as $dia) {
            $mid = (int) $dia['id'];
            $origem = 'previsto';
            $m = $dados['marcacoes'][$mid] ?? [];
            foreach ([11, 12, 13, 14, 5, 6, 7, 8] as $seq) {
                if (isset($m[$seq]['origem'])) {
                    $origem = (string) $m[$seq]['origem'];
                    break;
                }
            }

            $ts = strtotime((string) $dia['data']);
            $diaSemana = (int) date('N', $ts);
            $this->registrarJornadaDoDia($jornadas, $dia, $diaSemana);
            $origens = [];
            foreach ([1 => 'ent1', 'sai1', 'ent2', 'sai2'] as $slot => $campo) {
                $origens[$campo] = null;
                foreach ([$slot + 10, $slot + 4, $slot] as $seq) {
                    if (!empty($dia[$campo]) && isset($m[$seq]['horario'], $m[$seq]['origem'])
                        && substr((string) $dia[$campo], 0, 5) === substr((string) $m[$seq]['horario'], 0, 5)) {
                        $origens[$campo] = $m[$seq]['origem'];
                        break;
                    }
                }
            }

            $trab = (int) ($dia['total_trab_min'] ?? 0);
            $extras = (int) ($dia['horas_extras_min'] ?? 0);
            $normal = max(0, $trab - $extras);
            $noturno = (int) ($dia['adn_min'] ?? 0);
            $atestadoMin = $atestados[$mid]['minutos'] ?? null;
            $diasOut[] = [
                'data_fmt' => date('d/m', $ts) . ' ' . $diasSemana[$diaSemana],
                'tipo_dia' => $dia['tipo_dia'],
                'ent1' => $dia['ent1'] ?? null,
                'sai1' => $dia['sai1'] ?? null,
                'ent2' => $dia['ent2'] ?? null,
                'sai2' => $dia['sai2'] ?? null,
                'total_normal_min' => $normal,
                'total_trab_min' => $trab,
                'adn_min' => $noturno,
                'horas_previstas_min' => (int) ($dia['horas_previstas_min'] ?? 0),
                'dia_falta' => (int) ($dia['dia_falta'] ?? 0),
                'horas_falta_min' => (int) ($dia['horas_falta_min'] ?? 0),
                'atraso_bruto_min' => (int) ($dia['atraso_bruto_min'] ?? 0),
                'horas_extras_min' => $extras,
                'desconto_min' => (int) ($dia['desconto_min'] ?? 0),
                'atestado' => isset($atestados[$mid]),
                'atestado_min' => $atestadoMin,
                'origem' => $origem,
                'origens' => $origens,
            ];

            $totais['norm'] += $normal;
            $totais['trab'] += $trab;
            $totais['adn'] += $noturno;
            $totais['prev'] += (int) ($dia['horas_previstas_min'] ?? 0);
            $totais['dias_falta'] += (int) ($dia['dia_falta'] ?? 0);
            $totais['falta'] += (int) ($dia['horas_falta_min'] ?? 0);
            $totais['atraso'] += (int) ($dia['atraso_bruto_min'] ?? 0);
            $totais['he'] += $extras;
            $totais['desconto'] += (int) ($dia['desconto_min'] ?? 0);
            $totais['atestado'] += $atestadoMin ?? 0;
        }

        $escalaTipo = (string) ($e['escala_tipo'] ?? '');
        $escalaAlternada = in_array($escalaTipo, ['12x36', 'ciclo'], true);

        $jornadaSemanal = [];
        foreach ($diasSemana as $numero => $rotulo) {
            $variantes = array_values($jornadas[$numero] ?? []);
            if ($escalaAlternada && count($variantes) > 1) {
                $trabalhoVariantes = array_values(array_filter(
                    $variantes,
                    static fn (array $v): bool => ($v['tipo'] ?? '') === 'trabalho'
                ));
                if (count($trabalhoVariantes) === 1) {
                    $variantes = $trabalhoVariantes;
                }
            }
            $jornada = match (count($variantes)) {
                0 => ['tipo' => 'indisponivel'],
                1 => $variantes[0],
                default => ['tipo' => 'variavel'],
            };
            $jornadaSemanal[] = ['dia' => $rotulo] + $jornada;
        }

        return [
            'codigo' => $codigo,
            'emitido' => date('d/m/Y H:i'),
            'periodo' => str_replace(' a ', ' A ', PeriodoPonto::rotulo($dados['periodo'])),
            'espelho' => [
                'empresa' => 'ARCADIS',
                'nome' => $e['nome'],
                'matricula' => $e['matricula'],
                'cpf' => $e['cpf'] ?? '',
                'cargo' => $e['cargo'] ?: ($e['funcao'] ?? ''),
                'contrato_codigo' => $e['contrato_codigo'] ?? '',
                'cc_codigo' => $e['cc_codigo'] ?? '',
                'os_codigo' => $e['os_codigo'] ?? '',
                'municipio' => $e['municipio'] ?? '',
                'uf' => $e['uf'] ?? '',
                'escala_tipo' => $escalaTipo,
                'escala_codigo' => $e['escala_codigo'] ?? '',
                'jornada' => [
                    'entrada' => $e['jornada_entrada'] ?? null,
                    'intervalo_inicio' => $e['jornada_intervalo_inicio'] ?? null,
                    'intervalo_fim' => $e['jornada_intervalo_fim'] ?? null,
                    'saida' => $e['jornada_saida'] ?? null,
                ],
                'jornada_semanal' => $jornadaSemanal,
            ],
            'dias' => $diasOut,
            'totais' => $totais,
        ];
    }

    private function registrarJornadaDoDia(array &$jornadas, array $dia, int $diaSemana): void
    {
        if ($dia['tipo_dia'] === 'feriado') {
            return;
        }
        if (in_array($dia['tipo_dia'], ['folga', 'folga_escala'], true)) {
            $jornadas[$diaSemana]['folga'] = ['tipo' => 'folga'];
            return;
        }
        if (empty($dia['previsto_entrada']) || empty($dia['previsto_saida'])) {
            return;
        }

        $horario = [
            'entrada' => $dia['previsto_entrada'],
            'intervalo_inicio' => $dia['previsto_intervalo_inicio'] ?? null,
            'intervalo_fim' => $dia['previsto_intervalo_fim'] ?? null,
            'saida' => $dia['previsto_saida'],
        ];
        $chave = implode('|', $horario);
        $jornadas[$diaSemana][$chave] = ['tipo' => 'trabalho'] + $horario;
    }

    /** @return array<int, array{minutos: ?int}> */
    private function carregarAtestados(array $dias): array
    {
        if ($dias === []) {
            return [];
        }
        $params = implode(',', array_fill(0, count($dias), '?'));
        $stmt = $this->db->prepare(
            "SELECT d.id, SUM(o.minutos) AS minutos
             FROM ponto_ocorrencias o
             INNER JOIN ponto_dias d ON d.id = o.ponto_dia_id
             WHERE d.id IN ($params) AND o.tipo = 'atestado'
             GROUP BY d.id"
        );
        $stmt->execute($dias);
        $atestados = [];
        foreach ($stmt as $row) {
            $minutos = (int) $row['minutos'];
            $atestados[(int) $row['id']] = ['minutos' => $minutos > 0 ? $minutos : null];
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT DISTINCT d.id
                 FROM ponto_dias d
                 INNER JOIN ponto_justificativas j ON j.espelho_id = d.espelho_id
                     AND d.data BETWEEN j.data_inicio AND j.data_fim
                 INNER JOIN ponto_justificativa_tipos t ON t.id = j.tipo_id
                 WHERE d.id IN ($params) AND d.tipo_dia = 'trabalho' AND UPPER(t.codigo) IN ('ATST', 'ATESTADO')"
            );
            $stmt->execute($dias);
            foreach ($stmt as $row) {
                $atestados[(int) $row['id']] ??= ['minutos' => null];
            }
        } catch (PDOException $e) {
            // Instalações antigas ainda podem registrar atestados só como ocorrências.
            if ($e->getCode() !== '42S02') {
                throw $e;
            }
        }

        return $atestados;
    }

    /** @param array<string,mixed> $payload */
    private function renderPdf(array $payload, string $outPath): void
    {
        // Hostinger desabilita exec/shell_exec: gerar sempre em PHP puro.
        (new FolhaPdfRenderer())->render($payload, $outPath);
    }
}
