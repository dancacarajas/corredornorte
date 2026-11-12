<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Db;
use App\Helpers\View;
use PDO;
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
        'jornadas' => [
            'table' => 'rh_jornadas',
            'title' => 'Jornadas',
            'menu' => 'jornadas',
            'subtitle' => 'Horários contratuais reutilizáveis',
            'columns' => ['codigo', 'nome', 'entrada', 'saida', 'status'],
            'fields' => [
                'codigo' => 'text', 'nome' => 'text',
                'entrada' => 'time', 'intervalo_inicio' => 'time', 'intervalo_fim' => 'time', 'saida' => 'time',
                'carga_diaria_min' => 'hours', 'carga_semanal_min' => 'hours',
                'trabalha_sabado' => 'bool', 'trabalha_domingo' => 'bool', 'trabalha_feriado' => 'bool', 'adicional_noturno' => 'bool',
                'status' => 'status',
            ],
        ],
        'escalas' => [
            'table' => 'rh_escalas',
            'title' => 'Escalas',
            'menu' => 'escalas',
            'subtitle' => 'Inclui escalas por ciclo',
            'columns' => ['codigo', 'nome', 'tipo', 'status'],
            'fields' => ['codigo' => 'text', 'nome' => 'text', 'tipo' => 'escala_tipo', 'status' => 'status'],
        ],
        'lotacoes' => [
            'table' => 'rh_lotacoes',
            'title' => 'Lotações',
            'menu' => 'lotacoes',
            'subtitle' => 'Pacote operacional: CC + OS + Localidade + Jornada + Escala',
            'columns' => ['codigo', 'nome', 'status'],
            'fields' => [
                'codigo' => 'text', 'nome' => 'text',
                'contrato_id' => 'contrato', 'centro_custo_id' => 'centro_custo', 'ordem_servico_id' => 'ordem_servico',
                'localidade_id' => 'localidade', 'jornada_id' => 'jornada', 'escala_id' => 'escala', 'calendario_id' => 'calendario',
                'status' => 'status',
            ],
        ],
    ];

    public function empresas(): void { $this->list('empresas'); }
    public function contratos(): void { $this->list('contratos'); }
    public function centrosCusto(): void { $this->list('centros-custo'); }
    public function ordensServico(): void { $this->list('ordens-servico'); }
    public function localidades(): void { $this->list('localidades'); }
    public function jornadas(): void { $this->list('jornadas'); }
    public function escalas(): void { $this->list('escalas'); }
    public function lotacoes(): void { $this->list('lotacoes'); }

    public function createForm(string $entity = ''): void
    {
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

    private function list(string $entity): void
    {
        $meta = $this->entities[$entity];
        $pdo = Db::tryConnection();
        $rows = [];
        if ($pdo instanceof PDO) {
            $rows = $pdo->query('SELECT * FROM ' . $meta['table'] . ' ORDER BY id DESC LIMIT 200')->fetchAll();
        }

        $topbarActions = '<a class="btn btn-primary btn-sm" href="/rh/' . htmlspecialchars($entity, ENT_QUOTES) . '/novo">Novo</a>';

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
        ]);
    }

    private function save(string $entity, ?int $id): void
    {
        $meta = $this->entities[$entity] ?? null;
        $pdo = Db::tryConnection();
        if ($meta === null || !$pdo instanceof PDO) {
            header('Location: /rh/efetivo');
            exit;
        }

        $data = [];
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
            $data[$field] = $val === '' ? null : $val;
        }

        if ($entity === 'jornadas') {
            $cargas = $this->calcularCargasJornada($data);
            $data['carga_diaria_min'] = $cargas['diaria'];
            $data['carga_semanal_min'] = $cargas['semanal'];
        }

        try {
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

    /** @param array<string,mixed> $data */
    private function calcularCargasJornada(array $data): array
    {
        $toMin = static function (?string $v): ?int {
            if ($v === null || $v === '') {
                return null;
            }
            if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($v), $m) !== 1) {
                return null;
            }

            return ((int) $m[1]) * 60 + (int) $m[2];
        };

        $entrada = $toMin(isset($data['entrada']) ? (string) $data['entrada'] : null);
        $saida = $toMin(isset($data['saida']) ? (string) $data['saida'] : null);
        if ($entrada === null || $saida === null) {
            return [
                'diaria' => (int) ($data['carga_diaria_min'] ?? 0),
                'semanal' => (int) ($data['carga_semanal_min'] ?? 0),
            ];
        }

        if ($saida <= $entrada) {
            $saida += 24 * 60;
        }
        $diaria = $saida - $entrada;

        $ii = $toMin(isset($data['intervalo_inicio']) ? (string) $data['intervalo_inicio'] : null);
        $iff = $toMin(isset($data['intervalo_fim']) ? (string) $data['intervalo_fim'] : null);
        if ($ii !== null && $iff !== null && $iff > $ii) {
            $diaria -= ($iff - $ii);
        }
        $diaria = max(0, $diaria);

        $dias = 5.0;
        $sab = !empty($data['trabalha_sabado']);
        $dom = !empty($data['trabalha_domingo']);
        if ($sab) {
            $dias += 1;
        }
        if ($dom) {
            $dias += 1;
        }
        if ($sab && $dom) {
            $dias = 3.5; // média semanal de plantão (ex.: 12x36)
        }

        return [
            'diaria' => $diaria,
            'semanal' => (int) round($diaria * $dias),
        ];
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
            'centro_custo' => $pdo->query("SELECT id, CONCAT(codigo,' — ',nome) AS label FROM rh_centros_custo WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'ordem_servico' => $pdo->query("SELECT id, CONCAT(codigo,' — ',nome) AS label FROM rh_ordens_servico WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'localidade' => $pdo->query("SELECT id, CONCAT(municipio,'/',uf,' — ',nome) AS label FROM rh_localidades WHERE status='ativo' ORDER BY municipio")->fetchAll(),
            'jornada' => $pdo->query("SELECT id, CONCAT(codigo,' — ',nome) AS label FROM rh_jornadas WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'escala' => $pdo->query("SELECT id, CONCAT(codigo,' — ',nome) AS label FROM rh_escalas WHERE status='ativo' ORDER BY codigo")->fetchAll(),
            'calendario' => $pdo->query("SELECT id, CONCAT(codigo,' — ',nome) AS label FROM rh_calendarios WHERE status='ativo' ORDER BY codigo")->fetchAll(),
        ];
    }
}
