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
    public const LAYOUT_PDF_ATUAL = 3;

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
            $permitidos = $dados['recorte'] ? ['fechado', 'enviado', 'assinado'] : ['conferido', 'fechado', 'enviado', 'assinado'];
            foreach ($dados['espelhos_periodo'] as $espelho) {
                if (!in_array($espelho['status'], $permitidos, true)) {
                    throw new RuntimeException('Feche os espelhos do período antes de emitir o cartão.');
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
            'SELECT d.*, c.email_corp, c.nome, e.id AS espelho_id
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
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
        $portalLinha = '';
        if (!empty($doc['portal_token'])) {
            $app = require dirname(__DIR__, 2) . '/config/app.php';
            $base = trim((string) ($app['url'] ?? ''));
            if ($base === '') {
                $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8080');
            }
            $portalLinha = "\nAssinar eletronicamente (somente leitura, sem editar ponto):\n"
                . rtrim($base, '/') . '/assinar/' . $doc['portal_token'] . "\n";
        }
        $mail = new MailService();
        $result = $mail->send(
            $to,
            'Folha de Ponto — ' . $doc['nome'],
            "Olá,\n\nSegue sua folha de ponto.\nCódigo de verificação: {$doc['codigo_verificacao']}\n"
            . $portalLinha
            . "\nArcadis RH",
            is_file($abs) ? $abs : null
        );

        $this->registrarEnvio($documentoId, $to, $result['ok'], $result['erro'], $tentativa);
        return $result;
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
                    pc.ano, pc.mes, l.codigo AS lotacao_codigo,
                    ct.codigo AS contrato_codigo, cc.codigo AS cc_codigo, os.codigo AS os_codigo,
                    loc.municipio, loc.uf,
                    j.entrada AS jornada_entrada, j.intervalo_inicio AS jornada_intervalo_inicio,
                    j.intervalo_fim AS jornada_intervalo_fim, j.saida AS jornada_saida
             FROM ponto_espelhos e
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
             LEFT JOIN rh_lotacoes l ON l.id = e.lotacao_id
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

        $mensal = PeriodoPonto::selecionar(null, null, (int) $espelho['ano'], (int) $espelho['mes']);
        $periodo = PeriodoPonto::selecionar($inicio, $fim, (int) $espelho['ano'], (int) $espelho['mes']);
        if ($periodo['data_fim'] < $mensal['data_inicio'] || $periodo['data_inicio'] > $mensal['data_fim']) {
            throw new RuntimeException('Abra um espelho que faça parte do período selecionado.');
        }
        $competencias = $this->db->prepare('SELECT e.id, e.status FROM ponto_espelhos e
            JOIN ponto_competencias c ON c.id = e.competencia_id
            WHERE e.colaborador_id = ? AND c.ano * 100 + c.mes BETWEEN ? AND ? ORDER BY e.id'
            . ($bloquear ? ' FOR UPDATE' : ''));
        $competencias->execute([(int) $espelho['colaborador_id'], (int) str_replace('-', '', substr($periodo['data_inicio'], 0, 7)),
            (int) str_replace('-', '', substr($periodo['data_fim'], 0, 7))]);
        $espelhos = $competencias->fetchAll();
        $idsEspelhos = array_map(static fn(array $item): int => (int) $item['id'], $espelhos);
        $params = implode(',', array_fill(0, count($idsEspelhos), '?'));
        $diasStmt = $this->db->prepare("SELECT * FROM ponto_dias WHERE espelho_id IN ($params) AND data BETWEEN ? AND ? ORDER BY data");
        $diasStmt->execute([...$idsEspelhos, $periodo['data_inicio'], $periodo['data_fim']]);
        $dias = $diasStmt->fetchAll();
        $marcacoes = [];
        if ($dias !== []) {
            $ids = implode(',', array_map(static fn($d) => (int) $d['id'], $dias));
            foreach ($this->db->query("SELECT * FROM ponto_marcacoes WHERE ponto_dia_id IN ({$ids})") as $m) {
                $marcacoes[(int) $m['ponto_dia_id']][(int) $m['sequencia']] = $m;
            }
        }

        return ['espelho' => $espelho, 'dias' => $dias, 'marcacoes' => $marcacoes,
            'periodo' => $periodo, 'recorte' => $periodo !== $mensal, 'espelhos_periodo' => $espelhos];
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

        $jornadaSemanal = [];
        foreach ($diasSemana as $numero => $rotulo) {
            $variantes = array_values($jornadas[$numero] ?? []);
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
                 WHERE d.id IN ($params) AND d.tipo_dia = 'trabalho' AND t.codigo = 'ATST'"
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
        $root = dirname(__DIR__, 2);
        $script = $root . '/scripts/gerar_folha_pdf.py';
        $tmp = $root . '/storage/tmp_folha_' . bin2hex(random_bytes(4)) . '.json';
        if (!is_dir(dirname($tmp))) {
            mkdir(dirname($tmp), 0775, true);
        }
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json) === false) {
                throw new RuntimeException('Não foi possível preparar os dados do cartão de ponto.');
            }

            $cmdParts = array_merge($this->pythonCmd(), [$script, $tmp, $outPath]);
            $cmd = implode(' ', array_map('escapeshellarg', $cmdParts)) . ' 2>&1';
            exec($cmd, $output, $code);
            if ($code !== 0 || !in_array('layout-pdf=' . self::LAYOUT_PDF_ATUAL, $output, true)
                || !is_file($outPath) || filesize($outPath) === 0) {
                if (is_file($outPath)) {
                    unlink($outPath);
                }
                throw new RuntimeException('Não foi possível gerar o cartão de ponto. Verifique o gerador de PDF no servidor.');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /** @return list<string> */
    private function pythonCmd(): array
    {
        $candidates = [
            ['py', '-3'],
            ['python'],
            ['python3'],
        ];
        foreach ($candidates as $parts) {
            $out = [];
            $code = 1;
            exec(implode(' ', array_map('escapeshellarg', $parts)) . ' --version 2>&1', $out, $code);
            if ($code === 0) {
                return $parts;
            }
        }
        return ['python'];
    }
}
