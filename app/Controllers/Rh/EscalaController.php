<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Db;
use App\Helpers\View;
use PDO;
use Throwable;

final class EscalaController
{
    public function show(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/escalas');
            exit;
        }
        $stmt = $pdo->prepare('SELECT * FROM rh_escalas WHERE id = :id');
        $stmt->execute(['id' => (int) $id]);
        $escala = $stmt->fetch();
        if ($escala === false) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Escala', 'activeMenu' => 'escalas']);
            return;
        }
        $ciclos = $pdo->prepare('SELECT * FROM rh_escala_ciclos WHERE escala_id = :id ORDER BY ordem, dia_ciclo');
        $ciclos->execute(['id' => (int) $id]);

        View::render('rh/escalas/show', [
            'pageTitle' => (string) $escala['nome'],
            'pageSubtitle' => $escala['codigo'] . ' · ' . $escala['tipo'],
            'activeMenu' => 'escalas',
            'escala' => $escala,
            'ciclos' => $ciclos->fetchAll(),
            'flash' => $_GET['ok'] ?? null,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function saveCiclo(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $escalaId = (int) $id;
        if (!$pdo instanceof PDO || $escalaId <= 0) {
            header('Location: /rh/escalas');
            exit;
        }

        $dias = $_POST['dia_ciclo'] ?? [];
        $tipos = $_POST['tipo_dia'] ?? [];
        if (!is_array($dias) || !is_array($tipos)) {
            header('Location: /rh/escalas/' . $escalaId . '?erro=dados');
            exit;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM rh_escala_ciclos WHERE escala_id = :id')->execute(['id' => $escalaId]);
            $ins = $pdo->prepare(
                'INSERT INTO rh_escala_ciclos (escala_id, dia_ciclo, tipo_dia, ordem) VALUES (:e,:d,:t,:o)'
            );
            $ordem = 1;
            foreach ($dias as $i => $dia) {
                $diaNum = (int) $dia;
                if ($diaNum <= 0) {
                    continue;
                }
                $tipo = (string) ($tipos[$i] ?? 'trabalho');
                if (!in_array($tipo, ['trabalho', 'folga'], true)) {
                    $tipo = 'trabalho';
                }
                $ins->execute(['e' => $escalaId, 'd' => $diaNum, 't' => $tipo, 'o' => $ordem++]);
            }
            $pdo->prepare("UPDATE rh_escalas SET tipo = 'ciclo' WHERE id = :id AND tipo <> 'ciclo'")
                ->execute(['id' => $escalaId]);
            $pdo->commit();
            header('Location: /rh/escalas/' . $escalaId . '?ok=1');
        } catch (Throwable $e) {
            $pdo->rollBack();
            header('Location: /rh/escalas/' . $escalaId . '?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }
}
