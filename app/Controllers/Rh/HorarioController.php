<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Db;
use App\Helpers\View;
use App\Services\HorarioService;
use PDO;
use Throwable;

final class HorarioController
{
    public function index(): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /');
            exit;
        }
        $svc = new HorarioService($pdo);
        $svc->ensureSchema();
        $migrados = 0;
        try {
            $migrados = $svc->migrarLote();
        } catch (Throwable) {
        }

        View::render('rh/horarios/index', [
            'pageTitle' => 'Horários',
            'pageSubtitle' => 'Modelo RHiD: semanal ou cíclico, com batidas por dia',
            'activeMenu' => 'horarios',
            'horarios' => $svc->listarAtivos(),
            'flash' => $_GET['ok'] ?? ($migrados > 0 ? 'migrado' : null),
            'migrados' => $migrados,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function show(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $horarioId = (int) $id;
        if (!$pdo instanceof PDO || $horarioId <= 0) {
            header('Location: /rh/horarios');
            exit;
        }
        $svc = new HorarioService($pdo);
        $svc->ensureSchema();
        $horario = $svc->buscar($horarioId);
        if ($horario === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Horário', 'activeMenu' => 'horarios']);
            return;
        }
        $dias = $svc->listarDias($horarioId);
        if ($dias === [] && (string) $horario['tipo'] === 'semanal') {
            $dias = $this->diasSemanalVazio();
        }

        View::render('rh/horarios/show', [
            'pageTitle' => (string) $horario['nome'],
            'pageSubtitle' => $horario['codigo'] . ' · ' . $horario['tipo'],
            'activeMenu' => 'horarios',
            'horario' => $horario,
            'dias' => $dias,
            'flash' => $_GET['ok'] ?? null,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function novo(): void
    {
        View::render('rh/horarios/show', [
            'pageTitle' => 'Novo horário',
            'pageSubtitle' => 'Semanal (seg–dom) ou cíclico (12×36, 5×1…)',
            'activeMenu' => 'horarios',
            'horario' => [
                'id' => 0,
                'codigo' => '',
                'nome' => '',
                'tipo' => 'semanal',
                'dias_ciclo' => 7,
                'trabalha_feriado' => 0,
                'adicional_noturno' => 0,
                'almoco_livre' => 0,
                'compensado' => 0,
                'neutro' => 0,
                'percentual_he' => '',
                'status' => 'ativo',
            ],
            'dias' => $this->diasSemanalVazio(),
            'flash' => null,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function save(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/horarios');
            exit;
        }
        $horarioId = (int) $id;
        $svc = new HorarioService($pdo);
        try {
            $cab = [
                'codigo' => $_POST['codigo'] ?? '',
                'nome' => $_POST['nome'] ?? '',
                'tipo' => $_POST['tipo'] ?? 'semanal',
                'dias_ciclo' => $_POST['dias_ciclo'] ?? null,
                'trabalha_feriado' => !empty($_POST['trabalha_feriado']),
                'adicional_noturno' => !empty($_POST['adicional_noturno']),
                'almoco_livre' => !empty($_POST['almoco_livre']),
                'compensado' => !empty($_POST['compensado']),
                'neutro' => !empty($_POST['neutro']),
                'percentual_he' => $_POST['percentual_he'] ?? '',
                'status' => $_POST['status'] ?? 'ativo',
            ];
            $diasIn = $_POST['dias'] ?? [];
            $dias = [];
            if (is_array($diasIn)) {
                foreach ($diasIn as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $dias[] = [
                        'indice' => (int) ($row['indice'] ?? 0),
                        'tipo_dia' => (string) ($row['tipo_dia'] ?? 'folga'),
                        'ent1' => $row['ent1'] ?? null,
                        'sai1' => $row['sai1'] ?? null,
                        'ent2' => $row['ent2'] ?? null,
                        'sai2' => $row['sai2'] ?? null,
                    ];
                }
            }
            $novoId = $svc->salvar($horarioId, $cab, $dias);
            header('Location: /rh/horarios/' . $novoId . '?ok=1');
            exit;
        } catch (Throwable $e) {
            $dest = $horarioId > 0 ? ('/rh/horarios/' . $horarioId) : '/rh/horarios/novo';
            header('Location: ' . $dest . '?erro=' . rawurlencode($e->getMessage()));
            exit;
        }
    }

    /** @return list<array<string,mixed>> */
    private function diasSemanalVazio(): array
    {
        $labels = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
        $out = [];
        foreach ($labels as $i => $lab) {
            $out[] = [
                'indice' => $i,
                'label' => $lab,
                'tipo_dia' => $i <= 5 ? 'trabalho' : 'folga',
                'ent1' => $i <= 5 ? '07:00' : null,
                'sai1' => $i <= 5 ? '12:00' : null,
                'ent2' => $i <= 5 ? '13:00' : null,
                'sai2' => $i <= 5 ? ($i === 5 ? '16:00' : '17:00') : null,
            ];
        }
        return $out;
    }
}
