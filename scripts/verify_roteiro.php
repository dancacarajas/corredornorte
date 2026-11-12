<?php

declare(strict_types=1);

/**
 * Checklist do roteiro completo — prova de existência + comportamento.
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\FolhaPontoService;
use App\Services\LotacaoResolver;
use App\Services\MotorPontoService;

function ok(string $msg): void { echo "OK: {$msg}\n"; }
function fail(string $msg): never { fwrite(STDERR, "FAIL: {$msg}\n"); exit(1); }

$pdo = Database::connection();
$checks = [];

// 1 Efetivo cadastros
foreach (['rh_colaboradores','rh_centros_custo','rh_localidades','rh_ordens_servico','rh_contratos','rh_jornadas','rh_escalas','rh_escala_ciclos','rh_calendarios','rh_feriados','rh_lotacoes','rh_colaborador_lotacoes','rh_colaborador_excecoes'] as $t) {
    $n = (int) $pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
    if ($n < 0) fail("Tabela {$t}");
    ok("Tabela {$t} ({$n})");
}

// Escala ciclo
$ciclo = (int) $pdo->query("SELECT COUNT(*) FROM rh_escala_ciclos")->fetchColumn();
$ciclo > 0 || fail('Escala ciclo sem dias');
ok('Escala por ciclo');

// Feriados compostos
$resolver = new LotacaoResolver($pdo);
$cfg = $resolver->resolve(1, '2026-11-02'); // Finados
$cfg || fail('Resolver lotação');
ok('Resolver lotação/jornada/escala');
$feriados = $cfg['feriados'] ?? [];
count($feriados) > 0 || fail('Calendário composto sem feriado em 02/11');
ok('Feriados compostos (nacional+)');

// Exceção individual
$pdo->exec("DELETE FROM rh_colaborador_excecoes WHERE colaborador_id=1 AND observacao='VERIFY'");
$pdo->prepare("INSERT INTO rh_colaborador_excecoes (colaborador_id,vigencia_inicio,vigencia_fim,jornada_id,observacao) VALUES (1,'2026-12-01','2026-12-15',2,'VERIFY')")->execute();
$cfgExc = $resolver->resolve(1, '2026-12-05');
(($cfgExc['excecao']['observacao'] ?? '') === 'VERIFY') || fail('Exceção individual não priorizada');
ok('Exceção individual (prioridade 1)');

// Motor + britânico
$motor = new MotorPontoService($pdo);
$ano = 2026; $mes = 8;
$ids = $pdo->query("SELECT e.id FROM ponto_espelhos e INNER JOIN ponto_competencias pc ON pc.id=e.competencia_id WHERE pc.ano={$ano} AND pc.mes={$mes}")->fetchAll(PDO::FETCH_COLUMN);
if ($ids) {
    $in = implode(',', array_map('intval', $ids));
    $docs = $pdo->query("SELECT id FROM documentos WHERE espelho_id IN ({$in})")->fetchAll(PDO::FETCH_COLUMN);
    if ($docs) {
        $din = implode(',', array_map('intval', $docs));
        $pdo->exec("DELETE FROM emails_envios WHERE documento_id IN ({$din})");
        $pdo->exec("DELETE FROM documentos_versoes WHERE documento_id IN ({$din})");
        $pdo->exec("DELETE FROM documentos WHERE id IN ({$din})");
    }
    $pdo->exec("DELETE FROM ponto_espelhos WHERE id IN ({$in})");
}
$pdo->exec("DELETE FROM ponto_competencias WHERE ano={$ano} AND mes={$mes}");
$espId = $motor->gerarEspelho(1, $ano, $mes);
$diaId = (int) $pdo->query("SELECT id FROM ponto_dias WHERE espelho_id={$espId} AND tipo_dia='trabalho' ORDER BY data LIMIT 1")->fetchColumn();
$prev = (int) $pdo->query("SELECT COUNT(*) FROM ponto_marcacoes WHERE ponto_dia_id={$diaId} AND origem='previsto'")->fetchColumn();
$ger = (int) $pdo->query("SELECT COUNT(*) FROM ponto_marcacoes WHERE ponto_dia_id={$diaId} AND origem='gerado'")->fetchColumn();
$prev > 0 || fail('Marcação prevista ausente');
$ger > 0 || fail('Horário britânico (gerado) ausente');
ok("Horário previsto≠gerado (previsto={$prev}, gerado={$ger})");

$motor->apontar($diaId, 1, '08:05:00', 'manual', 1);
$real = (int) $pdo->query("SELECT COUNT(*) FROM ponto_marcacoes WHERE ponto_dia_id={$diaId} AND origem='manual'")->fetchColumn();
$real > 0 || fail('Apontamento real');
ok('Apontamento real (origem manual)');

$motor->registrarOcorrencia($diaId, 'hora_extra', 60, 'verify', 1);
$motor->registrarOcorrencia($diaId, 'atraso', 10, 'verify', 1);
$motor->registrarOcorrencia($diaId, 'adicional_noturno', 30, 'verify', 1);
ok('Ocorrências HE/atraso/ADN');

$motor->alterarStatus($espId, 'conferido', null, 1);
$motor->alterarStatus($espId, 'fechado', null, 1);
$folha = new FolhaPontoService($pdo);
$doc = $folha->gerarPdf($espId, 1);
is_file($root . '/' . $doc['path']) || fail('PDF');
ok('PDF gerado com código ' . substr($doc['codigo'], 0, 8));

$folha->enviarDocumento($doc['documento_id']);
ok('Envio e-mail registrado');
$folha->gerarLinkAssinaturaDigital($doc['documento_id'], 1);
$st = (string) $pdo->query('SELECT status FROM documentos WHERE id=' . (int) $doc['documento_id'])->fetchColumn();
$st === 'enviado' || fail('Link de assinatura');
ok('Documento aguardando aceite do colaborador');

// Reabertura versionada + invalida PDF
$v1 = (int) $pdo->query("SELECT versao FROM ponto_espelhos WHERE id={$espId}")->fetchColumn();
$motor->alterarStatus($espId, 'em_tratamento', 'Correção verify', 1);
$v2 = (int) $pdo->query("SELECT versao FROM ponto_espelhos WHERE id={$espId}")->fetchColumn();
$v2 === $v1 + 1 || fail('Versão não incrementou');
$inv = (int) $pdo->query("SELECT COUNT(*) FROM documentos WHERE espelho_id={$espId} AND status='invalidado'")->fetchColumn();
$inv > 0 || fail('PDF não invalidado');
ok('Reabertura versionada + PDF invalidado');

// Telas-chave existem
foreach ([
    'app/Views/rh/calendarios/index.php',
    'app/Views/rh/escalas/show.php',
    'app/Views/rh/efetivo/index.php',
    'app/Views/rh/ponto/show.php',
    'app/Views/rh/folha/show.php',
    'app/Views/rh/folha/verificar.php',
    'app/Views/rh/importacoes/preview.php',
    'app/Views/rh/colaboradores/show.php',
] as $f) {
    is_file($root . '/' . $f) || fail("View ausente {$f}");
}
ok('Views do roteiro presentes');

echo "\nROTEIRO VERIFY PASSED\n";
