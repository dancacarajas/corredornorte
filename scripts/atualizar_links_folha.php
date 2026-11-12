<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\Database;
use App\Services\FolhaPontoService;

$pdo = Database::connection();
$folhas = new FolhaPontoService($pdo);
$consulta = $pdo->prepare(
    "SELECT id FROM documentos
     WHERE tipo = 'folha_ponto' AND status = 'enviado'
       AND portal_token IS NOT NULL AND portal_token <> '' AND layout_pdf < ?
     ORDER BY id"
);
$consulta->execute([FolhaPontoService::LAYOUT_PDF_ATUAL]);
$pendentes = $consulta->fetchAll(PDO::FETCH_COLUMN);
$atualizados = 0;
$falhas = 0;
foreach ($pendentes as $id) {
    try {
        $novoId = $folhas->atualizarModeloPendente((int) $id);
        if ($novoId !== (int) $id) {
            $atualizados++;
        }
    } catch (Throwable $erro) {
        $falhas++;
        fwrite(STDERR, sprintf("Via %d: %s\n", $id, $erro->getMessage()));
    }
}
printf("Links pendentes atualizados: %d. Falhas: %d.\n", $atualizados, $falhas);
exit($falhas === 0 ? 0 : 1);
