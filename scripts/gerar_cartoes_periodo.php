<?php

declare(strict_types=1);

/**
 * Gera cartões de ponto (espelho + britânico + PDF) para todos os ativos
 * no período exato informado (padrão: 16/10/2026 a 15/11/2026).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;
use App\Services\CalculoPontoService;
use App\Services\FolhaPontoService;
use App\Services\MotorPontoService;

@set_time_limit(0);

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

$periodoInicio = $argv[1] ?? '2026-10-16';
$periodoFim = $argv[2] ?? '2026-11-15';

echo "Ajustando vigência de lotação para cobrir o período...\n";
$pdo->exec(
    "UPDATE rh_colaborador_lotacoes cl
     INNER JOIN rh_colaboradores c ON c.id = cl.colaborador_id
     SET cl.vigencia_inicio = LEAST(cl.vigencia_inicio, '{$periodoInicio}'),
         cl.escala_ancora_data = COALESCE(cl.escala_ancora_data, '{$periodoInicio}'),
         cl.vigencia_fim = NULL
     WHERE c.status = 'ativo' AND cl.vigencia_fim IS NULL"
);

$ativos = $pdo->query("SELECT id, matricula, nome FROM rh_colaboradores WHERE status='ativo' ORDER BY matricula")->fetchAll();
echo 'Ativos=' . count($ativos) . "\n";
echo "Período: {$periodoInicio} a {$periodoFim}\n";

$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);
$folha = new FolhaPontoService($pdo);

$okEspelho = 0;
$failEspelho = 0;
$okPdf = 0;
$failPdf = 0;

foreach ($ativos as $colab) {
    $cid = (int) $colab['id'];
    $mat = (string) $colab['matricula'];
    try {
        $st = $pdo->prepare(
            'SELECT e.id, e.status
             FROM ponto_espelhos e
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             WHERE e.colaborador_id = :c AND pp.data_inicio = :i AND pp.data_fim = :f
             LIMIT 1'
        );
        $st->execute(['c' => $cid, 'i' => $periodoInicio, 'f' => $periodoFim]);
        $esp = $st->fetch();
        if ($esp && in_array($esp['status'], ['fechado', 'enviado', 'assinado', 'conferido'], true)) {
            $pdo->prepare("UPDATE ponto_espelhos SET status='em_tratamento' WHERE id=:id")
                ->execute(['id' => (int) $esp['id']]);
        }

        $espelhoId = $motor->gerarEspelho($cid, $periodoInicio, $periodoFim, 1);
        $calc->aplicarBritanicoNoEspelho($espelhoId);
        $calc->recalcularEspelho($espelhoId);

        $motor->alterarStatus($espelhoId, 'conferido', 'Conferência automática cartão período', 1);
        $motor->alterarStatus($espelhoId, 'fechado', 'Fechamento automático cartão período', 1);

        try {
            $folha->gerarPdf($espelhoId, 1, $periodoInicio, $periodoFim);
            $okPdf++;
        } catch (Throwable $e) {
            $failPdf++;
            echo "PDF FAIL {$mat}: {$e->getMessage()}\n";
        }

        $okEspelho++;
        if ($okEspelho % 10 === 0) {
            echo "… {$okEspelho} espelhos\n";
        }
    } catch (Throwable $e) {
        $failEspelho++;
        echo "ESPELHO FAIL {$mat}: {$e->getMessage()}\n";
    }
}

$q = $pdo->prepare(
    "SELECT COUNT(*) FROM ponto_espelhos e
     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
     WHERE c.status='ativo' AND pp.data_inicio = ? AND pp.data_fim = ?"
);
$q->execute([$periodoInicio, $periodoFim]);
$totalEsp = (int) $q->fetchColumn();

$docsQ = $pdo->prepare(
    "SELECT COUNT(*) FROM documentos d
     INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
     WHERE c.status='ativo'
       AND d.tipo='folha_ponto'
       AND d.status <> 'invalidado'
       AND pp.data_inicio = ? AND pp.data_fim = ?"
);
$docsQ->execute([$periodoInicio, $periodoFim]);
$docs = (int) $docsQ->fetchColumn();

echo "\n=== Resumo ===\n";
echo "Período solicitado: {$periodoInicio} a {$periodoFim}\n";
echo "Espelhos OK={$okEspelho} FAIL={$failEspelho}\n";
echo "PDFs OK={$okPdf} FAIL={$failPdf}\n";
echo "Espelhos ativos no período={$totalEsp}\n";
echo "Documentos cartão válidos={$docs}\n";
echo "Abrir gestão: /rh/ponto?data_inicio={$periodoInicio}&data_fim={$periodoFim}\n";
echo "Abrir folha: /rh/folha\n";
