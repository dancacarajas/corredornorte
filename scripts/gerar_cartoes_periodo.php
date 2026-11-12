<?php

declare(strict_types=1);

/**
 * Gera cartões de ponto (espelho + britânico + PDF) para todos os ativos
 * cobrindo o período 15/10/2026 a 16/11/2026 (competências 10/2026 e 11/2026).
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

$periodoInicio = '2026-10-15';
$periodoFim = '2026-11-16';
$competencias = [
    [2026, 10],
    [2026, 11],
];

echo "Ajustando vigência de lotação para cobrir o período...\n";
$pdo->exec(
    "UPDATE rh_colaborador_lotacoes cl
     INNER JOIN rh_colaboradores c ON c.id = cl.colaborador_id
     SET cl.vigencia_inicio = '2026-10-01',
         cl.escala_ancora_data = COALESCE(cl.escala_ancora_data, '2026-10-01'),
         cl.vigencia_fim = NULL
     WHERE c.status = 'ativo' AND cl.vigencia_fim IS NULL"
);

$ativos = $pdo->query("SELECT id, matricula, nome FROM rh_colaboradores WHERE status='ativo' ORDER BY matricula")->fetchAll();
echo 'Ativos=' . count($ativos) . "\n";

$motor = new MotorPontoService($pdo);
$calc = new CalculoPontoService($pdo);
$folha = new FolhaPontoService($pdo);

$okEspelho = 0;
$failEspelho = 0;
$okPdf = 0;
$failPdf = 0;

foreach ($competencias as [$ano, $mes]) {
    echo sprintf("\n=== Competência %02d/%d ===\n", $mes, $ano);
    foreach ($ativos as $colab) {
        $cid = (int) $colab['id'];
        $mat = (string) $colab['matricula'];
        try {
            // Reabre se estiver fechado para regenerar
            $st = $pdo->prepare(
                'SELECT e.id, e.status
                 FROM ponto_espelhos e
                 INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
                 WHERE e.colaborador_id = :c AND pc.ano = :a AND pc.mes = :m
                 LIMIT 1'
            );
            $st->execute(['c' => $cid, 'a' => $ano, 'm' => $mes]);
            $esp = $st->fetch();
            if ($esp && in_array($esp['status'], ['fechado', 'enviado', 'assinado', 'conferido'], true)) {
                $pdo->prepare("UPDATE ponto_espelhos SET status='em_tratamento' WHERE id=:id")
                    ->execute(['id' => (int) $esp['id']]);
            }

            $espelhoId = $motor->gerarEspelho($cid, $ano, $mes, 1);
            $calc->aplicarBritanicoNoEspelho($espelhoId);
            $calc->recalcularEspelho($espelhoId);

            // Confere e fecha para permitir PDF do cartão
            $motor->alterarStatus($espelhoId, 'conferido', 'Conferência automática cartão período', 1);
            $motor->alterarStatus($espelhoId, 'fechado', 'Fechamento automático cartão período', 1);

            try {
                $folha->gerarPdf($espelhoId, 1);
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
}

$q = $pdo->prepare(
    "SELECT COUNT(*) FROM ponto_espelhos e
     INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
     WHERE c.status='ativo' AND ((pc.ano=2026 AND pc.mes=10) OR (pc.ano=2026 AND pc.mes=11))"
);
$q->execute();
$totalEsp = (int) $q->fetchColumn();

$docs = (int) $pdo->query(
    "SELECT COUNT(*) FROM documentos d
     INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
     INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
     INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
     WHERE c.status='ativo'
       AND d.tipo='folha_ponto'
       AND d.status <> 'invalidado'
       AND ((pc.ano=2026 AND pc.mes=10) OR (pc.ano=2026 AND pc.mes=11))"
)->fetchColumn();

echo "\n=== Resumo ===\n";
echo "Período solicitado: {$periodoInicio} a {$periodoFim}\n";
echo "Espelhos OK={$okEspelho} FAIL={$failEspelho}\n";
echo "PDFs OK={$okPdf} FAIL={$failPdf}\n";
echo "Espelhos ativos out+nov={$totalEsp}\n";
echo "Documentos cartão válidos={$docs}\n";
echo "Abrir gestão: /rh/ponto?data_inicio=2026-10-15&data_fim=2026-11-16\n";
echo "Abrir folha: /rh/folha\n";
