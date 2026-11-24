<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Db;
use App\Helpers\View;
use PDO;
use RuntimeException;
use Throwable;

final class CalendarioController
{
    public function index(): void
    {
        Auth::requirePermission('calendarios.ver');
        $pdo = Db::tryConnection();
        $nacional = null;
        $cidades = [];
        if ($pdo instanceof PDO) {
            $nacional = $pdo->query(
                "SELECT c.*,
                        (SELECT COUNT(*) FROM rh_feriados f WHERE f.calendario_id = c.id) AS total_feriados
                 FROM rh_calendarios c
                 WHERE c.escopo = 'nacional'
                 ORDER BY c.id
                 LIMIT 1"
            )->fetch() ?: null;

            $cidades = $pdo->query(
                "SELECT l.id, l.nome, l.municipio, l.uf, l.status,
                        (SELECT COUNT(*) FROM rh_feriados f
                          INNER JOIN rh_calendarios c ON c.id = f.calendario_id
                          WHERE c.escopo = 'estadual' AND c.uf = l.uf AND c.status = 'ativo') AS feriados_estaduais,
                        (SELECT COUNT(*) FROM rh_feriados f
                          INNER JOIN rh_calendarios c ON c.id = f.calendario_id
                          WHERE c.escopo = 'municipal' AND c.localidade_id = l.id AND c.status = 'ativo') AS feriados_municipais
                 FROM rh_localidades l
                 ORDER BY l.uf, l.municipio, l.nome"
            )->fetchAll();
        }

        $podeCriar = Auth::canAny('calendarios.criar', 'calendarios.editar', 'cadastros.editar');
        $topbar = $podeCriar
            ? '<a class="btn btn-secondary btn-sm" href="/rh/calendarios/novo">Novo calendário</a>'
            : '';

        View::render('rh/calendarios/index', [
            'pageTitle' => 'Feriados',
            'pageSubtitle' => 'Nacionais + estaduais + municipais por cidade',
            'activeMenu' => 'feriados',
            'dbOk' => $pdo instanceof PDO,
            'nacional' => $nacional,
            'cidades' => $cidades,
            'podeCriar' => $podeCriar,
            'podeEditar' => Auth::canAny('calendarios.editar', 'cadastros.editar'),
            'podeExcluir' => Auth::canAny('calendarios.excluir', 'cadastros.editar'),
            'topbarActions' => $topbar,
            'flashOk' => $_GET['ok'] ?? null,
            'flashErro' => $_GET['erro'] ?? null,
        ]);
    }

