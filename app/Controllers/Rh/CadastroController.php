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

final class CadastroController
{
    /** @var array<string,array{table:string,title:string,menu:string,subtitle:string,columns:list<string>,fields:array<string,string>}> */
    private array $entities = [
        'empresas' => [
            'table' => 'rh_empresas',
            'title' => 'Empresas',
            'menu' => 'empresas',
            'subtitle' => 'Cadastro raiz da hierarquia organizacional',
            'columns' => ['nome', 'cnpj', 'status'],
            'fields' => ['nome' => 'text', 'cnpj' => 'text', 'status' => 'status'],
        ],
        'contratos' => [
            'table' => 'rh_contratos',
            'title' => 'Contratos',
            'menu' => 'contratos',
            'subtitle' => 'Contrato → Centro de Custo → OS',
            'columns' => ['codigo', 'nome', 'status'],
            'fields' => ['empresa_id' => 'empresa', 'codigo' => 'text', 'nome' => 'text', 'inicio' => 'date', 'fim' => 'date', 'status' => 'status3'],
        ],
        'centros-custo' => [
            'table' => 'rh_centros_custo',
            'title' => 'Centros de Custo',
            'menu' => 'centros',
            'subtitle' => 'Vinculados a um contrato',
            'columns' => ['codigo', 'nome', 'status'],
            'fields' => ['contrato_id' => 'contrato', 'codigo' => 'text', 'nome' => 'text', 'status' => 'status'],
        ],
        'ordens-servico' => [
            'table' => 'rh_ordens_servico',
            'title' => 'Ordens de Serviço',
            'menu' => 'os',
            'subtitle' => 'Vinculadas a um centro de custo',
            'columns' => ['codigo', 'nome', 'status'],
            'fields' => ['centro_custo_id' => 'centro_custo', 'codigo' => 'text', 'nome' => 'text', 'status' => 'status'],
        ],
        'localidades' => [
            'table' => 'rh_localidades',
            'title' => 'Localidades',
            'menu' => 'localidades',
            'subtitle' => 'Base para calendários municipais/estaduais',
            'columns' => ['nome', 'municipio', 'uf', 'status'],
            'fields' => ['nome' => 'text', 'municipio' => 'text', 'uf' => 'text', 'status' => 'status'],
        ],
        'lotacoes' => [
            'table' => 'rh_lotacoes',
            'title' => 'Lotações',
            'menu' => 'lotacoes',
            'subtitle' => 'Pacote operacional: CC + OS + Localidade + Horário + % HE',
            'columns' => ['codigo', 'nome', 'percentual_he', 'status'],
            'fields' => [
                'codigo' => 'text', 'nome' => 'text',
                'contrato_id' => 'contrato', 'centro_custo_id' => 'centro_custo', 'ordem_servico_id' => 'ordem_servico',
                'localidade_id' => 'localidade', 'horario_id' => 'horario', 'calendario_id' => 'calendario',
                'percentual_he' => 'percentual_he',
                'status' => 'status',
            ],
        ],
        'cargos' => [
            'table' => 'rh_cargos',
            'title' => 'Cargos',
            'menu' => 'cargos',
            'subtitle' => 'Catálogo de cargos do efetivo',
            'columns' => ['codigo', 'nome', 'status'],
            'fields' => ['codigo' => 'text', 'nome' => 'text', 'status' => 'status'],
        ],
        'funcoes' => [
            'table' => 'rh_funcoes',
            'title' => 'Funções',
            'menu' => 'funcoes',
            'subtitle' => 'Catálogo de funções do efetivo',
            'columns' => ['codigo', 'nome', 'status'],
            'fields' => ['codigo' => 'text', 'nome' => 'text', 'status' => 'status'],
        ],
        'gestores-arcadis' => [
            'table' => 'rh_gestores_arcadis',
            'title' => 'Gestores Arcadis',
            'menu' => 'gestores-arcadis',
            'subtitle' => 'Cadastro de gestores internos Arcadis',
            'columns' => ['nome', 'matricula', 'email', 'status'],
            'fields' => [
                'nome' => 'text',
                'matricula' => 'text',
                'email' => 'text',
                'telefone' => 'text',
                'status' => 'status',
            ],
        ],
        'gestores-cliente' => [
            'table' => 'rh_gestores_cliente',
            'title' => 'Gestores Cliente',
            'menu' => 'gestores-cliente',
            'subtitle' => 'Cadastro de gestores do cliente (ex.: Vale)',
            'columns' => ['nome', 'cliente', 'email', 'status'],
            'fields' => [
                'nome' => 'text',
                'cliente' => 'text',
                'email' => 'text',
                'telefone' => 'text',
                'status' => 'status',
            ],
        ],
        'supervisores' => [
            'table' => 'rh_supervisores',
            'title' => 'Supervisores',
            'menu' => 'supervisores',
            'subtitle' => 'Supervisão operacional de equipe',
            'columns' => ['nome', 'matricula', 'email', 'status'],
            'fields' => [
                'nome' => 'text',
                'matricula' => 'text',
                'email' => 'text',
                'telefone' => 'text',
                'status' => 'status',
            ],
        ],
    ];

