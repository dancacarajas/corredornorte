<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\CalculoPontoService;
use App\Services\MotorPontoService;
use App\Services\PeriodoPonto;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PontoController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        $periodo = $this->resolverPeriodo($_GET);
        $dataInicio = $periodo['inicio'];
        $dataFim = $periodo['fim'];
        $mesUnico = substr($dataInicio, 0, 7) === substr($dataFim, 0, 7);
        $avisoPeriodo = $periodo['aviso'];
        $espelhos = [];
        $dbOk = $pdo instanceof PDO;

        if ($dbOk) {
            $sql = <<<SQL
                SELECT e.*, c.nome, c.matricula, c.cargo, pc.ano, pc.mes,
                       COALESCE(d.he, 0) AS periodo_he_min,
                       COALESCE(d.falta, 0) AS periodo_falta_min,
                       COALESCE(d.atraso, 0) AS periodo_atraso_min
                FROM ponto_espelhos e
                INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
                INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
                LEFT JOIN (
                    SELECT espelho_id, SUM(horas_extras_min) AS he, SUM(horas_falta_min) AS falta,
                           SUM(atraso_bruto_min) AS atraso
                    FROM ponto_dias WHERE data BETWEEN :inicio AND :fim GROUP BY espelho_id
                ) d ON d.espelho_id = e.id
                WHERE pc.ano * 100 + pc.mes BETWEEN :competencia_inicio AND :competencia_fim
                ORDER BY c.nome, pc.ano, pc.mes
            SQL;
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'inicio' => $dataInicio,
                'fim' => $dataFim,
                'competencia_inicio' => $this->competencia($dataInicio),
                'competencia_fim' => $this->competencia($dataFim),
            ]);
            $espelhos = $stmt->fetchAll();
        }

        $topbarActions = $mesUnico ? '<form method="post" action="/rh/ponto/gerar" style="display:inline;">'
            . '<input type="hidden" name="data_inicio" value="' . htmlspecialchars($dataInicio, ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="data_fim" value="' . htmlspecialchars($dataFim, ENT_QUOTES, 'UTF-8') . '">'
            . '<button class="btn btn-primary btn-sm" type="submit" data-ponto-gerar>Gerar espelhos ' . date('m/Y', strtotime($dataInicio)) . '</button>'
            . '</form>' : '';

        View::render('rh/ponto/index', [
            'pageTitle' => 'Gestão de Ponto',
            'pageSubtitle' => date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim)),
            'activeMenu' => 'ponto',
            'topbarActions' => $topbarActions,
            'extraCss' => ['css/ponto.css'],
            'dbOk' => $dbOk,
            'espelhos' => $espelhos,
            'mesUnico' => $mesUnico,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'avisoPeriodo' => $avisoPeriodo,
        ]);
    }

    public function gerar(): void
    {
        $transmitir = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/x-ndjson');
        if ($transmitir) {
            $this->iniciarProgressoGeracao();
        }
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            if ($transmitir) {
                $this->emitirProgresso(['type' => 'error', 'message' => 'Banco de dados indisponível.']);
            } else {
                header('Location: /rh/ponto');
            }
            return;
        }
        try {
            @set_time_limit(180);
            $periodo = $this->resolverPeriodo($_POST);
            $dataInicio = $periodo['inicio'];
            $dataFim = $periodo['fim'];
            if ($periodo['aviso'] !== null) {
                throw new RuntimeException($periodo['aviso']);
            }
            if (substr($dataInicio, 0, 7) !== substr($dataFim, 0, 7)) {
                throw new RuntimeException('Selecione uma competência para gerar espelhos.');
            }
            $ano = (int) substr($dataInicio, 0, 4);
            $mes = (int) substr($dataInicio, 5, 2);
            $this->processarGeracao($pdo, $ano, $mes, $transmitir);
            $url = '/rh/ponto?' . http_build_query([
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
            ]);
            if ($transmitir) {
                $this->emitirProgresso(['type' => 'success', 'url' => $url]);
            } else {
                header('Location: ' . $url . '&ok=1');
            }
        } catch (Throwable $e) {
            if ($transmitir) {
                if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
                    $mensagem = $e->getMessage();
                } else {
                    error_log('Falha ao gerar espelhos de ponto: ' . $e->getMessage());
                    $mensagem = 'Não foi possível concluir a geração dos espelhos.';
                }
                $this->emitirProgresso(['type' => 'error', 'message' => $mensagem]);
                return;
            }
            $params = ['erro' => $e->getMessage()];
            if (isset($dataInicio, $dataFim)) {
                $params += ['data_inicio' => $dataInicio, 'data_fim' => $dataFim];
            }
            header('Location: /rh/ponto?' . http_build_query($params));
        }
    }

    private function processarGeracao(PDO $pdo, int $ano, int $mes, bool $transmitir): void
    {
        $competencia = sprintf('%04d-%02d', $ano, $mes);
        $trava = $pdo->prepare("SELECT GET_LOCK(CONCAT('arcadis:ponto:', MD5(DATABASE()), ':', ?), 0)");
        $trava->execute([$competencia]);
        if ((int) $trava->fetchColumn() !== 1) {
            throw new RuntimeException('Já há uma geração de espelhos em andamento para esta competência.');
        }
        try {
            $motor = new MotorPontoService($pdo);
            $calc = new CalculoPontoService($pdo);
            $progressoGeracao = $transmitir
                ? fn(int $concluidos, int $total) => $this->emitirEtapaGeracao('generation', $concluidos, $total)
                : null;
            $motor->gerarCompetenciaParaAtivos($ano, $mes, $progressoGeracao);

            $ids = $pdo->prepare(
                'SELECT e.id FROM ponto_espelhos e
                 INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
                 WHERE pc.ano=:a AND pc.mes=:m AND e.status NOT IN (\'fechado\', \'enviado\', \'assinado\')'
            );
            $ids->execute(['a' => $ano, 'm' => $mes]);
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
        } finally {
            $soltar = $pdo->prepare("SELECT RELEASE_LOCK(CONCAT('arcadis:ponto:', MD5(DATABASE()), ':', ?))");
            $soltar->execute([$competencia]);
        }
    }

    private function recalcularEspelhoGerado(PDO $pdo, CalculoPontoService $calc, int $espelhoId): void
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT status FROM ponto_espelhos WHERE id = ? FOR UPDATE');
            $stmt->execute([$espelhoId]);
            $status = $stmt->fetchColumn();
            if ($status !== false && !in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
                $calc->aplicarBritanicoNoEspelho($espelhoId);
            }
            $pdo->commit();
        } catch (Throwable $erro) {
            $pdo->rollBack();
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

    private function competencia(string $data): int
    {
        return (int) substr($data, 0, 4) * 100 + (int) substr($data, 5, 2);
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
            'SELECT e.*, c.nome, c.matricula, c.cargo, c.funcao, c.email_corp, pc.ano, pc.mes,
                    l.codigo AS lotacao_codigo, ct.codigo AS contrato_codigo, ct.nome AS contrato_nome,
                    cc.codigo AS cc_codigo, os.codigo AS os_codigo, loc.municipio, loc.uf,
                    j.codigo AS jornada_codigo, esc.codigo AS escala_codigo
             FROM ponto_espelhos e
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
             LEFT JOIN rh_lotacoes l ON l.id = e.lotacao_id
             LEFT JOIN rh_contratos ct ON ct.id = l.contrato_id
             LEFT JOIN rh_centros_custo cc ON cc.id = l.centro_custo_id
             LEFT JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
             LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
             LEFT JOIN rh_jornadas j ON j.id = l.jornada_id
             LEFT JOIN rh_escalas esc ON esc.id = l.escala_id
             WHERE e.id = :id'
        );
        $stmt->execute(['id' => $espelhoId]);
        $espelho = $stmt->fetch();
        if ($espelho === false) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Espelho não encontrado', 'activeMenu' => 'ponto']);
            return;
        }

        $ano = (int) $espelho['ano'];
        $mes = (int) $espelho['mes'];
        $erroPeriodo = null;
        try {
            $periodo = PeriodoPonto::selecionar($_GET['data_inicio'] ?? null, $_GET['data_fim'] ?? null, $ano, $mes);
        } catch (RuntimeException $erro) {
            $erroPeriodo = $erro->getMessage();
            $periodo = PeriodoPonto::selecionar(null, null, $ano, $mes);
        }
        $dataInicio = $periodo['data_inicio'];
        $dataFim = $periodo['data_fim'];
        $colaboradorId = (int) $espelho['colaborador_id'];
        $competenciasStmt = $pdo->prepare(
            'SELECT e.id, e.status, e.versao, pc.ano, pc.mes
             FROM ponto_espelhos e INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
             WHERE e.colaborador_id = :colaborador
               AND pc.ano * 100 + pc.mes BETWEEN :competencia_inicio AND :competencia_fim
             ORDER BY pc.ano, pc.mes'
        );
        $competenciasStmt->execute([
            'colaborador' => $colaboradorId,
            'competencia_inicio' => $this->competencia($dataInicio),
            'competencia_fim' => $this->competencia($dataFim),
        ]);
        $competencias = $competenciasStmt->fetchAll();

        $diasStmt = $pdo->prepare(
            'SELECT d.*, e.status AS espelho_status
             FROM ponto_dias d INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             WHERE e.colaborador_id = :colaborador AND d.data BETWEEN :ini AND :fim
             ORDER BY d.data'
        );
        $diasStmt->execute([
            'colaborador' => $colaboradorId,
            'ini' => $dataInicio,
            'fim' => $dataFim,
        ]);
        $dias = $diasStmt->fetchAll();

        $tipos = $pdo->query("SELECT * FROM ponto_justificativa_tipos WHERE status='ativo' ORDER BY nome")->fetchAll();
        $justs = $pdo->prepare(
            'SELECT j.*, t.nome AS tipo_nome FROM ponto_justificativas j
             INNER JOIN ponto_justificativa_tipos t ON t.id = j.tipo_id
             INNER JOIN ponto_espelhos e ON e.id = j.espelho_id
             WHERE e.colaborador_id = :colaborador AND j.data_inicio <= :fim AND j.data_fim >= :ini
             ORDER BY j.data_inicio DESC, j.id DESC'
        );
        $justs->execute(['colaborador' => $colaboradorId, 'ini' => $dataInicio, 'fim' => $dataFim]);

        $qsPeriodo = PeriodoPonto::consulta($periodo);
        $qsSeguro = View::e($qsPeriodo);
        $permiteJustificativa = $this->competencia($dataInicio) === $this->competencia($dataFim)
            && count($competencias) === 1
            && !in_array($competencias[0]['status'], ['fechado', 'enviado', 'assinado'], true);
        $acaoJustificativa = '';
        if ($permiteJustificativa) {
            $competenciaId = (int) $competencias[0]['id'];
            $acaoJustificativa = '<button class="btn btn-danger-soft btn-sm" type="button" data-modal-open="justModal" data-espelho-id="'
                . $competenciaId . '">Adicionar justificativa</button>';
        }
        $topbarActions = ''
            . '<a class="btn btn-secondary btn-sm" href="/rh/ponto?' . $qsSeguro . '">Voltar ao filtro</a>'
            . $acaoJustificativa
            . '<button class="btn btn-secondary btn-sm" type="button" data-modal-open="tiposJustModal">Tipos de justificativa</button>'
            . '<a class="btn btn-primary btn-sm" href="/rh/folha/' . $espelhoId . '?' . $qsSeguro . '">Folha / Enviar PDF</a>';

        View::render('rh/ponto/show', [
            'pageTitle' => (string) $espelho['nome'],
            'pageSubtitle' => trim(($espelho['cargo'] ?: $espelho['funcao'] ?: '') . ' · Mat. ' . $espelho['matricula']),
            'activeMenu' => 'ponto',
            'topbarActions' => $topbarActions,
            'extraCss' => ['css/ponto.css'],
            'espelho' => $espelho,
            'competencias' => $competencias,
            'dias' => $dias,
            'dataInicio' => $dataInicio,
            'dataFim' => $dataFim,
            'tiposJustificativa' => $tipos,
            'justificativas' => $justs->fetchAll(),
            'calc' => new CalculoPontoService($pdo),
            'flash' => $_GET['ok'] ?? null,
            'error' => $erroPeriodo ?? $_GET['erro'] ?? null,
        ]);
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

    public function salvarDia(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        $diaId = (int) ($_POST['ponto_dia_id'] ?? 0);
        if (!$pdo instanceof PDO || $espelhoId <= 0 || $diaId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        try {
            $pdo->beginTransaction();
            $st = $pdo->prepare('SELECT e.status FROM ponto_dias d INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                WHERE d.id = ? AND e.id = ? FOR UPDATE');
            $st->execute([$diaId, $espelhoId]);
            $status = $st->fetchColumn();
            if ($status === false) {
                throw new RuntimeException('O dia informado não pertence a este espelho.');
            }
            if (in_array($status, ['fechado', 'enviado', 'assinado'], true)) {
                throw new RuntimeException('Reabra a competência para editar as batidas.');
            }
            (new CalculoPontoService($pdo))->salvarBatidas($diaId, [
                'ent1' => $_POST['ent1'] ?? null,
                'sai1' => $_POST['sai1'] ?? null,
                'ent2' => $_POST['ent2'] ?? null,
                'sai2' => $_POST['sai2'] ?? null,
            ], 'manual');
            $pdo->commit();
            $this->redirectEspelho($espelhoId, 'batida');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    public function justificativa(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $espelhoId = (int) $id;
        if (!$pdo instanceof PDO || $espelhoId <= 0) {
            header('Location: /rh/ponto');
            exit;
        }
        $dest = null;
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT e.status, pc.ano, pc.mes FROM ponto_espelhos e
                INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id WHERE e.id = ? FOR UPDATE');
            $stmt->execute([$espelhoId]);
            $espelho = $stmt->fetch();
            if (!$espelho || in_array($espelho['status'], ['fechado', 'enviado', 'assinado'], true)) {
                throw new RuntimeException('Reabra a competência para adicionar justificativas.');
            }
            $inicio = $_POST['data_inicio'] ?? null;
            $fim = $_POST['data_fim'] ?? null;
            if (!is_string($inicio) || $inicio === '' || !is_string($fim) || $fim === '') {
                throw new RuntimeException('Informe o período da justificativa.');
            }
            $periodo = PeriodoPonto::selecionar($inicio, $fim, (int) $espelho['ano'], (int) $espelho['mes']);
            $inicio = $periodo['data_inicio'];
            $fim = $periodo['data_fim'];
            $competencia = (int) $espelho['ano'] * 100 + (int) $espelho['mes'];
            if ($this->competencia($inicio) !== $competencia || $this->competencia($fim) !== $competencia) {
                throw new RuntimeException('Registre a justificativa dentro da competência selecionada.');
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
            if (isset($_FILES['anexo']) && ($_FILES['anexo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $dir = dirname(__DIR__, 3) . '/storage/uploads/anexos';
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $_FILES['anexo']['name']) ?: 'anexo.bin';
                $dest = $dir . '/' . bin2hex(random_bytes(8)) . '_' . $safe;
                if (!move_uploaded_file((string) $_FILES['anexo']['tmp_name'], $dest)) {
                    throw new RuntimeException('Não foi possível salvar o anexo.');
                }
                $anexo = 'storage/uploads/anexos/' . basename($dest);
            }
            $pdo->prepare(
                'INSERT INTO ponto_justificativas
                (espelho_id, ponto_dia_id, tipo_id, data_inicio, data_fim, observacao, anexo_path, usuario_id)
                VALUES (:e,:d,:t,:i,:f,:o,:a,:u)'
            )->execute([
                'e' => $espelhoId,
                'd' => $diaId > 0 ? $diaId : null,
                't' => (int) ($_POST['tipo_id'] ?? 0),
                'i' => $inicio,
                'f' => $fim,
                'o' => trim((string) ($_POST['observacao'] ?? '')) ?: null,
                'a' => $anexo,
                'u' => Auth::id(),
            ]);

            // Se abona falta, zera dia_falta nos dias do intervalo
            $tipo = $pdo->prepare('SELECT abona_falta FROM ponto_justificativa_tipos WHERE id=:id');
            $tipo->execute(['id' => (int) ($_POST['tipo_id'] ?? 0)]);
            if ((int) $tipo->fetchColumn() === 1) {
                $pdo->prepare(
                    "UPDATE ponto_dias SET dia_falta=0, horas_falta_min=0, desconto_min=0, ok_status='ok', ocorrencia_resumo='Justificado'
                     WHERE espelho_id=:e AND data BETWEEN :i AND :f AND tipo_dia='trabalho'"
                )->execute([
                    'e' => $espelhoId,
                    'i' => $inicio,
                    'f' => $fim,
                ]);
                (new CalculoPontoService($pdo))->recalcularEspelho($espelhoId);
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
            header('Location: /rh/ponto');
        } catch (Throwable $e) {
            if ($back > 0) {
                $this->redirectEspelho($back, null, $e->getMessage());
            }
            header('Location: /rh/ponto?erro=' . rawurlencode($e->getMessage()));
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
            (new MotorPontoService($pdo))->alterarStatus(
                $espelhoId,
                (string) ($_POST['status'] ?? 'em_tratamento'),
                trim((string) ($_POST['justificativa'] ?? '')) ?: null,
                Auth::id()
            );
            $this->redirectEspelho($espelhoId, 'status');
        } catch (Throwable $e) {
            $this->redirectEspelho($espelhoId, null, $e->getMessage());
        }
    }

    public function apontar(string $id = '0'): void
    {
        $this->salvarDia($id);
    }
}
