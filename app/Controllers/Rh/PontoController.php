<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\CalculoPontoService;
use App\Services\MedicaoService;
use App\Services\MotorPontoService;
use App\Services\PainelExcecoesService;
use App\Services\PeriodoPonto;
use App\Services\PerfilService;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PontoController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $dbOk = $pdo instanceof PDO;
        $periodosGerados = [];
        if ($dbOk) {
            try {
                $periodosGerados = (new MedicaoService($pdo))->listarPeriodosGerados(24);
            } catch (Throwable) {
                $periodosGerados = [];
            }
        }

        $get = $_GET;
        $getIni = isset($get['data_inicio']) ? trim((string) $get['data_inicio']) : '';
        $getFim = isset($get['data_fim']) ? trim((string) $get['data_fim']) : '';
        if ($getIni === '' && $getFim === '' && $periodosGerados !== []) {
            $get['data_inicio'] = (string) $periodosGerados[0]['data_inicio'];
            $get['data_fim'] = (string) $periodosGerados[0]['data_fim'];
        }

        $periodo = $this->resolverPeriodo($get);
        $dataInicio = $periodo['inicio'];
        $dataFim = $periodo['fim'];
        $busca = trim((string) ($_GET['q'] ?? ''));
        $filtroStatus = trim((string) ($_GET['status'] ?? ''));
        $filtroRaw = trim((string) ($_GET['filtro'] ?? ''));
        $filtroEspecial = in_array($filtroRaw, ['divergencia', 'sem_espelho'], true) ? $filtroRaw : '';
        $podeGerar = false;
        try {
            $this->assertPeriodoGeracao($dataInicio, $dataFim);
            $podeGerar = $periodo['aviso'] === null;
        } catch (RuntimeException) {
            $podeGerar = false;
        }
        $avisoPeriodo = $periodo['aviso'];
        $espelhos = [];
        $faltantesSemEspelho = [];
        $periodoGerado = null;
        $resumo = [
            'total' => 0,
            'abertos' => 0,
            'tratamento' => 0,
            'conferidos' => 0,
            'fechados' => 0,
            'he_min' => 0,
            'falta_min' => 0,
            'atraso_min' => 0,
            'he_autorizadas' => 0,
            'he_pendentes' => 0,
        ];

        if ($dbOk) {
            $motor = new MotorPontoService($pdo);
            $periodoGerado = $motor->buscarPeriodo($dataInicio, $dataFim);

            if ($periodoGerado !== null) {
                $sql = <<<SQL
                    SELECT
                        e.id,
                        e.colaborador_id,
                        e.status,
                        c.nome,
                        c.matricula,
                        c.cargo,
                        c.funcao,
                        COALESCE(d.he, 0) AS periodo_he_min,
                        COALESCE(d.falta, 0) AS periodo_falta_min,
                        COALESCE(d.atraso, 0) AS periodo_atraso_min,
                        COALESCE(d.dias_pendentes, 0) AS dias_pendentes
                    FROM ponto_espelhos e
                    INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                    INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                    LEFT JOIN (
                        SELECT espelho_id,
                               SUM(horas_extras_min) AS he,
                               SUM(horas_falta_min) AS falta,
                               SUM(atraso_bruto_min) AS atraso,
                               SUM(CASE
                                     WHEN tipo_dia = 'trabalho'
                                      AND ok_status IN ('pendente','falta')
                                     THEN 1 ELSE 0
                                   END) AS dias_pendentes
                        FROM ponto_dias
                        WHERE data BETWEEN :inicio AND :fim
                        GROUP BY espelho_id
                    ) d ON d.espelho_id = e.id
                    WHERE pp.data_inicio = :p_inicio AND pp.data_fim = :p_fim
                SQL;
                $escopo = \App\Helpers\EscopoAcesso::sqlFiltroColaborador('c');
                $sql .= $escopo['sql'] . ' ORDER BY c.nome';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_merge([
                    'inicio' => $dataInicio,
                    'fim' => $dataFim,
                    'p_inicio' => $dataInicio,
                    'p_fim' => $dataFim,
                ], $escopo['params']));

                $hePorColab = [];
                try {
                    $heStmt = $pdo->prepare(
                        "SELECT colaborador_id,
                                SUM(CASE WHEN status = 'aprovada' THEN 1 ELSE 0 END) AS aprovadas,
                                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes
                         FROM he_solicitacoes
                         WHERE data BETWEEN :ini AND :fim
                         GROUP BY colaborador_id"
                    );
                    $heStmt->execute(['ini' => $dataInicio, 'fim' => $dataFim]);
                    foreach ($heStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $heRow) {
                        $hePorColab[(int) $heRow['colaborador_id']] = $heRow;
                    }
                } catch (Throwable) {
                    $hePorColab = [];
                }

                $espelhosComDivergencia = [];
                if ($filtroEspecial === 'divergencia') {
                    try {
                        $divStmt = $pdo->prepare(
                            "SELECT DISTINCT d.espelho_id
                             FROM ponto_divergencias d
                             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                             WHERE pp.data_inicio = :ini AND pp.data_fim = :fim
                               AND d.status IN ('aberta', 'em_analise')"
                        );
                        $divStmt->execute(['ini' => $dataInicio, 'fim' => $dataFim]);
                        foreach ($divStmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $eid) {
                            $espelhosComDivergencia[(int) $eid] = true;
                        }
                    } catch (Throwable) {
                        $espelhosComDivergencia = [];
                    }
                }

                $idsComEspelho = [];
                foreach ($stmt->fetchAll() as $row) {
                    $status = (string) ($row['status'] ?? 'em_aberto');
                    $cid = (int) $row['colaborador_id'];
                    $idsComEspelho[$cid] = true;
                    $row['he_aprovadas'] = (int) ($hePorColab[$cid]['aprovadas'] ?? 0);
                    $row['he_pendentes'] = (int) ($hePorColab[$cid]['pendentes'] ?? 0);

                    if ($filtroEspecial === 'divergencia' && !isset($espelhosComDivergencia[(int) $row['id']])) {
                        continue;
                    }
                    if ($busca !== '') {
                        $hay = mb_strtolower(
                            (string) $row['nome'] . ' ' . (string) $row['matricula'] . ' '
                            . (string) ($row['cargo'] ?? '') . ' ' . (string) ($row['funcao'] ?? ''),
                            'UTF-8'
                        );
                        if (!str_contains($hay, mb_strtolower($busca, 'UTF-8'))) {
                            continue;
                        }
                    }
                    if ($filtroStatus !== '' && $status !== $filtroStatus) {
                        if (!($filtroStatus === 'fechado' && in_array($status, ['fechado', 'enviado', 'assinado'], true))) {
                            continue;
                        }
                    }

                    $espelhos[] = $row;
                    $resumo['total']++;
                    $resumo['he_min'] += (int) $row['periodo_he_min'];
                    $resumo['falta_min'] += (int) $row['periodo_falta_min'];
                    $resumo['atraso_min'] += (int) $row['periodo_atraso_min'];
                    $resumo['he_autorizadas'] += (int) $row['he_aprovadas'];
                    $resumo['he_pendentes'] += (int) $row['he_pendentes'];
                    if (in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
                        $resumo['fechados']++;
                    } elseif ($status === 'conferido') {
                        $resumo['conferidos']++;
                    } elseif ($status === 'em_tratamento') {
                        $resumo['tratamento']++;
                    } else {
                        $resumo['abertos']++;
                    }
                }
            } else {
                $idsComEspelho = [];
            }

            if ($filtroEspecial === 'sem_espelho') {
                try {
                    $faltantesSemEspelho = PainelExcecoesService::listarColaboradoresSemEspelho(
                        $pdo,
                        $dataInicio,
                        $dataFim,
                        $idsComEspelho
                    );
                } catch (Throwable) {
                    $faltantesSemEspelho = [];
                }
            }
        }

        $rotuloGerar = 'Gerar espelhos do período';
        $periodoInexistente = $dbOk && $periodo['aviso'] === null && $periodoGerado === null;
        $qs = htmlspecialchars(http_build_query([
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
        ]), ENT_QUOTES, 'UTF-8');

        $topbarActions = ''
            . '<a class="btn btn-secondary btn-sm" href="/rh/ponto/justificativa-tipos">Tipos de justificativa</a>';
        if ($podeGerar) {
            $topbarActions .= '<form method="post" action="/rh/ponto/gerar" style="display:inline;">'
                . '<input type="hidden" name="data_inicio" value="' . htmlspecialchars($dataInicio, ENT_QUOTES, 'UTF-8') . '">'
                . '<input type="hidden" name="data_fim" value="' . htmlspecialchars($dataFim, ENT_QUOTES, 'UTF-8') . '">'
                . '<button class="btn btn-primary btn-sm" type="submit" data-ponto-gerar>' . htmlspecialchars($rotuloGerar, ENT_QUOTES, 'UTF-8') . '</button>'
                . '</form>';
        }

        $podeExcluirPeriodo = false;
        if ($dbOk && $periodoGerado !== null) {
            try {
                (new PerfilService($pdo))->ensureCatalog();
            } catch (Throwable) {
            }
            $podeExcluirPeriodo = Auth::can('ponto.excluir_periodo');
        }

        View::render('rh/ponto/index', [
            'pageTitle' => 'Gestão de Ponto',
            'pageSubtitle' => date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim)),
            'activeMenu' => 'ponto',
            'topbarActions' => $topbarActions,
            'extraCss' => ['css/ponto.css?v=excluir-periodo-20261116'],
            'dbOk' => $dbOk,
            'espelhos' => $espelhos,
            'resumo' => $resumo,
            'mesUnico' => true,
            'podeGerar' => $podeGerar,
            'podeExcluirPeriodo' => $podeExcluirPeriodo,
            'rotuloGerar' => $rotuloGerar,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'avisoPeriodo' => $avisoPeriodo,
            'periodoInexistente' => $periodoInexistente,
            'periodoJaGerado' => $periodoGerado !== null,
            'busca' => $busca,
            'filtroStatus' => $filtroStatus,
            'filtroEspecial' => $filtroEspecial,
            'faltantesSemEspelho' => $faltantesSemEspelho,
            'periodosGerados' => $periodosGerados,
            'atalhosPeriodo' => $this->atalhosPeriodo(),
        ]);
    }

    /** Exclui definitivamente o período gerado e todo o conteúdo associado. */
    public function excluirPeriodo(): void
    {
        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO) {
            try {
                (new PerfilService($pdo))->ensureCatalog();
            } catch (Throwable) {
            }
        }
        Auth::requirePermission('ponto.excluir_periodo');

        $periodo = $this->resolverPeriodo($_POST);
        $dataInicio = $periodo['inicio'];
        $dataFim = $periodo['fim'];
        $redirectBase = '/rh/ponto?' . http_build_query([
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
        ]);

        if (!$pdo instanceof PDO) {
            header('Location: ' . $redirectBase . '&erro=' . rawurlencode('Banco indisponível.'));
            exit;
        }

        $confirm = trim((string) ($_POST['confirm_texto'] ?? ''));
        $esperado = 'EXCLUIR';
        if (mb_strtoupper($confirm) !== $esperado) {
            header('Location: ' . $redirectBase . '&erro=' . rawurlencode(
                'Para excluir o período, digite EXCLUIR na confirmação.'
            ));
            exit;
        }

        try {
            $resumo = (new MotorPontoService($pdo))->excluirPeriodoDefinitivo($dataInicio, $dataFim);
            $msg = sprintf(
                'Período %s a %s excluído definitivamente (%d cartão(ões), %d PDF(s), %d RA, %d anexo(s)).',
                date('d/m/Y', strtotime($dataInicio)),
                date('d/m/Y', strtotime($dataFim)),
                (int) $resumo['espelhos'],
                (int) $resumo['documentos'],
                (int) $resumo['ra'],
                (int) $resumo['anexos']
            );
            header('Location: /rh/ponto?ok=' . rawurlencode($msg));
        } catch (Throwable $e) {
            header('Location: ' . $redirectBase . '&erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function acaoLote(): void
    {
        $this->loteStatus();
    }

    public function loteStatus(): void
    {
        $pdo = Db::tryConnection();
        $periodo = $this->resolverPeriodo($_POST);
        $dataInicio = $periodo['inicio'];
        $dataFim = $periodo['fim'];
        $redirect = '/rh/ponto?' . http_build_query([
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
            'q' => (string) ($_POST['q'] ?? ''),
            'status' => (string) ($_POST['filtro_status'] ?? ''),
        ]);

        if (!$pdo instanceof PDO) {
            header('Location: ' . $redirect . '&erro=' . rawurlencode('Banco indisponível.'));
            exit;
        }

        try {
            $acao = trim((string) ($_POST['acao'] ?? ''));
            $novoStatus = trim((string) ($_POST['status'] ?? ''));
            $isPreencherPrevistos = ($acao === 'preencher_previstos' || $novoStatus === 'preencher_previstos');

            if (!$isPreencherPrevistos && !in_array($novoStatus, ['em_tratamento', 'conferido', 'fechado'], true)) {
                throw new RuntimeException('Status de lote inválido.');
            }
            $ids = array_values(array_unique(array_map('intval', (array) ($_POST['espelho_ids'] ?? []))));
            $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
            if ($ids === []) {
                throw new RuntimeException('Selecione ao menos um colaborador.');
            }
            if ($periodo['aviso'] !== null) {
                throw new RuntimeException($periodo['aviso']);
            }

            $motor = new MotorPontoService($pdo);
            $justificativa = trim((string) ($_POST['justificativa'] ?? '')) ?: null;
            $afetados = 0;
            $avisosLote = [];

            foreach ($ids as $espelhoId) {
                $st = $pdo->prepare(
                    'SELECT e.id, c.nome FROM ponto_espelhos e
                     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                     WHERE e.id = :id
                       AND pp.data_inicio = :ini
                       AND pp.data_fim = :fim'
                );
                $st->execute(['id' => $espelhoId, 'ini' => $dataInicio, 'fim' => $dataFim]);
                $rowEspelho = $st->fetch(PDO::FETCH_ASSOC);
                $idEspelho = (int) ($rowEspelho['id'] ?? 0);
                if ($idEspelho <= 0) {
                    continue;
                }
                $nomeColab = trim((string) ($rowEspelho['nome'] ?? ''));

                try {
                    if ($isPreencherPrevistos) {
                        $pdo->beginTransaction();
                        try {
                            $this->preencherPrevistosDoEspelho($pdo, $idEspelho, $dataInicio, $dataFim);
                            $pdo->commit();
                            $afetados++;
                        } catch (Throwable $errPrev) {
                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }
                            throw $errPrev;
                        }
                    } else {
                        $motor->alterarStatus($idEspelho, $novoStatus, $justificativa, Auth::id());
                        $afetados++;
                    }
                } catch (Throwable $e) {
                    $avisosLote[] = ($nomeColab !== '' ? $nomeColab . ': ' : '') . $e->getMessage();
                }
            }

            $statusLabel = $isPreencherPrevistos ? 'previstos preenchidos' : $novoStatus;
            $location = $redirect;
            if ($afetados > 0) {
                $location .= '&ok_lote=' . $afetados . '&status_lote=' . rawurlencode($statusLabel);
            }
            if ($avisosLote !== []) {
                $location .= '&erro=' . rawurlencode(implode(' | ', array_slice($avisosLote, 0, 5)));
            }
            header('Location: ' . $location);
        } catch (Throwable $e) {
            header('Location: ' . $redirect . '&erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    /** @return list<array{label:string,data_inicio:string,data_fim:string}> */
    private function atalhosPeriodo(): array
    {
        $hoje = new \DateTimeImmutable('today');
        $mesAtualIni = $hoje->modify('first day of this month')->format('Y-m-d');
        $mesAtualFim = $hoje->modify('last day of this month')->format('Y-m-d');
        $mesAntIni = $hoje->modify('first day of last month')->format('Y-m-d');
        $mesAntFim = $hoje->modify('last day of last month')->format('Y-m-d');

        // Ciclo 16→15 vigente
        $dia = (int) $hoje->format('j');
        if ($dia >= 16) {
            $cicloIni = $hoje->format('Y-m-16');
            $cicloFim = $hoje->modify('first day of next month')->format('Y-m-15');
        } else {
            $cicloIni = $hoje->modify('first day of last month')->format('Y-m-16');
            $cicloFim = $hoje->format('Y-m-15');
        }

        return [
            ['label' => 'Ciclo 16→15', 'data_inicio' => $cicloIni, 'data_fim' => $cicloFim],
            ['label' => 'Mês atual', 'data_inicio' => $mesAtualIni, 'data_fim' => $mesAtualFim],
            ['label' => 'Mês anterior', 'data_inicio' => $mesAntIni, 'data_fim' => $mesAntFim],
        ];
    }

    public function gerar(): void
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $modoJson = str_contains($accept, 'application/json')
            || isset($_POST['batch_offset'])
            || isset($_POST['phase']);
        $modoNdjson = !$modoJson && str_contains($accept, 'application/x-ndjson');

        if ($modoNdjson) {
            $this->iniciarProgressoGeracao();
        }
        // Libera o lock de sessão para o lote AJAX não enfileirar atrás de outra aba.
        if ($modoJson || $modoNdjson) {
            Auth::start();
            session_write_close();
        }

        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            if ($modoJson) {
                $this->responderJson(['type' => 'error', 'message' => 'Banco de dados indisponível.']);
            } elseif ($modoNdjson) {
                $this->emitirProgresso(['type' => 'error', 'message' => 'Banco de dados indisponível.']);
            } else {
                header('Location: /rh/ponto');
            }
            return;
        }

        try {
            @set_time_limit(120);
            // DDL fora de qualquer GET_LOCK / TX da geração.
            (new \App\Services\HorarioService($pdo))->ensureSchema();
            $periodo = $this->resolverPeriodo($_POST);
            $dataInicio = $periodo['inicio'];
            $dataFim = $periodo['fim'];
            if ($periodo['aviso'] !== null) {
                throw new RuntimeException($periodo['aviso']);
            }
            $this->assertPeriodoGeracao($dataInicio, $dataFim);
            $force = isset($_POST['force']) && (string) $_POST['force'] === '1';
            $url = '/rh/ponto?' . http_build_query([
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
            ]);

            $motor = new MotorPontoService($pdo);
            $existente = $motor->buscarPeriodo($dataInicio, $dataFim);
            $fase = (string) ($_POST['phase'] ?? 'generation');
            $offset = max(0, (int) ($_POST['batch_offset'] ?? 0));

            if ($modoJson && $fase === 'check') {
                if ($existente === null) {
                    $this->responderJson([
                        'type' => 'ready',
                        'exists' => false,
                        'message' => 'Período livre para geração.',
                    ]);
                    return;
                }
                $this->responderJson([
                    'type' => 'exists_confirm',
                    'exists' => true,
                    'espelhos' => $existente['espelhos'],
                    'message' => 'Já existe cartão gerado para este período.',
                ]);
                return;
            }

            // Primeiro lote da geração: exige force se o período já existir.
            if ($existente !== null && !$force && $offset === 0 && $fase === 'generation') {
                if ($modoJson) {
                    $this->responderJson([
                        'type' => 'exists_confirm',
                        'exists' => true,
                        'espelhos' => $existente['espelhos'],
                        'message' => 'Já existe cartão gerado para este período.',
                    ]);
                    return;
                }
                throw new RuntimeException('Já existe cartão gerado para este período. Confirme a sobrescrita para regenerar.');
            }

            if ($modoJson) {
                $this->processarGeracaoEmLotes($pdo, $dataInicio, $dataFim, $url);
                return;
            }

            $this->processarGeracao($pdo, $dataInicio, $dataFim, $modoNdjson);
            if ($modoNdjson) {
                $this->emitirProgresso(['type' => 'success', 'url' => $url]);
            } else {
                header('Location: ' . $url . '&ok=1');
            }
        } catch (Throwable $e) {
            if ($modoJson) {
                error_log('Falha ao gerar espelhos de ponto: ' . $e->getMessage());
                $mensagem = ($e instanceof RuntimeException && !($e instanceof PDOException))
                    ? $e->getMessage()
                    : ('Não foi possível concluir a geração dos espelhos. ' . $e->getMessage());
                $this->responderJson(['type' => 'error', 'message' => mb_substr($mensagem, 0, 280)]);
                return;
            }
            if ($modoNdjson) {
                error_log('Falha ao gerar espelhos de ponto: ' . $e->getMessage());
                $mensagem = ($e instanceof RuntimeException && !($e instanceof PDOException))
                    ? $e->getMessage()
                    : ('Não foi possível concluir a geração dos espelhos. ' . $e->getMessage());
                $this->emitirProgresso(['type' => 'error', 'message' => mb_substr($mensagem, 0, 280)]);
                return;
            }
            $params = ['erro' => $e->getMessage()];
            if (isset($dataInicio, $dataFim)) {
                $params += ['data_inicio' => $dataInicio, 'data_fim' => $dataFim];
            }
            header('Location: /rh/ponto?' . http_build_query($params));
        }
    }

    private function processarGeracaoEmLotes(PDO $pdo, string $dataInicio, string $dataFim, string $url): void
    {
        $fase = (string) ($_POST['phase'] ?? 'generation');
        if (!in_array($fase, ['generation', 'calculation'], true)) {
            $fase = 'generation';
        }
        $offset = max(0, (int) ($_POST['batch_offset'] ?? 0));
        $batchSize = min(5, max(1, (int) ($_POST['batch_size'] ?? 1)));
        $chave = $dataInicio . ':' . $dataFim;
        $rotulo = PeriodoPonto::rotulo(['data_inicio' => $dataInicio, 'data_fim' => $dataFim]);

        $this->comTravaGeracao($pdo, $chave, function () use (
            $pdo,
            $dataInicio,
            $dataFim,
            $url,
            $fase,
            $offset,
            $batchSize,
            $rotulo
        ): void {
            $motor = new MotorPontoService($pdo);
            $calc = new CalculoPontoService($pdo);

            if ($fase === 'generation') {
                $ids = $motor->idsColaboradoresAtivos();
                $total = count($ids);
                if ($total === 0) {
                    $this->responderJson([
                        'type' => 'success',
                        'percent' => 100,
                        'done' => true,
                        'url' => $url,
                        'message' => 'Nenhum colaborador ativo para gerar.',
                    ]);
                    return;
                }

                $fatia = array_slice($ids, $offset, $batchSize);
                $gerados = 0;
                $semLotacao = 0;
                $puladosFechados = 0;
                foreach ($fatia as $colaboradorId) {
                    $resultado = $motor->gerarEspelhoTolerante((int) $colaboradorId, $dataInicio, $dataFim);
                    if ($resultado === 'ok') {
                        $gerados++;
                    } elseif ($resultado === 'skip_lotacao') {
                        $semLotacao++;
                    } elseif ($resultado === 'skip_fechado') {
                        $puladosFechados++;
                    }
                }

                $proximo = $offset + count($fatia);
                $percentual = (int) floor(80 * ($proximo / max(1, $total)));

                if ($proximo < $total) {
                    $detalhe = [];
                    if ($semLotacao > 0) {
                        $detalhe[] = "{$semLotacao} sem lotação";
                    }
                    if ($puladosFechados > 0) {
                        $detalhe[] = "{$puladosFechados} fechados";
                    }
                    $this->responderJson([
                        'type' => 'progress',
                        'phase' => 'generation',
                        'completed' => $proximo,
                        'total' => $total,
                        'percent' => min(79, $percentual),
                        'batch_offset' => $proximo,
                        'done' => false,
                        'generated' => $gerados,
                        'skipped_lotacao' => $semLotacao,
                        'skipped_fechado' => $puladosFechados,
                        'message' => $detalhe !== []
                            ? "Gerando {$rotulo}… (" . implode(', ', $detalhe) . ')'
                            : "Gerando espelhos de {$rotulo}…",
                    ]);
                    return;
                }

                $this->responderJson([
                    'type' => 'progress',
                    'phase' => 'calculation',
                    'completed' => 0,
                    'total' => 0,
                    'percent' => 80,
                    'batch_offset' => 0,
                    'done' => false,
                    'message' => 'Calculando totais de ponto…',
                ]);
                return;
            }

            $sql = 'SELECT e.id FROM ponto_espelhos e
                 INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                 WHERE pp.data_inicio = :ini AND pp.data_fim = :fim
                   AND e.status NOT IN (\'fechado\', \'enviado\', \'assinado\')
                 ORDER BY e.id';
            $ids = $pdo->prepare($sql);
            $ids->execute(['ini' => $dataInicio, 'fim' => $dataFim]);
            $espelhos = array_map(static fn(array $row): int => (int) $row['id'], $ids->fetchAll());
            $total = count($espelhos);
            if ($total === 0 || $offset >= $total) {
                $this->responderJson([
                    'type' => 'success',
                    'percent' => 100,
                    'done' => true,
                    'url' => $url,
                    'message' => 'Espelhos gerados com sucesso.',
                ]);
                return;
            }

            $fatia = array_slice($espelhos, $offset, $batchSize);
            foreach ($fatia as $espelhoId) {
                $this->recalcularEspelhoGerado($pdo, $calc, $espelhoId);
            }
            $proximo = $offset + count($fatia);
            if ($proximo < $total) {
                $percentual = 80 + (int) floor(19 * ($proximo / $total));
                $this->responderJson([
                    'type' => 'progress',
                    'phase' => 'calculation',
                    'completed' => $proximo,
                    'total' => $total,
                    'percent' => min(99, $percentual),
                    'batch_offset' => $proximo,
                    'done' => false,
                    'message' => 'Calculando totais de ponto…',
                ]);
                return;
            }

            $this->responderJson([
                'type' => 'success',
                'percent' => 100,
                'done' => true,
                'url' => $url,
                'message' => 'Espelhos gerados com sucesso.',
            ]);
        });
    }

    private function assertPeriodoGeracao(string $inicio, string $fim): void
    {
        if ($inicio > $fim) {
            throw new RuntimeException('Informe datas inicial e final válidas, em ordem.');
        }
        $dias = (int) (new \DateTimeImmutable($inicio))->diff(new \DateTimeImmutable($fim))->days + 1;
        if ($dias > PeriodoPonto::MAX_DIAS_PDF) {
            throw new RuntimeException(
                'Selecione um período de no máximo ' . PeriodoPonto::MAX_DIAS_PDF . ' dias para gerar espelhos.'
            );
        }
    }

    private function processarGeracao(PDO $pdo, string $dataInicio, string $dataFim, bool $transmitir): void
    {
        $chave = $dataInicio . ':' . $dataFim;
        $this->comTravaGeracao($pdo, $chave, function () use ($pdo, $dataInicio, $dataFim, $transmitir): void {
            $motor = new MotorPontoService($pdo);
            $calc = new CalculoPontoService($pdo);
            $progressoGeracao = $transmitir
                ? fn(int $concluidos, int $total) => $this->emitirEtapaGeracao('generation', $concluidos, $total)
                : null;
            $motor->gerarPeriodoParaAtivos($dataInicio, $dataFim, $progressoGeracao);

            $ids = $pdo->prepare(
                'SELECT e.id FROM ponto_espelhos e
                 INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                 WHERE pp.data_inicio=:i AND pp.data_fim=:f
                   AND e.status NOT IN (\'fechado\', \'enviado\', \'assinado\')'
            );
            $ids->execute(['i' => $dataInicio, 'f' => $dataFim]);
            $espelhos = $ids->fetchAll();
            $total = count($espelhos);
            if ($transmitir) {
                $this->emitirEtapaGeracao('calculation', 0, $total);
            }
            foreach ($espelhos as $indice => $row) {
                $this->recalcularEspelhoGerado($pdo, $calc, (int) $row['id']);
                if ($transmitir) {
                    $this->emitirEtapaGeracao('calculation', $indice + 1, $total);
                }
            }
        });
    }

    /** @param callable():void $fn */
    private function comTravaGeracao(PDO $pdo, string $chavePeriodo, callable $fn): void
    {
        // MySQL limita GET_LOCK a 64 chars: hash único do banco + período.
        $trava = $pdo->prepare('SELECT GET_LOCK(CONCAT(\'ap:\', MD5(CONCAT(DATABASE(), \':\', ?))), 0)');
        $trava->execute([$chavePeriodo]);
        if ((int) $trava->fetchColumn() !== 1) {
            throw new RuntimeException('Já há uma geração de espelhos em andamento para este período.');
        }
        try {
            $fn();
        } finally {
            $soltar = $pdo->prepare('SELECT RELEASE_LOCK(CONCAT(\'ap:\', MD5(CONCAT(DATABASE(), \':\', ?))))');
            $soltar->execute([$chavePeriodo]);
        }
    }

    /** @param array<string,mixed> $payload */
    private function responderJson(array $payload): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-transform');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    private function recalcularEspelhoGerado(PDO $pdo, CalculoPontoService $calc, int $espelhoId): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT status FROM ponto_espelhos WHERE id = ? FOR UPDATE');
            $stmt->execute([$espelhoId]);
            $status = $stmt->fetchColumn();
            if ($status !== false && !in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
                // Na geração o cartão já deve vir preenchido (britânico ou previsto).
                // "Preencher previstos" permanece só como ação manual de reaplicar.
                $calc->aplicarBritanicoNoEspelho($espelhoId);
            }
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $erro) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $erro;
        }
    }

    private function iniciarProgressoGeracao(): void
    {
        session_write_close();
        ignore_user_abort(true);
        @ini_set('zlib.output_compression', '0');
        @ini_set('display_errors', '0');
        while (ob_get_level() > 0 && @ob_end_clean()) {
            // Envia cada atualização ao navegador assim que a etapa progride.
        }
        header('Content-Type: application/x-ndjson; charset=utf-8');
        header('Cache-Control: no-store, no-transform');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('Vary: Accept', false);
    }

    private function emitirEtapaGeracao(string $etapa, int $concluidos, int $total): void
    {
        $fracao = $total > 0 ? $concluidos / $total : 1;
        $percentual = $etapa === 'generation' ? (int) floor(80 * $fracao) : 80 + (int) floor(19 * $fracao);
        $this->emitirProgresso([
            'type' => 'progress',
            'phase' => $etapa,
            'completed' => $concluidos,
            'total' => $total,
            'percent' => $percentual,
        ]);
    }

    private function emitirProgresso(array $evento): void
    {
        echo json_encode($evento, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR) . "\n";
        flush();
    }

    /**
     * @param array<string,mixed> $src
     * @return array{inicio:string,fim:string,aviso:?string}
     */
    private function resolverPeriodo(array $src): array
    {
        $ano = (int) ($src['ano'] ?? date('Y'));
        $mes = (int) ($src['mes'] ?? date('n'));
        if ($ano < 2000 || $ano > 2100 || $mes < 1 || $mes > 12) {
            $ano = (int) date('Y');
            $mes = (int) date('n');
        }
        $aviso = null;
        try {
            $periodo = PeriodoPonto::selecionar($src['data_inicio'] ?? null, $src['data_fim'] ?? null, $ano, $mes);
        } catch (RuntimeException $erro) {
            $aviso = $erro->getMessage();
            $periodo = PeriodoPonto::selecionar(null, null, $ano, $mes);
        }
        return [
            'inicio' => $periodo['data_inicio'],
            'fim' => $periodo['data_fim'],
            'aviso' => $aviso,
        ];
    }

    public function show(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }

        $stmt = $pdo->prepare(
            'SELECT e.*, c.nome, c.matricula, c.cargo, c.funcao, c.email_corp,
                    pp.data_inicio AS periodo_inicio, pp.data_fim AS periodo_fim,
                    YEAR(pp.data_inicio) AS ano, MONTH(pp.data_inicio) AS mes,
                    l.codigo AS lotacao_codigo, ct.codigo AS contrato_codigo, ct.nome AS contrato_nome,
                    cc.codigo AS cc_codigo, os.codigo AS os_codigo, loc.municipio, loc.uf,
                    h.codigo AS horario_codigo, h.nome AS horario_nome
             FROM ponto_espelhos e
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             LEFT JOIN rh_lotacoes l ON l.id = e.lotacao_id
             LEFT JOIN rh_contratos ct ON ct.id = l.contrato_id
             LEFT JOIN rh_centros_custo cc ON cc.id = l.centro_custo_id
             LEFT JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
             LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
             LEFT JOIN rh_horarios h ON h.id = l.horario_id
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $espelhoId]);
        $espelho = $stmt->fetch();
        if ($espelho === false) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Espelho não encontrado', 'activeMenu' => 'ponto']);
            return;
        }

        $periodoEspelho = [
            'data_inicio' => (string) $espelho['periodo_inicio'],
            'data_fim' => (string) $espelho['periodo_fim'],
        ];
        $ano = (int) $espelho['ano'];
        $mes = (int) $espelho['mes'];
        $erroPeriodo = null;
        try {
            $periodo = PeriodoPonto::selecionar(
                $_GET['data_inicio'] ?? $periodoEspelho['data_inicio'],
                $_GET['data_fim'] ?? $periodoEspelho['data_fim'],
                $ano,
                $mes
            );
            // Espelho é de um período fixo: recorte deve ficar dentro dele.
            if ($periodo['data_inicio'] < $periodoEspelho['data_inicio']) {
                $periodo['data_inicio'] = $periodoEspelho['data_inicio'];
            }
            if ($periodo['data_fim'] > $periodoEspelho['data_fim']) {
                $periodo['data_fim'] = $periodoEspelho['data_fim'];
            }
        } catch (RuntimeException $erro) {
            $erroPeriodo = $erro->getMessage();
            $periodo = $periodoEspelho;
        }
        $dataInicio = $periodo['data_inicio'];
        $dataFim = $periodo['data_fim'];
        $colaboradorId = (int) $espelho['colaborador_id'];
        $competencias = [[
            'id' => $espelhoId,
            'status' => $espelho['status'],
            'versao' => $espelho['versao'],
            'data_inicio' => $periodoEspelho['data_inicio'],
            'data_fim' => $periodoEspelho['data_fim'],
        ]];

        $diasStmt = $pdo->prepare(
            'SELECT d.*, e.status AS espelho_status
             FROM ponto_dias d INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             WHERE e.id = :espelho AND d.data BETWEEN :ini AND :fim
             ORDER BY d.data'
        );
        $diasStmt->execute([
            'espelho' => $espelhoId,
            'ini' => $dataInicio,
            'fim' => $dataFim,
        ]);
        $dias = $diasStmt->fetchAll();

        $heAutorizadas = [];
        $heDeclaracoes = [];
        try {
            $heDeclaracoes = (new \App\Services\HeClienteService($pdo))->listarDeclaracoesDoEspelho($espelhoId);
            foreach ($heDeclaracoes as $decl) {
                if (in_array((string) $decl['he_status'], ['declarada', 'aplicada'], true)) {
                    $heAutorizadas[(string) $decl['data']] = [
                        'id' => (int) $decl['ra_dia_id'],
                        'data' => (string) $decl['data'],
                        'hora_inicial' => (string) $decl['he_hora_inicio'],
                        'hora_final' => (string) $decl['he_hora_fim'],
                        'minutos_he' => (int) $decl['minutos'],
                        'modalidade' => 'cliente',
                        'relatorio_atividade' => 'Declarada no Relatório de Atividades',
                        'status' => (string) $decl['he_status'],
                        'sai2_anterior' => $decl['he_sai2_anterior'],
                        'gestor_nome' => $decl['gestor_cliente_nome'] ?? 'Gestor Cliente',
                        'origem' => 'ra_cliente',
                    ];
                }
            }
        } catch (Throwable) {
            $heDeclaracoes = [];
        }

        $this->ensureTiposJustificativa($pdo);
        $tipos = $pdo->query("SELECT * FROM ponto_justificativa_tipos WHERE status='ativo' ORDER BY nome")->fetchAll();
        $justs = $pdo->prepare(
            'SELECT j.*, t.nome AS tipo_nome, t.abona_falta
             FROM ponto_justificativas j
             INNER JOIN ponto_justificativa_tipos t ON t.id = j.tipo_id
             INNER JOIN ponto_espelhos e ON e.id = j.espelho_id
             WHERE e.colaborador_id = :colaborador AND j.data_inicio <= :fim AND j.data_fim >= :ini
             ORDER BY j.data_inicio DESC, j.id DESC'
        );
        $justs->execute(['colaborador' => $colaboradorId, 'ini' => $dataInicio, 'fim' => $dataFim]);

        $qsPeriodo = PeriodoPonto::consulta($periodo);
        $qsSeguro = View::e($qsPeriodo);
        $statusPeriodo = $this->statusPeriodo(array_column($competencias, 'status'));
        $periodoBloqueado = in_array($statusPeriodo, ['fechado', 'enviado', 'assinado'], true);
        $espelhoAcaoId = (int) (($competencias[0]['id'] ?? $espelhoId));
        foreach ($competencias as $comp) {
            if (!in_array((string) $comp['status'], ['fechado', 'enviado', 'assinado'], true)) {
                $espelhoAcaoId = (int) $comp['id'];
                break;
            }
        }
        $permiteJustificativa = !$periodoBloqueado && $competencias !== [];
        $acaoJustificativa = '';
        $acaoTurno = '';
        if ($permiteJustificativa) {
            $acaoJustificativa = '<button class="btn btn-danger-soft btn-sm" type="button" data-modal-open="justModal" data-espelho-id="'
                . $espelhoAcaoId . '">Adicionar justificativa</button>';
            $acaoTurno = '<button class="btn btn-secondary btn-sm" type="button" data-modal-open="turnoAutorizacaoModal" data-espelho-id="'
                . $espelhoAcaoId . '">Alterar Turno (PDF)</button>';
        }
        $topbarActions = ''
            . '<a class="btn btn-secondary btn-sm" href="/rh/ponto?' . $qsSeguro . '">Voltar ao filtro</a>'
            . $acaoTurno
            . $acaoJustificativa
            . '<a class="btn btn-secondary btn-sm" href="/rh/ponto/justificativa-tipos">Tipos de justificativa</a>';

        $divergenciasAbertas = [];
        try {
            $divStmt = $pdo->prepare(
                "SELECT id, data_ocorrencia, motivo, status, created_at
                 FROM ponto_divergencias
                 WHERE espelho_id = :id AND status IN ('aberta','em_analise')
                 ORDER BY created_at DESC, id DESC"
            );
            $divStmt->execute(['id' => $espelhoId]);
            $divergenciasAbertas = $divStmt->fetchAll() ?: [];
        } catch (Throwable) {
            $divergenciasAbertas = [];
        }

        $heAnexosPorDia = [];
        try {
            $heAnexosPorDia = (new \App\Services\PontoHeAutorizacaoService($pdo))->mapPorDiaDoEspelho($espelhoId);
        } catch (Throwable) {
            $heAnexosPorDia = [];
        }

        $turnoAutorizacoes = [];
        $turnoAutorizacoesPorData = [];
        try {
            $turnoSvc = new \App\Services\PontoTurnoAutorizacaoService($pdo);
            $turnoAutorizacoes = $turnoSvc->listarPorEspelho($espelhoId);
            $turnoAutorizacoesPorData = $turnoSvc->mapPorDataDoEspelho($espelhoId);
        } catch (Throwable) {
            $turnoAutorizacoes = [];
            $turnoAutorizacoesPorData = [];
        }

        $docFolha = null;
        $portalUrlFolha = null;
        try {
            $docStmt = $pdo->prepare(
                "SELECT d.* FROM documentos d
                 WHERE d.espelho_id = :id AND d.tipo = 'folha_ponto' AND d.status IN ('gerado','enviado','assinado')
                 ORDER BY d.id DESC LIMIT 1"
            );
            $docStmt->execute(['id' => $espelhoId]);
            $docFolha = $docStmt->fetch() ?: null;
            if (is_array($docFolha) && !empty($docFolha['portal_token'])) {
                $portalUrlFolha = \App\Helpers\AppUrl::base() . '/assinar/' . $docFolha['portal_token'];
            }
        } catch (Throwable) {
            $docFolha = null;
        }

        $justificativasLista = $justs->fetchAll();

        $cltConformidade = [
            'por_dia' => [],
            'resumo' => ['total' => 0, 'bloqueios' => 0, 'alertas' => 0, 'dias_afetados' => 0, 'contexto_escala' => ''],
        ];
        try {
            $cltConformidade = (new \App\Services\ConformidadeCltService($pdo))
                ->avaliarEspelho($espelho, $dias, $justificativasLista);
        } catch (Throwable) {
        }

        View::render('rh/ponto/show', [
            'pageTitle' => (string) $espelho['nome'],
            'pageSubtitle' => trim(($espelho['cargo'] ?: $espelho['funcao'] ?: '') . ' · Mat. ' . $espelho['matricula']),
            'activeMenu' => 'ponto',
            'topbarActions' => $topbarActions,
            'extraCss' => ['css/ponto.css?v=p1-20261119b'],
            'espelho' => $espelho,
            'competencias' => $competencias,
            'statusPeriodo' => $statusPeriodo,
            'periodoBloqueado' => $periodoBloqueado,
            'espelhoAcaoId' => $espelhoAcaoId,
            'dias' => $dias,
            'heAutorizadas' => $heAutorizadas,
            'heAnexosPorDia' => $heAnexosPorDia,
            'heDeclaracoes' => $heDeclaracoes,
            'divergenciasAbertas' => $divergenciasAbertas,
            'cltConformidade' => $cltConformidade,
            'turnoAutorizacoes' => $turnoAutorizacoes,
            'turnoAutorizacoesPorData' => $turnoAutorizacoesPorData,
            'docFolha' => $docFolha,
            'portalUrlFolha' => $portalUrlFolha,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'tiposJustificativa' => $tipos,
            'justificativas' => $justificativasLista,
            'calc' => new CalculoPontoService($pdo),
            'flash' => $_GET['ok'] ?? null,
            'error' => $erroPeriodo ?? $_GET['erro'] ?? null,
        ]);
    }

    /**
     * Status único do período a partir dos espelhos internos (mês).
     * @param list<string> $statusLista
     */
    private function statusPeriodo(array $statusLista): string
    {
        $statusLista = array_values(array_filter(array_map('strval', $statusLista), static fn(string $s): bool => $s !== ''));
        if ($statusLista === []) {
            return 'em_aberto';
        }
        $unicos = array_values(array_unique($statusLista));
        if (count($unicos) === 1) {
            return $unicos[0];
        }
        $bloqueados = ['fechado', 'enviado', 'assinado'];
        $abertos = array_filter($unicos, static fn(string $s): bool => !in_array($s, $bloqueados, true));
        if ($abertos === []) {
            return 'fechado';
        }
        if (in_array('em_tratamento', $unicos, true) || in_array('em_aberto', $unicos, true)) {
            return 'em_tratamento';
        }
        return 'conferido';
    }

    private function redirectEspelho(int $espelhoId, ?string $ok = null, ?string $erro = null): void
    {
        $params = [];
        $ini = $_POST['retorno_data_inicio'] ?? $_POST['data_inicio'] ?? $_GET['data_inicio'] ?? '';
        $fim = $_POST['retorno_data_fim'] ?? $_POST['data_fim'] ?? $_GET['data_fim'] ?? '';
        if (is_string($ini) && is_string($fim)
            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ini) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fim)) {
            $params['data_inicio'] = $ini;
            $params['data_fim'] = $fim;
        }
        $retornoId = (int) ($_POST['espelho_retorno'] ?? 0);
        if ($retornoId > 0 && $retornoId !== $espelhoId && ($pdo = Db::tryConnection()) instanceof PDO) {
            $stmt = $pdo->prepare('SELECT r.id FROM ponto_espelhos r
                INNER JOIN ponto_espelhos e ON e.colaborador_id = r.colaborador_id
                WHERE e.id = ? AND r.id = ?');
            $stmt->execute([$espelhoId, $retornoId]);
            if ($stmt->fetchColumn() !== false) {
                $espelhoId = $retornoId;
            }
        }
        if ($ok !== null) {
            $params['ok'] = $ok;
        }
        if ($erro !== null) {
            $params['erro'] = $erro;
        }
        $qs = $params !== [] ? ('?' . http_build_query($params)) : '';
        header('Location: /rh/ponto/' . $espelhoId . $qs);
        exit;
    }

    public function aplicarHeRa(string $id = '0', string $raDiaId = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $diaRa = (int) $raDiaId;
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $diaRa <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            (new \App\Services\HeClienteService($pdo))->aplicarDoRaDia($diaRa, (int) (\App\Helpers\Auth::id() ?? 0));
            $this->redirectEspelho($espelhoId, 'he-aplicada');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    public function divergenteHeRa(string $id = '0', string $raDiaId = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $diaRa = (int) $raDiaId;
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $diaRa <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            (new \App\Services\HeClienteService($pdo))->marcarDivergente($diaRa, (int) (\App\Helpers\Auth::id() ?? 0));
            $this->redirectEspelho($espelhoId, 'he-divergente');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    public function salvarDia(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $diaId = (int) ($_POST['ponto_dia_id'] ?? 0);
        $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

        if (!$pdo instanceof PDO || $espelhoId <= 0 || $diaId <= 0) {
            if ($wantsJson) {
                http_response_code(400);
                $this->responderJson(['ok' => false, 'error' => 'Parâmetros inválidos para salvar batida.']);
                return;
            }
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $normHora = static function (mixed $raw): ?string {
                $val = trim((string) $raw);
                if ($val === '' || $val === '--:--') {
                    return null;
                }
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $val)) {
                    throw new RuntimeException('Horário inválido: "' . $val . '". Use formato HH:MM.');
                }
                return $val;
            };
            $batidas = [
                'ent1' => $normHora($_POST['ent1'] ?? null),
                'sai1' => $normHora($_POST['sai1'] ?? null),
                'ent2' => $normHora($_POST['ent2'] ?? null),
                'sai2' => $normHora($_POST['sai2'] ?? null),
            ];

            // DDL (CREATE/ALTER) causa commit implícito no MySQL — rodar ANTES da transação.
            \App\Services\PontoHeAutorizacaoService::ensureSchema($pdo);
            \App\Services\ConformidadeCltService::ensureSchema($pdo);

            // Validação trabalhista (CLT) ANTES de gravar batidas, respeitando lotação/escala do colaborador.
            $cltSvc = new \App\Services\ConformidadeCltService($pdo);
            $cltAval = $cltSvc->avaliarAlteracaoDia($espelhoId, $diaId, $batidas);
            $cltConfirmado = ((string) ($_POST['clt_confirmado'] ?? '')) === '1';
            $cltJustificativa = trim((string) ($_POST['clt_justificativa'] ?? ''));

            if ($cltAval['has_violations'] && (!$cltConfirmado || $cltJustificativa === '')) {
                if ($wantsJson) {
                    http_response_code(422);
                    $this->responderJson([
                        'ok' => false,
                        'code' => 'clt_violation',
                        'error' => $cltConfirmado && $cltJustificativa === ''
                            ? 'Informe a justificativa obrigatória para registrar esta exceção trabalhista.'
                            : 'Divergência com a legislação trabalhista (CLT) detectada.',
                        'ponto_dia_id' => $diaId,
                        'clt' => $cltAval,
                    ]);
                    return;
                }
                $primeira = $cltAval['violacoes'][0] ?? null;
                $txt = is_array($primeira)
                    ? ('[' . $primeira['codigo'] . '] ' . $primeira['titulo'] . ' — ' . $primeira['explicacao'])
                    : 'Divergência trabalhista (CLT) detectada.';
                throw new RuntimeException($txt);
            }

            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT e.status FROM ponto_dias d INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                WHERE d.id = ? AND e.id = ? FOR UPDATE');
            $st->execute([$diaId, $espelhoId]);
            $status = $st->fetchColumn();
            if ($status === false) {
                throw new RuntimeException('O dia informado não pertence a este espelho.');
            }
            if (in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
                throw new RuntimeException('Reabra o período para editar as batidas.');
            }
            $calc = new CalculoPontoService($pdo);
            $calc->salvarBatidas($diaId, $batidas, 'manual');

            $heAuth = new \App\Services\PontoHeAutorizacaoService($pdo);
            $diaHeStmt = $pdo->prepare('SELECT COALESCE(horas_extras_min, 0) FROM ponto_dias WHERE id = ?');
            $diaHeStmt->execute([$diaId]);
            $heMin = (int) $diaHeStmt->fetchColumn();
            if ($heMin > \App\Services\PontoHeAutorizacaoService::TOLERANCIA_HE_MIN) {
                $justHe = trim((string) ($_POST['he_justificativa'] ?? ''));
                $temArquivo = isset($_FILES['he_autorizacao']) && (int) ($_FILES['he_autorizacao']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
                if ($temArquivo) {
                    $heAuth->anexar($espelhoId, $diaId, $_FILES['he_autorizacao'], Auth::id(), $heMin, $justHe);
                } elseif (!$heAuth->temAnexo($diaId)) {
                    throw new RuntimeException(
                        'HE_AUTORIZACAO_OBRIGATORIA: Este dia gerou '
                        . $calc->fmt($heMin)
                        . ' de hora extra em relação à escala. Informe a justificativa enviada ao gestor e anexe a autorização de HE (PDF do SharePoint).'
                    );
                } else {
                    try {
                        $pdo->prepare('UPDATE ponto_he_autorizacoes SET minutos_he = ? WHERE ponto_dia_id = ?')
                            ->execute([$heMin, $diaId]);
                    } catch (Throwable) {
                    }
                    if ($justHe !== '') {
                        $heAuth->atualizarJustificativa($espelhoId, $diaId, $justHe, Auth::id());
                    }
                }
            }

            // Espelha HE do cartão no RA (HE Cliente), inclusive quando o anexo já existia.
            try {
                $metaSt = $pdo->prepare(
                    'SELECT d.data, d.previsto_saida, d.sai2, e.colaborador_id, e.periodo_id
                     FROM ponto_dias d
                     INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                     WHERE d.id = ? LIMIT 1'
                );
                $metaSt->execute([$diaId]);
                $meta = $metaSt->fetch(PDO::FETCH_ASSOC);
                if ($meta !== false) {
                    (new \App\Services\RelatorioAtividadesService($pdo))->sincronizarHeDoPontoDia(
                        (int) $meta['colaborador_id'],
                        (int) $meta['periodo_id'],
                        (string) $meta['data'],
                        $diaId,
                        isset($meta['previsto_saida']) ? (string) $meta['previsto_saida'] : null,
                        isset($meta['sai2']) ? (string) $meta['sai2'] : null,
                        $heMin,
                        Auth::id()
                    );
                }
            } catch (Throwable) {
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            if ($cltAval['has_violations'] && $cltConfirmado && $cltJustificativa !== '') {
                $codigos = array_values(array_map(static fn(array $v): string => (string) ($v['codigo'] ?? ''), $cltAval['violacoes']));
                $cltSvc->registrarExcecaoAuditada(
                    $espelhoId,
                    $diaId,
                    (string) ($cltAval['data'] ?: date('Y-m-d')),
                    $codigos,
                    $cltJustificativa,
                    Auth::id()
                );
            }

            if ($wantsJson) {
                $diaRowStmt = $pdo->prepare(
                    'SELECT d.*, e.status AS espelho_status
                     FROM ponto_dias d
                     INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                     WHERE d.id = ?'
                );
                $diaRowStmt->execute([$diaId]);
                $diaRow = $diaRowStmt->fetch() ?: [];

                $ini = (string) ($_POST['data_inicio'] ?? '');
                $fim = (string) ($_POST['data_fim'] ?? '');
                $filtroData = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ini) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fim);
                $totSql = 'SELECT
                        COALESCE(SUM(he_50_min), 0) AS he50,
                        COALESCE(SUM(he_100_min), 0) AS he100,
                        COALESCE(SUM(adn_min), 0) AS adn,
                        COALESCE(SUM(horas_falta_min), 0) AS falta,
                        COALESCE(SUM(atraso_bruto_min), 0) AS atraso,
                        COALESCE(SUM(desconto_min), 0) AS desconto
                     FROM ponto_dias WHERE espelho_id = :e' . ($filtroData ? ' AND data BETWEEN :i AND :f' : '');
                $totStmt = $pdo->prepare($totSql);
                $totParams = ['e' => $espelhoId];
                if ($filtroData) {
                    $totParams['i'] = $ini;
                    $totParams['f'] = $fim;
                }
                $totStmt->execute($totParams);
                $tot = $totStmt->fetch() ?: [];

                $cltAtualizado = [
                    'por_dia' => [],
                    'resumo' => ['total' => 0, 'bloqueios' => 0, 'alertas' => 0, 'dias_afetados' => 0, 'contexto_escala' => ''],
                ];
                try {
                    $espSt = $pdo->prepare('SELECT * FROM ponto_espelhos WHERE id = ? LIMIT 1');
                    $espSt->execute([$espelhoId]);
                    $espRow = $espSt->fetch(PDO::FETCH_ASSOC) ?: [];
                    $diasAllSt = $pdo->prepare('SELECT * FROM ponto_dias WHERE espelho_id = ? ORDER BY data ASC');
                    $diasAllSt->execute([$espelhoId]);
                    $diasAll = $diasAllSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $justAllSt = $pdo->prepare(
                        'SELECT j.*, t.nome AS tipo_nome, t.abona_falta
                         FROM ponto_justificativas j
                         INNER JOIN ponto_justificativa_tipos t ON t.id = j.tipo_id
                         WHERE j.espelho_id = ?'
                    );
                    $justAllSt->execute([$espelhoId]);
                    $justAll = $justAllSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    $cltAtualizado = $cltSvc->avaliarEspelho($espRow, $diasAll, $justAll);
                } catch (Throwable) {
                }

                $this->responderJson([
                    'ok' => true,
                    'espelho_status' => (string) ($diaRow['espelho_status'] ?? $status),
                    'clt' => $cltAtualizado,
                    'dia' => [
                        'id' => (int) ($diaRow['id'] ?? $diaId),
                        'tipo_dia' => (string) ($diaRow['tipo_dia'] ?? ''),
                        'ok_status' => (string) ($diaRow['ok_status'] ?? 'pendente'),
                        'dia_falta' => (int) ($diaRow['dia_falta'] ?? 0),
                        'total_trab_min' => (int) ($diaRow['total_trab_min'] ?? 0),
                        'total_trab_fmt' => $calc->fmt((int) ($diaRow['total_trab_min'] ?? 0)),
                        'horas_previstas_min' => (int) ($diaRow['horas_previstas_min'] ?? 0),
                        'horas_previstas_fmt' => $calc->fmt((int) ($diaRow['horas_previstas_min'] ?? 0)),
                        'horas_falta_min' => (int) ($diaRow['horas_falta_min'] ?? 0),
                        'horas_falta_fmt' => $calc->fmt((int) ($diaRow['horas_falta_min'] ?? 0)),
                        'atraso_bruto_min' => (int) ($diaRow['atraso_bruto_min'] ?? 0),
                        'atraso_bruto_fmt' => $calc->fmt((int) ($diaRow['atraso_bruto_min'] ?? 0)),
                        'he_50_min' => (int) ($diaRow['he_50_min'] ?? 0),
                        'he_50_fmt' => $calc->fmt((int) ($diaRow['he_50_min'] ?? 0)),
                        'he_100_min' => (int) ($diaRow['he_100_min'] ?? 0),
                        'he_100_fmt' => $calc->fmt((int) ($diaRow['he_100_min'] ?? 0)),
                        'adn_min' => (int) ($diaRow['adn_min'] ?? 0),
                        'adn_fmt' => $calc->fmt((int) ($diaRow['adn_min'] ?? 0)),
                        'desconto_min' => (int) ($diaRow['desconto_min'] ?? 0),
                        'desconto_fmt' => $calc->fmt((int) ($diaRow['desconto_min'] ?? 0)),
                        'horas_extras_min' => (int) ($diaRow['horas_extras_min'] ?? 0),
                        'horas_extras_fmt' => $calc->fmt((int) ($diaRow['horas_extras_min'] ?? 0)),
                        'he_anexo' => $heAuth->temAnexo($diaId),
                        'he_anexo_id' => (int) (($heAuth->mapPorDiaDoEspelho($espelhoId)[$diaId]['id'] ?? 0)),
                    ],
                    'totais' => [
                        'he50_fmt' => $calc->fmt((int) ($tot['he50'] ?? 0)),
                        'he100_fmt' => $calc->fmt((int) ($tot['he100'] ?? 0)),
                        'adn_fmt' => $calc->fmt((int) ($tot['adn'] ?? 0)),
                        'falta_fmt' => $calc->fmt((int) ($tot['falta'] ?? 0)),
                        'atraso_fmt' => $calc->fmt((int) ($tot['atraso'] ?? 0)),
                        'desconto_fmt' => $calc->fmt((int) ($tot['desconto'] ?? 0)),
                    ],
                ]);
                return;
            }
            $this->redirectEspelho($espelhoId, 'batida');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $msg = $e->getMessage();
            $needsHe = str_starts_with($msg, 'HE_AUTORIZACAO_OBRIGATORIA:');
            if ($needsHe) {
                $msg = trim(substr($msg, strlen('HE_AUTORIZACAO_OBRIGATORIA:')));
            }
            if ($wantsJson) {
                http_response_code(422);
                $this->responderJson([
                    'ok' => false,
                    'error' => $msg,
                    'code' => $needsHe ? 'needs_he_anexo' : 'error',
                    'ponto_dia_id' => $diaId,
                ]);
                return;
            }
            $this->redirectEspelho($espelhoId, null, $msg);
        }
    }

    /** Registra alteração temporária de turno (ex.: noturno 19h) com anexo PDF obrigatório do cliente. */
    public function salvarTurnoAutorizacao(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            \App\Services\PontoTurnoAutorizacaoService::ensureSchema($pdo);
            $svc = new \App\Services\PontoTurnoAutorizacaoService($pdo);
            $svc->aplicarAlteracaoTurno(
                $espelhoId,
                trim((string) ($_POST['turno_data_inicio'] ?? '')),
                trim((string) ($_POST['turno_data_fim'] ?? '')),
                trim((string) ($_POST['turno_ent1'] ?? '')),
                trim((string) ($_POST['turno_sai1'] ?? '')) ?: null,
                trim((string) ($_POST['turno_ent2'] ?? '')) ?: null,
                trim((string) ($_POST['turno_sai2'] ?? '')),
                ((string) ($_POST['turno_adicional_noturno'] ?? '1')) === '1',
                trim((string) ($_POST['turno_motivo'] ?? '')),
                $_FILES['turno_autorizacao_pdf'] ?? [],
                ((string) ($_POST['turno_aplicar_batidas'] ?? '1')) === '1',
                Auth::id()
            );
            $this->redirectEspelho($espelhoId, 'turno-autorizado');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    /** Download do anexo PDF da autorização de alteração de turno. */
    public function baixarTurnoAutorizacao(string $id = '0', string $authId = '0'): void
    {
        Auth::requirePermission('ponto.ver');
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $aid = (int) $authId;
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $aid <= 0) {
            http_response_code(404);
            echo 'Autorização de turno não encontrada.';
            return;
        }
        try {
            $svc = new \App\Services\PontoTurnoAutorizacaoService($pdo);
            $st = $pdo->prepare(
                'SELECT id, arquivo_path, original_name, mime FROM ponto_turno_autorizacoes
                 WHERE id = ? AND espelho_id = ? LIMIT 1'
            );
            $st->execute([$aid, $espelhoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                http_response_code(404);
                echo 'Autorização de turno não encontrada.';
                return;
            }
            $abs = $svc->caminhoAbsoluto((string) $row['arquivo_path']);
            if (!is_file($abs)) {
                http_response_code(404);
                echo 'Arquivo PDF não encontrado no servidor.';
                return;
            }
            $name = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) ($row['original_name'] ?: 'autorizacao_turno.pdf'));
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . $name . '"');
            header('Content-Length: ' . (string) filesize($abs));
            readfile($abs);
        } catch (Throwable) {
            http_response_code(500);
            echo 'Erro ao abrir anexo de alteração de turno.';
        }
    }

    /** Remove autorização de turno temporário do espelho. */
    public function excluirTurnoAutorizacao(string $id = '0', string $authId = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $aid = (int) $authId;
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $aid <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            (new \App\Services\PontoTurnoAutorizacaoService($pdo))->excluir($espelhoId, $aid);
            $this->redirectEspelho($espelhoId, 'turno-removido');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    /** Download do anexo de autorização de HE do cartão. */
    public function baixarHeAutorizacao(string $id = '0', string $anexoId = '0'): void
    {
        Auth::requirePermission('ponto.ver');
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $aid = (int) $anexoId;
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $aid <= 0) {
            http_response_code(404);
            echo 'Anexo não encontrado.';
            return;
        }
        try {
            $svc = new \App\Services\PontoHeAutorizacaoService($pdo);
            $st = $pdo->prepare(
                'SELECT id, arquivo_path, original_name, mime FROM ponto_he_autorizacoes
                 WHERE id = ? AND espelho_id = ? LIMIT 1'
            );
            $st->execute([$aid, $espelhoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                http_response_code(404);
                echo 'Anexo não encontrado.';
                return;
            }
            $abs = $svc->absPath((string) $row['arquivo_path']);
            if (!is_file($abs)) {
                http_response_code(404);
                echo 'Arquivo ausente.';
                return;
            }
            $mime = (string) ($row['mime'] ?: 'application/octet-stream');
            $name = (string) ($row['original_name'] ?: basename($abs));
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . str_replace('"', '', $name) . '"');
            header('Content-Length: ' . (string) filesize($abs));
            readfile($abs);
        } catch (Throwable $e) {
            http_response_code(500);
            echo 'Falha ao baixar anexo.';
        }
    }

    public function justificativa(string $id = '0'): void
    {
        Auth::requirePermission('ponto.editar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        $dest = null;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT e.status, pp.data_inicio, pp.data_fim,
                        YEAR(pp.data_inicio) AS ano, MONTH(pp.data_inicio) AS mes
                 FROM ponto_espelhos e
                 INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                 WHERE e.id = ? FOR UPDATE'
            );
            $stmt->execute([$espelhoId]);
            $espelho = $stmt->fetch();
            if (!$espelho || in_array($espelho['status'], ['fechado', 'enviado', 'assinado'], true)) {
                throw new RuntimeException('Reabra o período para adicionar justificativas.');
            }
            $inicio = $_POST['data_inicio'] ?? null;
            $fim = $_POST['data_fim'] ?? null;
            if (!is_string($inicio) || $inicio === '' || !is_string($fim) || $fim === '') {
                throw new RuntimeException('Informe o período da justificativa.');
            }
            $periodo = PeriodoPonto::selecionar($inicio, $fim, (int) $espelho['ano'], (int) $espelho['mes']);
            $inicio = max($periodo['data_inicio'], (string) $espelho['data_inicio']);
            $fim = min($periodo['data_fim'], (string) $espelho['data_fim']);
            if ($inicio > $fim) {
                throw new RuntimeException('A justificativa precisa ficar dentro do período do cartão.');
            }
            $periodoFiltroIni = (string) ($_POST['retorno_data_inicio'] ?? '');
            $periodoFiltroFim = (string) ($_POST['retorno_data_fim'] ?? '');
            if (
                preg_match('/^\d{4}-\d{2}-\d{2}$/D', $periodoFiltroIni)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $periodoFiltroFim)
                && ($inicio < $periodoFiltroIni || $fim > $periodoFiltroFim)
            ) {
                throw new RuntimeException('Registre a justificativa dentro do período selecionado.');
            }
            $diaId = (int) ($_POST['ponto_dia_id'] ?? 0);
            if ($diaId > 0) {
                $stmt = $pdo->prepare('SELECT id FROM ponto_dias WHERE id = ? AND espelho_id = ? AND data BETWEEN ? AND ?');
                $stmt->execute([$diaId, $espelhoId, $inicio, $fim]);
                if ($stmt->fetchColumn() === false) {
                    throw new RuntimeException('O período da justificativa deve incluir o dia selecionado.');
                }
            }
            $anexo = null;
            if (isset($_FILES['anexo']) && ($_FILES['anexo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $info = \App\Helpers\UploadGuard::validate(
                    $_FILES['anexo'],
                    ['pdf', 'png', 'jpg', 'jpeg', 'webp'],
                    ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'],
                    10485760
                );
                $dir = dirname(__DIR__, 3) . '/storage/uploads/anexos';
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo((string) $_FILES['anexo']['name'], PATHINFO_FILENAME)) ?: 'anexo';
                $dest = $dir . '/' . bin2hex(random_bytes(8)) . '_' . $safeBase . '.' . $info['ext'];
                if (!move_uploaded_file((string) $_FILES['anexo']['tmp_name'], $dest)) {
                    throw new RuntimeException('Não foi possível salvar o anexo.');
                }
                $anexo = 'storage/uploads/anexos/' . basename($dest);
            }
            $tipoId = (int) ($_POST['tipo_id'] ?? 0);
            if ($tipoId <= 0) {
                throw new RuntimeException('Selecione o tipo de justificativa.');
            }
            $tipo = $pdo->prepare(
                "SELECT id, abona_falta, status, nome FROM ponto_justificativa_tipos WHERE id = :id LIMIT 1"
            );
            $tipo->execute(['id' => $tipoId]);
            $tipoRow = $tipo->fetch(PDO::FETCH_ASSOC);
            if ($tipoRow === false || (string) ($tipoRow['status'] ?? '') !== 'ativo') {
                throw new RuntimeException('Tipo de justificativa inválido ou inativo.');
            }

            $pdo->prepare(
                'INSERT INTO ponto_justificativas
                (espelho_id, ponto_dia_id, tipo_id, data_inicio, data_fim, observacao, anexo_path, usuario_id)
                VALUES (:e,:d,:t,:i,:f,:o,:a,:u)'
            )->execute([
                'e' => $espelhoId,
                'd' => $diaId > 0 ? $diaId : null,
                't' => $tipoId,
                'i' => $inicio,
                'f' => $fim,
                'o' => trim((string) ($_POST['observacao'] ?? '')) ?: null,
                'a' => $anexo,
                'u' => Auth::id(),
            ]);

            $calc = new CalculoPontoService($pdo);
            if ((int) ($tipoRow['abona_falta'] ?? 0) === 1) {
                // Remove batidas do intervalo e recalcula: dias ficam Justificado (sem falta).
                $calc->aplicarJustificativaAbono($espelhoId, $inicio, $fim);
            } else {
                $calc->recalcularEspelho($espelhoId);
            }

            $pdo->commit();
            $this->redirectEspelho($espelhoId, 'justificativa');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($dest !== null && is_file($dest)) {
                unlink($dest);
            }
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    public function excluirJustificativa(string $id = '0', string $justificativaId = '0'): void
    {
        Auth::requirePermission('ponto.editar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $justId = (int) $justificativaId;
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $justId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $st = $pdo->prepare(
                'SELECT j.id, e.status
                 FROM ponto_justificativas j
                 INNER JOIN ponto_espelhos e ON e.id = j.espelho_id
                 WHERE j.id = :j AND j.espelho_id = :e
                 LIMIT 1'
            );
            $st->execute(['j' => $justId, 'e' => $espelhoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('Justificativa não encontrada.');
            }
            if (in_array((string) $row['status'], ['fechado', 'enviado', 'assinado'], true)) {
                throw new RuntimeException('Reabra o período para remover justificativas.');
            }
            $pdo->prepare('DELETE FROM ponto_justificativas WHERE id = :id')->execute(['id' => $justId]);
            (new CalculoPontoService($pdo))->recalcularEspelho($espelhoId);
            $this->redirectEspelho($espelhoId, 'justificativa-removida');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    public function baixarAnexo(string $justificativaId = '0'): void
    {
        Auth::requireLogin();
        if (!Auth::canAny('ponto.ver', 'ponto.editar')) {
            Auth::requirePermission('ponto.ver');
        }
        $pdo = Db::tryConnection();
        $id = (int) $justificativaId;
        if (!$pdo instanceof PDO || $id <= 0) {
            http_response_code(404);
            echo 'Anexo não encontrado';
            return;
        }
        $stmt = $pdo->prepare('SELECT anexo_path FROM ponto_justificativas WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $rel = (string) ($stmt->fetchColumn() ?: '');
        $anexosRoot = realpath(dirname(__DIR__, 3) . '/storage/uploads/anexos');
        $path = $rel !== '' ? realpath(dirname(__DIR__, 3) . '/' . ltrim($rel, '/')) : false;
        if ($anexosRoot === false || $path === false || !str_starts_with($path, $anexosRoot . DIRECTORY_SEPARATOR) || !is_file($path)) {
            http_response_code(404);
            echo 'Arquivo ausente';
            return;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mimeMap = [
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
        ];
        $mime = $mimeMap[$ext] ?? 'application/octet-stream';
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($path)) ?: ('anexo_' . $id);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    public function tipoJustificativa(): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/ponto');
            exit;
        }
        $back = (int) ($_POST['espelho_id'] ?? 0);
        try {
            $pdo->prepare(
                'INSERT INTO ponto_justificativa_tipos (codigo, nome, abona_falta, status)
                 VALUES (:c,:n,:a,\'ativo\')
                 ON DUPLICATE KEY UPDATE nome=VALUES(nome), abona_falta=VALUES(abona_falta), status=\'ativo\''
            )->execute([
                'c' => strtoupper(trim((string) ($_POST['codigo'] ?? ''))),
                'n' => trim((string) ($_POST['nome'] ?? '')),
                'a' => isset($_POST['abona_falta']) ? 1 : 0,
            ]);
            if ($back > 0) {
                $this->redirectEspelho($back, 'tipo');
            }
            $qs = http_build_query(array_filter([
                'data_inicio' => (string) ($_POST['data_inicio'] ?? ''),
                'data_fim' => (string) ($_POST['data_fim'] ?? ''),
                'ok' => 'tipo',
            ]));
            header('Location: /rh/ponto' . ($qs !== '' ? '?' . $qs : ''));
        } catch (Throwable $e) {
            if ($back > 0) {
                $this->redirectEspelho($back, null, $e->getMessage());
            }
            $qs = http_build_query(array_filter([
                'data_inicio' => (string) ($_POST['data_inicio'] ?? ''),
                'data_fim' => (string) ($_POST['data_fim'] ?? ''),
                'erro' => $e->getMessage(),
            ]));
            header('Location: /rh/ponto?' . $qs);
        }
        exit;
    }

    public function ocorrencia(string $id = '0'): void
    {
        $this->salvarDia($id);
    }

    public function status(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $novoStatus = (string) ($_POST['status'] ?? 'em_tratamento');
            $justificativa = trim((string) ($_POST['justificativa'] ?? '')) ?: null;
            $motor = new MotorPontoService($pdo);

            $ini = (string) ($_POST['data_inicio'] ?? '');
            $fim = (string) ($_POST['data_fim'] ?? '');
            $aplicarPeriodo = isset($_POST['aplicar_periodo']) && $_POST['aplicar_periodo'] === '1';
            $ids = [$espelhoId];

            if ($aplicarPeriodo
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ini)
                && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fim)
            ) {
                $st = $pdo->prepare(
                    'SELECT e.id FROM ponto_espelhos e
                     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                     WHERE e.id = :id
                       AND pp.data_inicio = :ini
                       AND pp.data_fim = :fim'
                );
                $st->execute(['id' => $espelhoId, 'ini' => $ini, 'fim' => $fim]);
                $idOk = (int) $st->fetchColumn();
                $ids = $idOk > 0 ? [$idOk] : [$espelhoId];
            }

            foreach ($ids as $idEspelho) {
                $motor->alterarStatus($idEspelho, $novoStatus, $justificativa, Auth::id());
            }
            $this->redirectEspelho($espelhoId, 'status');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    public function apontar(string $id = '0'): void
    {
        $this->salvarDia($id);
    }

    /**
     * Preenche dias úteis pendentes (sem batidas) com o horário previsto do dia/jornada.
     */
    public function apontarPrevisto(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $pdo->beginTransaction();
            $ini = trim((string) ($_POST['data_inicio'] ?? ''));
            $fim = trim((string) ($_POST['data_fim'] ?? ''));
            $this->preencherPrevistosDoEspelho($pdo, $espelhoId, $ini, $fim);
            $pdo->commit();
            $this->redirectEspelho($espelhoId, 'previsto-aplicado');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    /** Preenche previstos de um espelho (uso interno e lote da Medição). */
    public function preencherPrevistosDoEspelho(PDO $pdo, int $espelhoId, string $ini = '', string $fim = ''): int
    {
        $st = $pdo->prepare('SELECT status FROM ponto_espelhos WHERE id = ? FOR UPDATE');
        $st->execute([$espelhoId]);
        $status = $st->fetchColumn();
        if ($status === false) {
            throw new RuntimeException('Espelho não encontrado.');
        }
        if (in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
            throw new RuntimeException('Reabra o período para preencher os horários previstos.');
        }

        $filtroData = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $ini) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fim);

        $sqlDias = "SELECT id, previsto_entrada, previsto_intervalo_inicio, previsto_intervalo_fim, previsto_saida
                    FROM ponto_dias
                    WHERE espelho_id = :id
                      AND tipo_dia = 'trabalho'
                      AND ent1 IS NULL AND sai1 IS NULL AND ent2 IS NULL AND sai2 IS NULL"
            . ($filtroData ? ' AND data BETWEEN :ini AND :fim' : '')
            . ' ORDER BY data ASC';
        $qDias = $pdo->prepare($sqlDias);
        $params = ['id' => $espelhoId];
        if ($filtroData) {
            $params['ini'] = $ini;
            $params['fim'] = $fim;
        }
        $qDias->execute($params);
        $pendentes = $qDias->fetchAll() ?: [];

        $calc = new CalculoPontoService($pdo);
        $aplicados = 0;

        if ($pendentes !== []) {
            $diaIds = array_map(static fn(array $r): int => (int) $r['id'], $pendentes);
            $in = implode(',', $diaIds);
            $marcacoes = [];
            foreach ($pdo->query(
                "SELECT ponto_dia_id, sequencia, horario, origem
                 FROM ponto_marcacoes
                 WHERE ponto_dia_id IN ({$in}) AND origem IN ('gerado','previsto')"
            ) as $mRow) {
                $marcacoes[(int) $mRow['ponto_dia_id']][(string) $mRow['origem']][(int) $mRow['sequencia']] = substr((string) $mRow['horario'], 0, 5);
            }

            $metaDia = $pdo->prepare('SELECT data FROM ponto_dias WHERE id = :id');
            foreach ($pendentes as $dia) {
                $did = (int) $dia['id'];
                $metaDia->execute(['id' => $did]);
                $dataDia = (string) ($metaDia->fetchColumn() ?: '');
                // Não preenche presença em dia coberto por justificativa que abona falta.
                if ($calc->justificativaAbonoDoDia($espelhoId, $dataDia) !== null) {
                    continue;
                }
                $map = [];
                foreach ($marcacoes[$did]['gerado'] ?? [] as $seq => $hora) {
                    $slot = $seq >= 5 ? $seq - 4 : $seq;
                    $map[$slot] = $hora;
                }
                if ($map === []) {
                    foreach ($marcacoes[$did]['previsto'] ?? [] as $seq => $hora) {
                        $map[$seq] = $hora;
                    }
                }
                $e1 = $map[1] ?? (!empty($dia['previsto_entrada']) ? substr((string) $dia['previsto_entrada'], 0, 5) : null);
                $s1 = $map[2] ?? (!empty($dia['previsto_intervalo_inicio']) ? substr((string) $dia['previsto_intervalo_inicio'], 0, 5) : null);
                $e2 = $map[3] ?? (!empty($dia['previsto_intervalo_fim']) ? substr((string) $dia['previsto_intervalo_fim'], 0, 5) : null);
                $s2 = $map[4] ?? (!empty($dia['previsto_saida']) ? substr((string) $dia['previsto_saida'], 0, 5) : null);
                if (!$e1 && !$s2) {
                    continue;
                }
                $calc->salvarBatidas($did, [
                    'ent1' => $e1,
                    'sai1' => $s1,
                    'ent2' => $e2,
                    'sai2' => $s2,
                ], 'gerado', false);
                $aplicados++;
            }
        }

        $calc->recalcularEspelho($espelhoId);
        if ($aplicados > 0) {
            $pdo->prepare("UPDATE ponto_espelhos SET status='em_tratamento' WHERE id=:id AND status='em_aberto'")
                ->execute(['id' => $espelhoId]);
        }

        return $aplicados;
    }

    /**
     * Aplica em lote todas as HEs declaradas no Relatório de Atividades deste espelho.
     */
    public function heClienteAplicarTodas(string $id = '0'): void
    {
        Auth::requirePermission('ponto.editar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $heService = new \App\Services\HeClienteService($pdo);
            $uid = (int) (Auth::id() ?? 0);
            $n = 0;
            if (method_exists($heService, 'aplicarTodasDoEspelho')) {
                $n = (int) $heService->aplicarTodasDoEspelho($espelhoId, $uid);
            } else {
                foreach ($heService->listarDeclaracoesDoEspelho($espelhoId) as $decl) {
                    if (($decl['he_status'] ?? '') === 'declarada') {
                        $heService->aplicarDoRaDia((int) $decl['ra_dia_id'], $uid);
                        $n++;
                    }
                }
            }
            if ($n <= 0) {
                throw new RuntimeException('Nenhuma HE declarada pendente para aplicar neste período.');
            }
            $this->redirectEspelho($espelhoId, 'he-todas-aplicadas');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    /**
     * Marca divergências apontadas pelo colaborador como resolvidas e devolve o espelho para tratamento.
     */
    public function resolverDivergencia(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $obs = trim((string) ($_POST['resolucao_obs'] ?? ''));
            (new \App\Services\AssinaturaEletronicaService($pdo))->resolverDivergencia($espelhoId, Auth::id(), $obs);
            $this->redirectEspelho($espelhoId, 'divergencia-resolvida');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    /**
     * Atalho antigo (reenviar direto) desativado: divergência deve voltar à conferência.
     */
    public function resolverDivergenciaEReenviar(string $id = '0'): void
    {
        $this->resolverDivergencia($id);
    }

    /** Emite PDF + link de assinatura sem sair do cartão de ponto. */
    public function gerarFolhaPdf(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $motor = new MotorPontoService($pdo);
            $st = $pdo->prepare('SELECT status FROM ponto_espelhos WHERE id = ?');
            $st->execute([$espelhoId]);
            $status = (string) ($st->fetchColumn() ?: '');
            $just = 'Fechamento para emissão da folha no ponto';
            if (in_array($status, ['em_aberto', 'em_tratamento'], true)) {
                $motor->alterarStatus($espelhoId, 'conferido', $just, Auth::id());
                $motor->alterarStatus($espelhoId, 'fechado', $just, Auth::id());
            } elseif ($status === 'conferido') {
                $motor->alterarStatus($espelhoId, 'fechado', $just, Auth::id());
            }
            $ini = trim((string) ($_POST['data_inicio'] ?? ''));
            $fim = trim((string) ($_POST['data_fim'] ?? ''));
            $pdf = (new \App\Services\FolhaPontoService($pdo))->gerarPdf(
                $espelhoId,
                Auth::id(),
                $ini !== '' ? $ini : null,
                $fim !== '' ? $fim : null
            );
            if (empty($pdf['portal_token'])) {
                throw new RuntimeException('PDF gerado sem link de assinatura.');
            }
            $this->redirectEspelho($espelhoId, 'folha-pdf-link');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    /** Envia e-mail com link da folha ativa, permanecendo no cartão. */
    public function enviarFolhaEmail(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $docId = (int) ($_POST['documento_id'] ?? 0);
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $docId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $res = (new \App\Services\FolhaPontoService($pdo))->enviarDocumento($docId, null, $espelhoId);
            if (!($res['ok'] ?? false)) {
                throw new RuntimeException((string) ($res['erro'] ?? 'Falha no envio'));
            }
            $this->redirectEspelho($espelhoId, 'folha-email');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    /** Garante tipos padrão quando a tabela existe mas está vazia (install antigo). */
    private function ensureTiposJustificativa(PDO $pdo): void
    {
        try {
            $n = (int) $pdo->query('SELECT COUNT(*) FROM ponto_justificativa_tipos')->fetchColumn();
            if ($n > 0) {
                return;
            }
            $pdo->exec(
                "INSERT IGNORE INTO ponto_justificativa_tipos (codigo, nome, abona_falta, status) VALUES
                 ('atestado', 'Atestado médico', 1, 'ativo'),
                 ('ferias', 'Férias', 1, 'ativo'),
                 ('folga_compensada', 'Folga compensada', 1, 'ativo'),
                 ('outros', 'Outros', 0, 'ativo')"
            );
        } catch (Throwable) {
        }
    }
}