    public function empresas(): void { $this->list('empresas'); }
    public function contratos(): void { $this->list('contratos'); }
    public function centrosCusto(): void { $this->list('centros-custo'); }
    public function ordensServico(): void { $this->list('ordens-servico'); }
    public function localidades(): void { $this->list('localidades'); }
    public function lotacoes(): void { $this->list('lotacoes'); }
    public function cargos(): void { $this->list('cargos'); }
    public function funcoes(): void { $this->list('funcoes'); }
    public function gestoresArcadis(): void { $this->list('gestores-arcadis'); }
    public function gestoresCliente(): void { $this->list('gestores-cliente'); }
    public function supervisores(): void { $this->list('supervisores'); }

    public function createForm(string $entity = ''): void
    {
        $mapaCad = \App\Services\PerfilService::mapaCadastrosMenu()[$entity] ?? null;
        if ($mapaCad !== null) {
            Auth::requirePermission($mapaCad['criar']);
        } else {
            Auth::requirePermission('cadastros.editar');
        }
        $meta = $this->entities[$entity] ?? null;
        if ($meta === null) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Cadastro', 'activeMenu' => '']);
            return;
        }
        $pdo = Db::tryConnection();
        View::render('rh/cadastros/form', [
            'pageTitle' => 'Nova · ' . $meta['title'],
            'pageSubtitle' => $meta['subtitle'],
            'activeMenu' => $meta['menu'],
            'entity' => $entity,
            'meta' => $meta,
            'row' => [],
            'lookups' => $this->lookups($pdo),
            'dbOk' => $pdo instanceof PDO,
            'errors' => [],
            'action' => '/rh/' . $entity,
        ]);
    }

    public function store(string $entity = ''): void
    {
        $this->save($entity, null);
    }

    public function editForm(string $entity = '', string $id = '0'): void
    {
        $mapaCad = \App\Services\PerfilService::mapaCadastrosMenu()[$entity] ?? null;
        if ($mapaCad !== null) {
            Auth::requirePermission($mapaCad['editar']);
        } else {
            Auth::requirePermission('cadastros.editar');
        }
        $meta = $this->entities[$entity] ?? null;
        $pdo = Db::tryConnection();
        if ($meta === null || !$pdo instanceof PDO) {
            header('Location: /rh/efetivo');
            exit;
        }
        $stmt = $pdo->prepare('SELECT * FROM ' . $meta['table'] . ' WHERE id = :id');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            http_response_code(404);
            View::render('ui/not-found', ['pageTitle' => 'Registro', 'activeMenu' => $meta['menu']]);
            return;
        }
        View::render('rh/cadastros/form', [
            'pageTitle' => 'Editar · ' . $meta['title'],
            'pageSubtitle' => $meta['subtitle'],
            'activeMenu' => $meta['menu'],
            'entity' => $entity,
            'meta' => $meta,
            'row' => $row,
            'lookups' => $this->lookups($pdo),
            'dbOk' => true,
            'errors' => [],
            'action' => '/rh/' . $entity . '/' . (int) $id,
        ]);
    }

    public function update(string $entity = '', string $id = '0'): void
    {
        $this->save($entity, (int) $id);
    }

    public function destroy(string $entity = '', string $id = '0'): void
    {
        Csrf::requireValid();
        $meta = $this->entities[$entity] ?? null;
        $pdo = Db::tryConnection();
        $mapaCad = \App\Services\PerfilService::mapaCadastrosMenu()[$entity] ?? null;
        if ($mapaCad !== null) {
            Auth::requirePermission($mapaCad['excluir']);
        } else {
            Auth::requirePermission('cadastros.editar');
        }

        if ($meta === null || !$pdo instanceof PDO) {
            header('Location: /rh/efetivo?erro=' . rawurlencode('Cadastro indisponível'));
            exit;
        }

        $rowId = (int) $id;
        try {
            if ($rowId <= 0) {
                throw new RuntimeException('Registro inválido.');
            }
            $st = $pdo->prepare('SELECT * FROM ' . $meta['table'] . ' WHERE id = :id LIMIT 1');
            $st->execute(['id' => $rowId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('Registro não encontrado.');
            }

            // Soft delete quando há status; senão tenta hard delete.
            if (array_key_exists('status', $row)) {
                $pdo->prepare(
                    'UPDATE ' . $meta['table'] . " SET status = 'inativo' WHERE id = :id"
                )->execute(['id' => $rowId]);
            } else {
                try {
                    $pdo->prepare('DELETE FROM ' . $meta['table'] . ' WHERE id = :id')->execute(['id' => $rowId]);
                } catch (Throwable $e) {
                    throw new RuntimeException('Não foi possível excluir: registro em uso por outros módulos.');
                }
            }

            header('Location: /rh/' . $entity . '?ok=excluido');
        } catch (Throwable $e) {
            header('Location: /rh/' . $entity . '?erro=' . rawurlencode($e->getMessage()));
        }
        exit;
    }

    private function list(string $entity): void
    {
        $meta = $this->entities[$entity];
        $pdo = Db::tryConnection();
        $rows = [];
        if ($pdo instanceof PDO) {
            $rows = $pdo->query('SELECT * FROM ' . $meta['table'] . ' ORDER BY id DESC LIMIT 200')->fetchAll();
        }

        $mapaCad = \App\Services\PerfilService::mapaCadastrosMenu()[$entity] ?? null;
        $podeCriar = $mapaCad
            ? Auth::canAny($mapaCad['criar'], $mapaCad['editar'], 'cadastros.editar')
            : Auth::can('cadastros.editar');
        $podeExcluir = $mapaCad
            ? Auth::canAny($mapaCad['excluir'], 'cadastros.editar')
            : Auth::can('cadastros.editar');
        $topbarActions = $podeCriar
            ? '<a class="btn btn-primary btn-sm" href="/rh/' . htmlspecialchars($entity, ENT_QUOTES) . '/novo">Novo</a>'
            : '';

        View::render('rh/cadastros/list', [
            'pageTitle' => $meta['title'],
            'pageSubtitle' => $meta['subtitle'],
            'activeMenu' => $meta['menu'],
            'topbarActions' => $topbarActions,
            'dbOk' => $pdo instanceof PDO,
            'columns' => $meta['columns'],
            'rows' => $rows,
            'entityLabel' => $meta['title'],
            'entity' => $entity,
            'podeExcluir' => $podeExcluir,
            'flashOk' => $_GET['ok'] ?? null,
            'flashErro' => $_GET['erro'] ?? null,
        ]);
    }

    private function save(string $entity, ?int $id): void
    {
        $mapaCad = \App\Services\PerfilService::mapaCadastrosMenu()[$entity] ?? null;
        if ($mapaCad !== null) {
            Auth::requirePermission($id === null ? $mapaCad['criar'] : $mapaCad['editar']);
        } else {
            Auth::requirePermission('cadastros.editar');
        }
        $meta = $this->entities[$entity] ?? null;
        $pdo = Db::tryConnection();
        if ($meta === null || !$pdo instanceof PDO) {
            header('Location: /rh/efetivo');
            exit;
        }

        $data = [];
        try {
            foreach ($meta['fields'] as $field => $type) {
                if ($type === 'bool') {
                    $data[$field] = isset($_POST[$field]) ? 1 : 0;
                    continue;
                }
                $val = trim((string) ($_POST[$field] ?? ''));
                if ($type === 'hours') {
                    $data[$field] = $val === '' ? null : $this->parseHoursToMinutes($val);
                    continue;
                }
                if ($type === 'percentual_he') {
                    $pct = (int) $val;
                    if ($pct < 1 || $pct > 200) {
                        throw new \RuntimeException('Informe o percentual de HE entre 1 e 200.');
                    }
                    $data[$field] = $pct;
                    continue;
                }
                $data[$field] = $val === '' ? null : $val;
            }

            if ($entity === 'lotacoes') {
                (new \App\Services\HorarioService($pdo))->ensureSchema();
                if (empty($data['horario_id'])) {
                    throw new RuntimeException('Selecione o horário da lotação.');
                }
                $data['jornada_id'] = null;
                $data['escala_id'] = null;
            }

            if ($id === null) {
                $cols = array_keys($data);
                $placeholders = array_map(static fn($c) => ':' . $c, $cols);
                $sql = 'INSERT INTO ' . $meta['table'] . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
                $pdo->prepare($sql)->execute($data);
            } else {
                $sets = [];
                foreach (array_keys($data) as $col) {
                    $sets[] = "{$col} = :{$col}";
                }
                $data['id'] = $id;
                $sql = 'UPDATE ' . $meta['table'] . ' SET ' . implode(', ', $sets) . ' WHERE id = :id';
                $pdo->prepare($sql)->execute($data);
            }
            header('Location: /rh/' . $entity);
            exit;
        } catch (Throwable $e) {
            View::render('rh/cadastros/form', [
                'pageTitle' => ($id ? 'Editar' : 'Nova') . ' · ' . $meta['title'],
                'pageSubtitle' => $e->getMessage(),
                'activeMenu' => $meta['menu'],
                'entity' => $entity,
                'meta' => $meta,
                'row' => $data,
                'lookups' => $this->lookups($pdo),
                'dbOk' => true,
                'errors' => ['geral' => $e->getMessage()],
                'action' => $id ? '/rh/' . $entity . '/' . $id : '/rh/' . $entity,
            ]);
        }
    }

    /** Aceita HH:MM ou horas decimais (ex.: 12 ou 12.5) e grava minutos. */
    private function parseHoursToMinutes(string $val): int
    {
        $val = trim(str_replace(',', '.', $val));
        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $val, $m) === 1) {
            return ((int) $m[1]) * 60 + (int) $m[2];
        }
        if (is_numeric($val)) {
            return (int) round(((float) $val) * 60);
        }

        return 0;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function lookups(?PDO $pdo): array
    {
        if (!$pdo instanceof PDO) {
            return [];
        }
        return [
            'empresa' => $pdo->query("SELECT id, nome AS label FROM rh_empresas WHERE status='ativo' ORDER BY nome")->fetchAll(),
            'contrato' => $pdo->query("SELECT id, CONCAT(codigo,' — ',nome) AS label FROM rh_contratos WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'centro_custo' => $pdo->query("SELECT id, contrato_id, CONCAT(codigo,' — ',nome) AS label FROM rh_centros_custo WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'ordem_servico' => $pdo->query("SELECT os.id, os.centro_custo_id, cc.contrato_id, CONCAT(os.codigo,' — ',os.nome) AS label FROM rh_ordens_servico os LEFT JOIN rh_centros_custo cc ON cc.id = os.centro_custo_id WHERE os.status='ativo' ORDER BY os.codigo")->fetchAll(),
            'localidade' => $pdo->query("SELECT id, CONCAT(municipio,'/',uf,' — ',nome) AS label FROM rh_localidades WHERE status='ativo' ORDER BY municipio")->fetchAll(),
            'horario' => $this->lookupHorarios($pdo),
            'calendario' => $pdo->query("SELECT id, CONCAT(codigo,' — ',nome) AS label FROM rh_calendarios WHERE status='ativo' ORDER BY codigo")->fetchAll(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function lookupHorarios(PDO $pdo): array
    {
        try {
            (new \App\Services\HorarioService($pdo))->ensureSchema();
            return $pdo->query(
                "SELECT id, CONCAT(codigo,' — ',nome,' (',tipo,')') AS label
                 FROM rh_horarios WHERE status='ativo' ORDER BY codigo"
            )->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