    public function destroy(string $id = '0'): void
    {
        Auth::requirePermission('calendarios.excluir');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $calId = (int) $id;
        try {
            if (!$pdo instanceof PDO || $calId <= 0) {
                throw new RuntimeException('Calendário inválido.');
            }
            $st = $pdo->prepare('SELECT id, escopo, status FROM rh_calendarios WHERE id = :id LIMIT 1');
            $st->execute(['id' => $calId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('Calendário não encontrado.');
            }
            if ((string) ($row['escopo'] ?? '') === 'nacional') {
                throw new RuntimeException('O calendário nacional não pode ser excluído.');
            }
            $pdo->prepare("UPDATE rh_calendarios SET status = 'inativo' WHERE id = :id")->execute(['id' => $calId]);
            header('Location: /rh/calendarios?ok=excluido');
        } catch (Throwable $e) {
            header('Location: /rh/calendarios?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function porCidade(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        $localidadeId = (int) $id;
        if (!$pdo instanceof PDO || $localidadeId <= 0) {
            header('Location: /rh/calendarios');
            exit;
        }

        $localidade = $this->findLocalidade($pdo, $localidadeId);
        if ($localidade === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Localidade', 'activeMenu' => 'feriados']);
            return;
        }

        $packs = $this->ensureCalendariosCidade($pdo, $localidade);

        View::render('rh/calendarios/cidade', [
            'pageTitle' => 'Feriados · ' . $localidade['municipio'] . '/' . $localidade['uf'],
            'pageSubtitle' => (string) $localidade['nome'] . ' — nacional + estadual + municipal',
            'activeMenu' => 'feriados',
            'localidade' => $localidade,
            'nacional' => $packs['nacional'],
            'estadual' => $packs['estadual'],
            'municipal' => $packs['municipal'],
            'feriadosNacional' => $this->feriadosDoCalendario($pdo, (int) $packs['nacional']['id']),
            'feriadosEstadual' => $this->feriadosDoCalendario($pdo, (int) $packs['estadual']['id']),
            'feriadosMunicipal' => $this->feriadosDoCalendario($pdo, (int) $packs['municipal']['id']),
            'flash' => $_GET['ok'] ?? null,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function addFeriadoCidade(string $id = '0'): void
    {
        Auth::requirePermission('calendarios.criar');
        $pdo = Db::tryConnection();
        $localidadeId = (int) $id;
        if (!$pdo instanceof PDO || $localidadeId <= 0) {
            header('Location: /rh/calendarios');
            exit;
        }

        $localidade = $this->findLocalidade($pdo, $localidadeId);
        if ($localidade === null) {
            header('Location: /rh/calendarios');
            exit;
        }

        $escopo = (string) ($_POST['escopo'] ?? 'municipal');
        if (!in_array($escopo, ['nacional', 'estadual', 'municipal'], true)) {
            $escopo = 'municipal';
        }

        try {
            $packs = $this->ensureCalendariosCidade($pdo, $localidade);
            $calId = (int) $packs[$escopo]['id'];
            $pdo->prepare(
                'INSERT INTO rh_feriados (calendario_id, data, nome, tipo, recorrente_ano)
                 VALUES (:c, :data, :nome, :tipo, :rec)'
            )->execute([
                'c' => $calId,
                'data' => (string) ($_POST['data'] ?? ''),
                'nome' => trim((string) ($_POST['nome'] ?? '')),
                'tipo' => (string) ($_POST['tipo'] ?? 'feriado'),
                'rec' => isset($_POST['recorrente_ano']) ? 1 : 0,
            ]);
            header('Location: /rh/localidades/' . $localidadeId . '/feriados?ok=feriado');
        } catch (Throwable $e) {
            header('Location: /rh/localidades/' . $localidadeId . '/feriados?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function deleteFeriadoCidade(string $id = '0', string $feriadoId = '0'): void
    {
        Auth::requirePermission('calendarios.excluir');
        $pdo = Db::tryConnection();
        $localidadeId = (int) $id;
        if ($pdo instanceof PDO && $localidadeId > 0) {
            $localidade = $this->findLocalidade($pdo, $localidadeId);
            if ($localidade !== null) {
                $packs = $this->ensureCalendariosCidade($pdo, $localidade);
                $ids = [
                    (int) $packs['nacional']['id'],
                    (int) $packs['estadual']['id'],
                    (int) $packs['municipal']['id'],
                ];
                $in = implode(',', $ids);
                $pdo->prepare(
                    "DELETE FROM rh_feriados WHERE id = :id AND calendario_id IN ($in)"
                )->execute(['id' => (int) $feriadoId]);
            }
        }
        header('Location: /rh/localidades/' . $localidadeId . '/feriados?ok=removido');
        exit;
    }

    public function create(): void
    {
        Auth::requirePermission('calendarios.criar');
        $pdo = Db::tryConnection();
        View::render('rh/calendarios/form', [
            'pageTitle' => 'Novo calendário',
            'pageSubtitle' => 'Escopo de feriados',
            'activeMenu' => 'feriados',
            'dbOk' => $pdo instanceof PDO,
            'row' => [],
            'lookups' => $this->lookups($pdo),
            'action' => '/rh/calendarios',
            'errors' => [],
        ]);
    }

    public function store(): void
    {
        Auth::requirePermission('calendarios.criar');
        $this->save(null);
    }

    public function edit(string $id = '0'): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/calendarios');
            exit;
        }
        $stmt = $pdo->prepare(
            'SELECT c.*,
                    l.nome AS localidade_nome, l.municipio, l.uf AS localidade_uf
             FROM rh_calendarios c
             LEFT JOIN rh_localidades l ON l.id = c.localidade_id
             WHERE c.id = :id'
        );
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Calendário', 'activeMenu' => 'feriados']);
            return;
        }

        // Municipal/estadual: redireciona para a visão por cidade
        if ($row['escopo'] === 'municipal' && !empty($row['localidade_id'])) {
            header('Location: /rh/localidades/' . (int) $row['localidade_id'] . '/feriados');
            exit;
        }
        if ($row['escopo'] === 'estadual' && !empty($row['uf'])) {
            $loc = $pdo->prepare(
                "SELECT id FROM rh_localidades WHERE uf = :uf AND status = 'ativo' ORDER BY id LIMIT 1"
            );
            $loc->execute(['uf' => $row['uf']]);
            $locId = (int) ($loc->fetchColumn() ?: 0);
            if ($locId > 0) {
                header('Location: /rh/localidades/' . $locId . '/feriados');
                exit;
            }
        }

        View::render('rh/calendarios/show', [
            'pageTitle' => (string) $row['nome'],
            'pageSubtitle' => strtoupper((string) $row['escopo']) . ' · ' . $row['codigo'],
            'activeMenu' => 'feriados',
            'calendario' => $row,
            'feriados' => $this->feriadosDoCalendario($pdo, (int) $id),
            'lookups' => $this->lookups($pdo),
            'flash' => $_GET['ok'] ?? null,
            'error' => $_GET['erro'] ?? null,
        ]);
    }

    public function update(string $id = '0'): void
    {
        Auth::requirePermission('calendarios.editar');
        $this->save((int) $id);
    }

    public function addFeriado(string $id = '0'): void
    {
        Auth::requirePermission('calendarios.criar');
        $pdo = Db::tryConnection();
        $calId = (int) $id;
        if (!$pdo instanceof PDO || $calId <= 0) {
            header('Location: /rh/calendarios');
            exit;
        }
        try {
            $pdo->prepare(
                'INSERT INTO rh_feriados (calendario_id, data, nome, tipo, recorrente_ano)
                 VALUES (:c, :data, :nome, :tipo, :rec)'
            )->execute([
                'c' => $calId,
                'data' => (string) ($_POST['data'] ?? ''),
                'nome' => trim((string) ($_POST['nome'] ?? '')),
                'tipo' => (string) ($_POST['tipo'] ?? 'feriado'),
                'rec' => isset($_POST['recorrente_ano']) ? 1 : 0,
            ]);
            header('Location: /rh/calendarios/' . $calId . '?ok=feriado');
        } catch (Throwable $e) {
            header('Location: /rh/calendarios/' . $calId . '?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    public function deleteFeriado(string $id = '0', string $feriadoId = '0'): void
    {
        Auth::requirePermission('calendarios.excluir');
        $pdo = Db::tryConnection();
        if ($pdo instanceof PDO) {
            $pdo->prepare('DELETE FROM rh_feriados WHERE id = :id AND calendario_id = :c')
                ->execute(['id' => (int) $feriadoId, 'c' => (int) $id]);
        }
        header('Location: /rh/calendarios/' . (int) $id . '?ok=removido');
        exit;
    }

    private function save(?int $id): void
    {
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            header('Location: /rh/calendarios');
            exit;
        }
        $data = [
            'codigo' => trim((string) ($_POST['codigo'] ?? '')),
            'nome' => trim((string) ($_POST['nome'] ?? '')),
            'escopo' => (string) ($_POST['escopo'] ?? 'nacional'),
            'uf' => trim((string) ($_POST['uf'] ?? '')) ?: null,
            'localidade_id' => ($_POST['localidade_id'] ?? '') !== '' ? (int) $_POST['localidade_id'] : null,
            'contrato_id' => ($_POST['contrato_id'] ?? '') !== '' ? (int) $_POST['contrato_id'] : null,
            'status' => (string) ($_POST['status'] ?? 'ativo'),
        ];

        if ($data['escopo'] === 'estadual' && ($data['uf'] === null || $data['uf'] === '')) {
            View::render('rh/calendarios/form', [
                'pageTitle' => $id ? 'Editar calendário' : 'Novo calendário',
                'pageSubtitle' => 'UF obrigatória para escopo estadual',
                'activeMenu' => 'feriados',
                'dbOk' => true,
                'row' => $data,
                'lookups' => $this->lookups($pdo),
                'action' => $id ? '/rh/calendarios/' . $id : '/rh/calendarios',
                'errors' => ['geral' => 'Informe a UF para calendário estadual.'],
            ]);
            return;
        }
        if ($data['escopo'] === 'municipal' && empty($data['localidade_id'])) {
            View::render('rh/calendarios/form', [
                'pageTitle' => $id ? 'Editar calendário' : 'Novo calendário',
                'pageSubtitle' => 'Cidade obrigatória para escopo municipal',
                'activeMenu' => 'feriados',
                'dbOk' => true,
                'row' => $data,
                'lookups' => $this->lookups($pdo),
                'action' => $id ? '/rh/calendarios/' . $id : '/rh/calendarios',
                'errors' => ['geral' => 'Selecione a localidade/cidade para calendário municipal.'],
            ]);
            return;
        }

        // Preenche UF a partir da localidade no municipal
        if ($data['escopo'] === 'municipal' && !empty($data['localidade_id'])) {
            $loc = $this->findLocalidade($pdo, (int) $data['localidade_id']);
            if ($loc !== null) {
                $data['uf'] = (string) $loc['uf'];
            }
        }

        try {
            if ($id === null) {
                $pdo->prepare(
                    'INSERT INTO rh_calendarios (codigo, nome, escopo, uf, localidade_id, contrato_id, status)
                     VALUES (:codigo,:nome,:escopo,:uf,:localidade_id,:contrato_id,:status)'
                )->execute($data);
                $newId = (int) $pdo->lastInsertId();
                if ($data['escopo'] === 'municipal' && !empty($data['localidade_id'])) {
                    header('Location: /rh/localidades/' . (int) $data['localidade_id'] . '/feriados');
                } else {
                    header('Location: /rh/calendarios/' . $newId);
                }
            } else {
                $data['id'] = $id;
                $pdo->prepare(
                    'UPDATE rh_calendarios SET codigo=:codigo, nome=:nome, escopo=:escopo, uf=:uf,
                     localidade_id=:localidade_id, contrato_id=:contrato_id, status=:status WHERE id=:id'
                )->execute($data);
                if ($data['escopo'] === 'municipal' && !empty($data['localidade_id'])) {
                    header('Location: /rh/localidades/' . (int) $data['localidade_id'] . '/feriados');
                } else {
                    header('Location: /rh/calendarios/' . $id);
                }
            }
            exit;
        } catch (Throwable $e) {
            View::render('rh/calendarios/form', [
                'pageTitle' => $id ? 'Editar calendário' : 'Novo calendário',
                'pageSubtitle' => $e->getMessage(),
                'activeMenu' => 'feriados',
                'dbOk' => true,
                'row' => $data,
                'lookups' => $this->lookups($pdo),
                'action' => $id ? '/rh/calendarios/' . $id : '/rh/calendarios',
                'errors' => ['geral' => $e->getMessage()],
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    private function findLocalidade(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM rh_localidades WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Garante calendários nacional, estadual (UF) e municipal (cidade).
     *
     * @param array<string,mixed> $localidade
     * @return array{nacional:array<string,mixed>,estadual:array<string,mixed>,municipal:array<string,mixed>}
     */
    private function ensureCalendariosCidade(PDO $pdo, array $localidade): array
    {
        $uf = strtoupper((string) $localidade['uf']);
        $localidadeId = (int) $localidade['id'];
        $municipio = (string) $localidade['municipio'];

        $nacional = $pdo->query(
            "SELECT * FROM rh_calendarios WHERE escopo = 'nacional' ORDER BY id LIMIT 1"
        )->fetch();
        if ($nacional === false) {
            $pdo->prepare(
                "INSERT INTO rh_calendarios (codigo, nome, escopo, status)
                 VALUES ('NACIONAL', 'Feriados Nacionais', 'nacional', 'ativo')"
            )->execute();
            $nacional = $pdo->query(
                "SELECT * FROM rh_calendarios WHERE escopo = 'nacional' ORDER BY id LIMIT 1"
            )->fetch();
        }

        $stmtEst = $pdo->prepare(
            "SELECT * FROM rh_calendarios WHERE escopo = 'estadual' AND uf = :uf ORDER BY id LIMIT 1"
        );
        $stmtEst->execute(['uf' => $uf]);
        $estadual = $stmtEst->fetch();
        if ($estadual === false) {
            $codigo = $uf . '-ESTADUAL';
            $pdo->prepare(
                "INSERT INTO rh_calendarios (codigo, nome, escopo, uf, status)
                 VALUES (:codigo, :nome, 'estadual', :uf, 'ativo')"
            )->execute([
                'codigo' => $codigo,
                'nome' => 'Feriados ' . $uf,
                'uf' => $uf,
            ]);
            $stmtEst->execute(['uf' => $uf]);
            $estadual = $stmtEst->fetch();
        }

        $stmtMun = $pdo->prepare(
            "SELECT * FROM rh_calendarios WHERE escopo = 'municipal' AND localidade_id = :lid ORDER BY id LIMIT 1"
        );
        $stmtMun->execute(['lid' => $localidadeId]);
        $municipal = $stmtMun->fetch();
        if ($municipal === false) {
            $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $municipio) ?: ('LOC' . $localidadeId));
            $codigo = substr($slug, 0, 28) . '-' . $localidadeId;
            $pdo->prepare(
                "INSERT INTO rh_calendarios (codigo, nome, escopo, uf, localidade_id, status)
                 VALUES (:codigo, :nome, 'municipal', :uf, :lid, 'ativo')"
            )->execute([
                'codigo' => $codigo,
                'nome' => 'Feriados ' . $municipio,
                'uf' => $uf,
                'lid' => $localidadeId,
            ]);
            $stmtMun->execute(['lid' => $localidadeId]);
            $municipal = $stmtMun->fetch();
        }

        return [
            'nacional' => $nacional,
            'estadual' => $estadual,
            'municipal' => $municipal,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function feriadosDoCalendario(PDO $pdo, int $calendarioId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM rh_feriados WHERE calendario_id = :id ORDER BY data, nome');
        $stmt->execute(['id' => $calendarioId]);

        return $stmt->fetchAll();
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function lookups(?PDO $pdo): array
    {
        if (!$pdo instanceof PDO) {
            return [];
        }

        return [
            'localidades' => $pdo->query(
                "SELECT id, CONCAT(municipio,'/',uf,' — ',nome) AS label FROM rh_localidades WHERE status='ativo' ORDER BY municipio"
            )->fetchAll(),
            'contratos' => $pdo->query(
                "SELECT id, CONCAT(codigo,' — ',nome) AS label FROM rh_contratos WHERE status='ativo' ORDER BY codigo"
            )->fetchAll(),
        ];
    }
}
