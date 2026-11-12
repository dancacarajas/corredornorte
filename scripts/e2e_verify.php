<?php

declare(strict_types=1);

/**
 * Verificação ponta a ponta do Arcadis RH.
 * Uso: php scripts/e2e_verify.php
 */

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\FolhaPontoService;
use App\Services\MotorPontoService;
use App\Services\TransferenciaLotacaoService;

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException('FAIL: ' . $msg);
    }
    echo "OK: {$msg}\n";
}

try {
    $pdo = Database::connection();
    assertTrue(true, 'Conexão MySQL');

    $ativos = (int) $pdo->query("SELECT COUNT(*) FROM rh_colaboradores WHERE status='ativo'")->fetchColumn();
    assertTrue($ativos >= 3, "Seeds de colaboradores ({$ativos})");

    $lotacoes = (int) $pdo->query("SELECT COUNT(*) FROM rh_lotacoes WHERE status='ativo'")->fetchColumn();
    assertTrue($lotacoes >= 2, "Seeds de lotações ({$lotacoes})");

    $motor = new MotorPontoService($pdo);

    // Competência dedicada ao teste para permanecer idempotente
    $anoTeste = 2026;
    $mesTeste = 9;
    $ids = $pdo->query(
        "SELECT e.id FROM ponto_espelhos e
         INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
         WHERE pc.ano={$anoTeste} AND pc.mes={$mesTeste}"
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $docIds = $pdo->query("SELECT id FROM documentos WHERE espelho_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
        if ($docIds !== []) {
            $din = implode(',', array_map('intval', $docIds));
            $pdo->exec("DELETE FROM emails_envios WHERE documento_id IN ({$din})");
            $pdo->exec("DELETE FROM documentos_versoes WHERE documento_id IN ({$din})");
            $pdo->exec("DELETE FROM documentos WHERE id IN ({$din})");
        }
        $pdo->exec("DELETE FROM ponto_espelhos WHERE id IN ({$in})");
    }
    $pdo->exec("DELETE FROM ponto_competencias WHERE ano={$anoTeste} AND mes={$mesTeste}");

    $gerados = $motor->gerarCompetenciaParaAtivos($anoTeste, $mesTeste);
    assertTrue($gerados >= 3, "Geração de espelhos ({$gerados})");

    $espelhoId = (int) $pdo->query(
        "SELECT e.id FROM ponto_espelhos e
         INNER JOIN ponto_competencias c ON c.id = e.competencia_id
         WHERE c.ano={$anoTeste} AND c.mes={$mesTeste}
         ORDER BY e.id LIMIT 1"
    )->fetchColumn();
    assertTrue($espelhoId > 0, "Espelho encontrado #{$espelhoId}");

    $dias = (int) $pdo->query("SELECT COUNT(*) FROM ponto_dias WHERE espelho_id={$espelhoId}")->fetchColumn();
    assertTrue($dias >= 28, "Dias do espelho ({$dias})");

    $diaTrabalho = (int) $pdo->query(
        "SELECT id FROM ponto_dias WHERE espelho_id={$espelhoId} AND tipo_dia='trabalho' ORDER BY data LIMIT 1"
    )->fetchColumn();
    assertTrue($diaTrabalho > 0, "Dia útil para ocorrência #{$diaTrabalho}");

    $motor->registrarOcorrencia($diaTrabalho, 'hora_extra', 60, 'E2E HE', 1);
    $he = (int) $pdo->query("SELECT total_he_min FROM ponto_espelhos WHERE id={$espelhoId}")->fetchColumn();
    assertTrue($he >= 60, "Total HE atualizado ({$he})");

    $motor->alterarStatus($espelhoId, 'conferido', 'E2E', 1);
    $motor->alterarStatus($espelhoId, 'fechado', 'E2E', 1);
    $status = (string) $pdo->query("SELECT status FROM ponto_espelhos WHERE id={$espelhoId}")->fetchColumn();
    assertTrue($status === 'fechado', "Espelho fechado ({$status})");

    $folha = new FolhaPontoService($pdo);
    $doc = $folha->gerarPdf($espelhoId, 1);
    assertTrue(is_file($root . '/' . $doc['path']), 'PDF gerado em disco');
    $folha->registrarEnvio($doc['documento_id'], 'joao.silva@arcadis.local', true);
    $env = (int) $pdo->query("SELECT COUNT(*) FROM emails_envios WHERE documento_id={$doc['documento_id']} AND status='enviado'")->fetchColumn();
    assertTrue($env === 1, 'Envio de e-mail registrado');

    // Transferência com vigência (alterna entre lotações ativas)
    $atual = $pdo->query("SELECT lotacao_id, vigencia_inicio FROM rh_colaborador_lotacoes WHERE colaborador_id=1 AND vigencia_fim IS NULL LIMIT 1")->fetch();
    assertTrue($atual !== false, 'Lotação atual do colaborador 1');
    $atualLot = (int) $atual['lotacao_id'];
    $destLot = (int) $pdo->query("SELECT id FROM rh_lotacoes WHERE status='ativo' AND id <> {$atualLot} ORDER BY id LIMIT 1")->fetchColumn();
    assertTrue($destLot > 0, "Lotação destino para transferência #{$destLot}");
    $dataTransfer = max(date('Y-m-d'), (string) $atual['vigencia_inicio']);
    // se já transferiu hoje na mesma data de início, avança 1 dia
    if ($dataTransfer === (string) $atual['vigencia_inicio']) {
        $dataTransfer = date('Y-m-d', strtotime($dataTransfer . ' +1 day'));
    }
    $svc = new TransferenciaLotacaoService($pdo);
    $svc->transferir(1, $destLot, $dataTransfer, 'transferencia', 'E2E transfer', 1);
    $abertas = (int) $pdo->query("SELECT COUNT(*) FROM rh_colaborador_lotacoes WHERE colaborador_id=1 AND vigencia_fim IS NULL")->fetchColumn();
    assertTrue($abertas === 1, 'Apenas uma lotação aberta após transferência');

    // Auth hash
    $hash = (string) $pdo->query("SELECT senha_hash FROM usuarios WHERE email='admin@arcadis.local'")->fetchColumn();
    assertTrue(password_verify('admin123', $hash), 'Senha admin123 válida');

    echo "\nE2E PASSED\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
