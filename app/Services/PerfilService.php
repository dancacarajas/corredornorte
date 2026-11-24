<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class PerfilService
{
    public const ACOES = ['ver', 'criar', 'editar', 'excluir', 'operar'];

    public function __construct(private PDO $db)
    {
    }

    /**
     * Mapa 360° do sistema — espelha o menu. Cada item declara quais ações existem.
     * Códigos gerados: {recurso}.{acao}
     *
     * @return list<array{
     *   grupo:string,
     *   itens:list<array{recurso:string,label:string,menu:string,acoes:list<string>,slug?:string}>
     * }>
     */
    public static function mapaAcesso360(): array
    {
        $cad = static function (string $recurso, string $label, string $grupoPath, string $slug): array {
            return [
                'recurso' => $recurso,
                'label' => $label,
                'menu' => $grupoPath . ' → ' . $label,
                'slug' => $slug,
                'acoes' => ['ver', 'criar', 'editar', 'excluir'],
            ];
        };

        return [
            [
                'grupo' => 'Principal',
                'itens' => [
                    ['recurso' => 'dashboard', 'label' => 'Visão geral', 'menu' => 'Principal → Visão geral', 'acoes' => ['ver']],
                    ['recurso' => 'alertas', 'label' => 'Central de Alertas', 'menu' => 'Principal → Central de Alertas', 'acoes' => ['ver', 'operar']],
                    ['recurso' => 'mapa', 'label' => 'Mapa do sistema', 'menu' => 'Principal → Mapa do sistema', 'acoes' => ['ver']],
                ],
            ],
            [
                'grupo' => 'Efetivo',
                'itens' => [
                    ['recurso' => 'efetivo', 'label' => 'Efetivo', 'menu' => 'RH → Efetivo', 'acoes' => ['ver', 'criar', 'editar', 'excluir', 'operar']],
                ],
            ],
            [
                'grupo' => 'Organização',
                'itens' => [
                    $cad('empresas', 'Empresas', 'RH → Organização', 'empresas'),
                    $cad('contratos', 'Contratos', 'RH → Organização', 'contratos'),
                    $cad('centros_custo', 'Centros de Custo', 'RH → Organização', 'centros-custo'),
                    $cad('ordens_servico', 'Ordens de Serviço', 'RH → Organização', 'ordens-servico'),
                    $cad('localidades', 'Localidades', 'RH → Organização', 'localidades'),
                ],
            ],
            [
                'grupo' => 'Horários e calendário',
                'itens' => [
                    $cad('horarios', 'Horários', 'RH → Horários e calendário', 'horarios'),
                    $cad('calendarios', 'Feriados', 'RH → Horários e calendário', 'calendarios'),
                ],
            ],
            [
                'grupo' => 'Pessoas e papéis',
                'itens' => [
                    $cad('lotacoes', 'Lotações', 'RH → Pessoas e papéis', 'lotacoes'),
                    $cad('cargos', 'Cargo', 'RH → Pessoas e papéis', 'cargos'),
                    $cad('funcoes', 'Função', 'RH → Pessoas e papéis', 'funcoes'),
                    $cad('gestores_arcadis', 'Gestor Arcadis', 'RH → Pessoas e papéis', 'gestores-arcadis'),
                    $cad('gestores_cliente', 'Gestor Cliente', 'RH → Pessoas e papéis', 'gestores-cliente'),
                    $cad('supervisores', 'Supervisor', 'RH → Pessoas e papéis', 'supervisores'),
                ],
            ],
            [
                'grupo' => 'Medição e ponto',
                'itens' => [
                    ['recurso' => 'ponto', 'label' => 'Gestão de Ponto', 'menu' => 'RH → Medição e ponto → Gestão de Ponto', 'acoes' => ['ver', 'editar', 'excluir', 'operar']],
                    ['recurso' => 'justificativa_tipos', 'label' => 'Tipos de justificativa', 'menu' => 'RH → Medição e ponto → Tipos de justificativa', 'acoes' => ['ver', 'criar', 'editar', 'excluir']],
                    ['recurso' => 'medicao', 'label' => 'Medição', 'menu' => 'RH → Medição e ponto → Medição', 'acoes' => ['ver', 'operar']],
                    ['recurso' => 'he_relatorio', 'label' => 'Relatório de HE', 'menu' => 'RH → Medição e ponto → Relatório de HE', 'acoes' => ['ver', 'operar']],
                    ['recurso' => 'ra', 'label' => 'Relatórios de Atividades', 'menu' => 'RH → Medição e ponto → Relatórios de Atividades', 'acoes' => ['ver', 'editar', 'operar']],
                    ['recurso' => 'folha', 'label' => 'Folha de ponto', 'menu' => 'RH → Medição e ponto → Folha', 'acoes' => ['ver', 'operar']],
                ],
            ],
            [
                'grupo' => 'Vale Refeição',
                'itens' => [
                    ['recurso' => 'vr', 'label' => 'Configuração VR', 'menu' => 'RH → Vale Refeição → Configuração', 'acoes' => ['ver', 'criar', 'editar', 'excluir']],
                    ['recurso' => 'vr_relatorio', 'label' => 'Gerar relatório VR', 'menu' => 'RH → Vale Refeição → Gerar relatório', 'acoes' => ['ver', 'operar']],
                ],
            ],
            [
                'grupo' => 'TI / Equipamentos',
                'itens' => [
                    ['recurso' => 'entregas', 'label' => 'Entrega de Equipamentos', 'menu' => 'RH → Entrega de Equipamentos', 'acoes' => ['ver', 'operar']],
                ],
            ],
            [
                'grupo' => 'Sistema',
                'itens' => [
                    ['recurso' => 'usuarios', 'label' => 'Usuários', 'menu' => 'Sistema → Usuários', 'acoes' => ['ver', 'criar', 'editar', 'excluir', 'operar']],
                    ['recurso' => 'atividade', 'label' => 'Atividade', 'menu' => 'Sistema → Atividade', 'acoes' => ['ver']],
                    ['recurso' => 'perfis', 'label' => 'Perfis', 'menu' => 'Sistema → Perfis', 'acoes' => ['ver', 'criar', 'editar', 'excluir', 'operar']],
                    ['recurso' => 'email', 'label' => 'Conta de e-mail', 'menu' => 'Sistema → Conta de e-mail', 'acoes' => ['ver', 'operar']],
                    ['recurso' => 'alertas_config', 'label' => 'Configurar alertas', 'menu' => 'Sistema → Configurar alertas', 'acoes' => ['ver', 'editar', 'operar']],
                    ['recurso' => 'importacoes', 'label' => 'Importações', 'menu' => 'Sistema → Importações', 'acoes' => ['ver', 'operar']],
                ],
            ],
        ];
    }

    /** @return list<string> */
    public static function ordemGruposMenu(): array
    {
        return array_map(static fn (array $g): string => $g['grupo'], self::mapaAcesso360());
    }

    /**
     * Slug de rota de cadastro → recurso.
     *
     * @return array<string, string>
     */
    public static function slugParaRecurso(): array
    {
        $out = [];
        foreach (self::mapaAcesso360() as $grupo) {
            foreach ($grupo['itens'] as $item) {
                if (!empty($item['slug'])) {
                    $out[(string) $item['slug']] = (string) $item['recurso'];
                }
            }
        }
        return $out;
    }

    /** Compat: mapa antigo usado pelo sidebar/gate. */
    public static function mapaCadastrosMenu(): array
    {
        $out = [];
        foreach (self::mapaAcesso360() as $grupo) {
            foreach ($grupo['itens'] as $item) {
                if (empty($item['slug'])) {
                    continue;
                }
                $r = (string) $item['recurso'];
                $out[(string) $item['slug']] = [
                    'ver' => $r . '.ver',
                    'editar' => $r . '.editar',
                    'criar' => $r . '.criar',
                    'excluir' => $r . '.excluir',
                    'grupo' => $grupo['grupo'],
                    'menu' => (string) $item['menu'],
                    'label' => (string) $item['label'],
                ];
            }
        }
        return $out;
    }

    /** @return list<string> */
    public static function codigosCadastrosVer(): array
    {
        return array_values(array_map(static fn (array $m): string => $m['ver'], self::mapaCadastrosMenu()));
    }

    /** @return list<string> */
    public static function codigosCadastrosEditar(): array
    {
        return array_values(array_map(static fn (array $m): string => $m['editar'], self::mapaCadastrosMenu()));
    }

    /** @return list<string> */
    public static function codigosCadastrosCriar(): array
    {
        return array_values(array_map(static fn (array $m): string => $m['criar'], self::mapaCadastrosMenu()));
    }

    /** @return list<string> */
    public static function codigosCadastrosExcluir(): array
    {
        return array_values(array_map(static fn (array $m): string => $m['excluir'], self::mapaCadastrosMenu()));
    }

    /**
     * @return list<array{codigo:string,nome:string,grupo:string,descricao:string}>
     */
    public static function catalogoDefinicao(): array
    {
        $acaoNome = [
            'ver' => 'Ver',
            'criar' => 'Criar',
            'editar' => 'Editar',
            'excluir' => 'Excluir',
            'operar' => 'Operar',
        ];
        $rows = [];
        foreach (self::mapaAcesso360() as $grupo) {
            foreach ($grupo['itens'] as $item) {
                foreach ($item['acoes'] as $acao) {
                    $rows[] = [
                        'codigo' => $item['recurso'] . '.' . $acao,
                        'nome' => $acaoNome[$acao] . ' · ' . $item['label'],
                        'grupo' => $grupo['grupo'],
                        'descricao' => $item['menu'] . ' — ' . $acaoNome[$acao],
                    ];
                }
            }
        }

        // Aliases legado (Auth ainda honra; UI da matriz não exibe).
        $legado = [
            ['codigo' => 'cadastros.ver', 'nome' => '[Legado] Ver cadastros', 'grupo' => 'Legado', 'descricao' => 'Atalho antigo'],
            ['codigo' => 'cadastros.editar', 'nome' => '[Legado] Editar cadastros', 'grupo' => 'Legado', 'descricao' => 'Atalho antigo'],
            ['codigo' => 'usuarios.gerenciar', 'nome' => '[Legado] Gerenciar usuários', 'grupo' => 'Legado', 'descricao' => 'Equivale a todas as ações de Usuários'],
            ['codigo' => 'perfis.gerenciar', 'nome' => '[Legado] Gerenciar perfis', 'grupo' => 'Legado', 'descricao' => 'Equivale a todas as ações de Perfis'],
            ['codigo' => 'email.gerenciar', 'nome' => '[Legado] Conta de e-mail', 'grupo' => 'Legado', 'descricao' => 'Equivale a Ver+Operar e-mail'],
            ['codigo' => 'importacoes.gerenciar', 'nome' => '[Legado] Importações', 'grupo' => 'Legado', 'descricao' => 'Equivale a Ver+Operar importações'],
            ['codigo' => 'alertas.gerenciar', 'nome' => '[Legado] Configurar alertas', 'grupo' => 'Legado', 'descricao' => 'Equivale a Ver+Editar+Operar config alertas'],
            ['codigo' => 'ponto.excluir_periodo', 'nome' => '[Legado] Excluir período', 'grupo' => 'Legado', 'descricao' => 'Equivale a ponto.excluir'],
            ['codigo' => 'folha.enviar', 'nome' => '[Legado] Enviar folha', 'grupo' => 'Legado', 'descricao' => 'Equivale a folha.operar'],
            ['codigo' => 'medicao.editar', 'nome' => '[Legado] Operar medição', 'grupo' => 'Legado', 'descricao' => 'Equivale a medicao.operar'],
            ['codigo' => 'vr.editar', 'nome' => '[Legado] Editar VR', 'grupo' => 'Legado', 'descricao' => 'Equivale a criar/editar/excluir VR'],
            ['codigo' => 'dashboard.ver', 'nome' => '[Compat] dashboard.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto pelo mapa'],
            ['codigo' => 'alertas.ver', 'nome' => '[Compat] alertas.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto pelo mapa'],
            ['codigo' => 'efetivo.ver', 'nome' => '[Compat] efetivo.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'efetivo.editar', 'nome' => '[Compat] efetivo.editar', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'ponto.ver', 'nome' => '[Compat] ponto.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'ponto.editar', 'nome' => '[Compat] ponto.editar', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'folha.ver', 'nome' => '[Compat] folha.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'medicao.ver', 'nome' => '[Compat] medicao.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'ra.ver', 'nome' => '[Compat] ra.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'ra.editar', 'nome' => '[Compat] ra.editar', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'vr.ver', 'nome' => '[Compat] vr.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'atividade.ver', 'nome' => '[Compat] atividade.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
            ['codigo' => 'entregas.gerir', 'nome' => '[Compat] entregas.gerir', 'grupo' => 'Legado', 'descricao' => 'Equivale a entregas.operar'],
            ['codigo' => 'entregas.ver', 'nome' => '[Compat] entregas.ver', 'grupo' => 'Legado', 'descricao' => 'Já coberto'],
        ];

        // Evita duplicar códigos já gerados pelo mapa.
        $seen = [];
        $final = [];
        foreach (array_merge($rows, $legado) as $row) {
            $c = $row['codigo'];
            if (isset($seen[$c])) {
                continue;
            }
            $seen[$c] = true;
            $final[] = $row;
        }

        return $final;
    }

    public function ensureCatalog(): void
    {
        $ins = $this->db->prepare(
            'INSERT INTO permissoes (codigo, nome, grupo, descricao)
             VALUES (:c, :n, :g, :d)
             ON DUPLICATE KEY UPDATE nome = VALUES(nome), grupo = VALUES(grupo), descricao = VALUES(descricao)'
        );
        $adminId = (int) $this->db->query("SELECT id FROM perfis WHERE codigo = 'admin' LIMIT 1")->fetchColumn();
        $link = $this->db->prepare(
            'INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:p, :m)'
        );

        foreach (self::catalogoDefinicao() as $row) {
            $exists = $this->db->prepare('SELECT id FROM permissoes WHERE codigo = :c LIMIT 1');
            $exists->execute(['c' => $row['codigo']]);
            $antes = $exists->fetchColumn();

            $ins->execute([
                'c' => $row['codigo'],
                'n' => $row['nome'],
                'g' => $row['grupo'],
                'd' => $row['descricao'],
            ]);

            if ($antes === false && $adminId > 0) {
                $permId = (int) $this->db->query(
                    'SELECT id FROM permissoes WHERE codigo = ' . $this->db->quote($row['codigo'])
                )->fetchColumn();
                if ($permId > 0) {
                    $link->execute(['p' => $adminId, 'm' => $permId]);
                }
            }
        }

        if ($adminId > 0) {
            $qtd = (int) $this->db->query(
                'SELECT COUNT(*) FROM perfil_permissoes WHERE perfil_id = ' . $adminId
            )->fetchColumn();
            if ($qtd === 0) {
                foreach (self::catalogoDefinicao() as $row) {
                    $permId = (int) $this->db->query(
                        'SELECT id FROM permissoes WHERE codigo = ' . $this->db->quote($row['codigo'])
                    )->fetchColumn();
                    if ($permId > 0) {
                        $link->execute(['p' => $adminId, 'm' => $permId]);
                    }
                }
            }
        }

        $this->expandirLegadoPara360();
    }

    /** Propaga atalhos antigos para o mapa 360° completo. */
    public function expandirLegadoPara360(): void
    {
        $link = $this->db->prepare(
            'INSERT IGNORE INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:p, :m)'
        );
        $grant = function (int $perfilId, array $codigos) use ($link): void {
            foreach ($codigos as $codigo) {
                $permId = (int) $this->db->query(
                    'SELECT id FROM permissoes WHERE codigo = ' . $this->db->quote($codigo)
                )->fetchColumn();
                if ($permId > 0) {
                    $link->execute(['p' => $perfilId, 'm' => $permId]);
                }
            }
        };

        $mapas = [
            'cadastros.ver' => self::codigosCadastrosVer(),
            'cadastros.editar' => array_merge(
                self::codigosCadastrosVer(),
                self::codigosCadastrosCriar(),
                self::codigosCadastrosEditar(),
                self::codigosCadastrosExcluir()
            ),
            'usuarios.gerenciar' => ['usuarios.ver', 'usuarios.criar', 'usuarios.editar', 'usuarios.excluir', 'usuarios.operar'],
            'perfis.gerenciar' => ['perfis.ver', 'perfis.criar', 'perfis.editar', 'perfis.excluir', 'perfis.operar'],
            'email.gerenciar' => ['email.ver', 'email.operar'],
            'importacoes.gerenciar' => ['importacoes.ver', 'importacoes.operar'],
            'alertas.gerenciar' => ['alertas_config.ver', 'alertas_config.editar', 'alertas_config.operar'],
            'ponto.excluir_periodo' => ['ponto.excluir'],
            'folha.enviar' => ['folha.operar'],
            'medicao.editar' => ['medicao.operar', 'he_relatorio.ver', 'he_relatorio.operar'],
            'medicao.operar' => ['he_relatorio.ver', 'he_relatorio.operar'],
            'medicao.ver' => ['he_relatorio.ver'],
            'vr.editar' => ['vr.criar', 'vr.editar', 'vr.excluir', 'vr_relatorio.operar'],
            'efetivo.editar' => ['efetivo.criar', 'efetivo.editar', 'efetivo.excluir', 'efetivo.operar'],
            'ponto.editar' => ['ponto.editar', 'ponto.operar', 'justificativa_tipos.ver', 'justificativa_tipos.criar', 'justificativa_tipos.editar', 'justificativa_tipos.excluir'],
            'ra.editar' => ['ra.editar', 'ra.operar'],
            'dashboard.ver' => ['dashboard.ver', 'mapa.ver'],
            'alertas.ver' => ['alertas.ver', 'alertas.operar'],
        ];

        // Também: quem tem X.editar ganha X.criar (cadastros granulares já existentes).
        foreach (self::mapaCadastrosMenu() as $meta) {
            $mapas[$meta['editar']] = array_merge(
                $mapas[$meta['editar']] ?? [],
                [$meta['ver'], $meta['criar'], $meta['editar'], $meta['excluir']]
            );
        }

        foreach ($mapas as $origem => $destinos) {
            $idOrigem = (int) $this->db->query(
                'SELECT id FROM permissoes WHERE codigo = ' . $this->db->quote($origem) . ' LIMIT 1'
            )->fetchColumn();
            if ($idOrigem <= 0) {
                continue;
            }
            $perfis = $this->db->query(
                'SELECT DISTINCT perfil_id FROM perfil_permissoes WHERE permissao_id = ' . $idOrigem
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($perfis as $perfilId) {
                $grant((int) $perfilId, $destinos);
            }
        }
    }

    /** Compatível com nome antigo. */
    public function expandirCadastrosLegado(): void
    {
        $this->expandirLegadoPara360();
    }

    /**
     * Estrutura pronta para a UI matriz (com IDs do banco).
     *
     * @return list<array{grupo:string,itens:list<array{recurso:string,label:string,menu:string,celulas:array<string,?array{id:int,codigo:string}>}>}>
     */
    public function matrizParaUi(): array
    {
        $this->ensureCatalog();
        $byCode = [];
        foreach ($this->db->query('SELECT id, codigo FROM permissoes')->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $byCode[(string) $row['codigo']] = (int) $row['id'];
        }

        $out = [];
        foreach (self::mapaAcesso360() as $grupo) {
            $itens = [];
            foreach ($grupo['itens'] as $item) {
                $celulas = [];
                foreach (self::ACOES as $acao) {
                    if (!in_array($acao, $item['acoes'], true)) {
                        $celulas[$acao] = null;
                        continue;
                    }
                    $codigo = $item['recurso'] . '.' . $acao;
                    $id = $byCode[$codigo] ?? 0;
                    $celulas[$acao] = $id > 0 ? ['id' => $id, 'codigo' => $codigo] : null;
                }
                $itens[] = [
                    'recurso' => $item['recurso'],
                    'label' => $item['label'],
                    'menu' => $item['menu'],
                    'celulas' => $celulas,
                ];
            }
            $out[] = ['grupo' => $grupo['grupo'], 'itens' => $itens];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function listar(bool $somenteAtivos = false): array
    {
        $this->ensureCatalog();
        $sql = 'SELECT p.*,
                       (SELECT COUNT(*) FROM usuario_perfis up WHERE up.perfil_id = p.id) AS total_usuarios,
                       (SELECT COUNT(*) FROM perfil_permissoes pp WHERE pp.perfil_id = p.id) AS total_permissoes
                FROM perfis p';
        if ($somenteAtivos) {
            $sql .= " WHERE p.status = 'ativo'";
        }
        $sql .= ' ORDER BY p.sistema DESC, p.nome';

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return ?array<string,mixed> */
    public function buscar(int $id): ?array
    {
        $this->ensureCatalog();
        $st = $this->db->prepare('SELECT * FROM perfis WHERE id = :id');
        $st->execute(['id' => $id]);
        $perfil = $st->fetch(PDO::FETCH_ASSOC);
        if ($perfil === false) {
            return null;
        }
        $ps = $this->db->prepare(
            'SELECT permissao_id FROM perfil_permissoes WHERE perfil_id = :id'
        );
        $ps->execute(['id' => $id]);
        $perfil['permissao_ids'] = array_map('intval', $ps->fetchAll(PDO::FETCH_COLUMN) ?: []);

        return $perfil;
    }

    /** @return list<array<string,mixed>> */
    public function catalogoPermissoes(): array
    {
        $this->ensureCatalog();
        $rows = $this->db->query('SELECT * FROM permissoes')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $ordem = array_flip(array_merge(self::ordemGruposMenu(), ['Legado']));
        usort($rows, static function (array $a, array $b) use ($ordem): int {
            $ga = (string) ($a['grupo'] ?? '');
            $gb = (string) ($b['grupo'] ?? '');
            $oa = $ordem[$ga] ?? 999;
            $ob = $ordem[$gb] ?? 999;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }
            return strcasecmp((string) ($a['nome'] ?? ''), (string) ($b['nome'] ?? ''));
        });

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public function catalogoPermissoesMapa(): array
    {
        return array_values(array_filter(
            $this->catalogoPermissoes(),
            static fn (array $p): bool => (string) ($p['grupo'] ?? '') !== 'Legado'
        ));
    }

    /**
     * @param array{codigo:string,nome:string,descricao?:string,status?:string,permissao_ids?:list<int>} $data
     */
    public function criar(array $data, ?int $actorId = null): int
    {
        $this->ensureCatalog();
        $codigo = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]+/', '_', $data['codigo']) ?? ''));
        $nome = trim($data['nome']);
        $descricao = trim((string) ($data['descricao'] ?? ''));
        $status = ($data['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        $permIds = $this->filtrarPermissoesValidas($data['permissao_ids'] ?? []);

        if ($codigo === '' || strlen($codigo) < 2) {
            throw new RuntimeException('Informe um código válido para o perfil.');
        }
        if ($nome === '') {
            throw new RuntimeException('Informe o nome do perfil.');
        }
        if ($this->codigoExiste($codigo)) {
            throw new RuntimeException('Já existe um perfil com este código.');
        }
        if ($permIds === []) {
            throw new RuntimeException('Marque ao menos uma permissão para o perfil.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'INSERT INTO perfis (codigo, nome, descricao, status, sistema) VALUES (:c, :n, :d, :s, 0)'
            )->execute(['c' => $codigo, 'n' => $nome, 'd' => $descricao !== '' ? $descricao : null, 's' => $status]);
            $id = (int) $this->db->lastInsertId();
            $this->syncPermissoes($id, $permIds);
            $this->audit($id, 'criar', null, [
                'codigo' => $codigo, 'nome' => $nome, 'permissao_ids' => $permIds,
            ], $actorId);
            $this->db->commit();

            return $id;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array{nome:string,descricao?:string,status?:string,permissao_ids?:list<int>} $data
     */
    public function atualizar(int $id, array $data, ?int $actorId = null): void
    {
        $this->ensureCatalog();
        $atual = $this->buscar($id);
        if ($atual === null) {
            throw new RuntimeException('Perfil não encontrado.');
        }

        $nome = trim($data['nome']);
        $descricao = trim((string) ($data['descricao'] ?? ''));
        $status = ($data['status'] ?? 'ativo') === 'inativo' ? 'inativo' : 'ativo';
        $permIds = $this->filtrarPermissoesValidas($data['permissao_ids'] ?? []);

        if ($nome === '') {
            throw new RuntimeException('Informe o nome do perfil.');
        }
        if (!empty($atual['sistema']) && $status === 'inativo') {
            throw new RuntimeException('O perfil de sistema Administrador não pode ser inativado.');
        }
        if ($permIds === []) {
            throw new RuntimeException('Marque ao menos uma permissão para o perfil.');
        }
        if (!empty($atual['sistema']) || (string) ($atual['codigo'] ?? '') === 'admin') {
            $idsObrigatorios = $this->idsPorCodigos(['perfis.gerenciar', 'perfis.operar', 'perfis.editar']);
            if ($idsObrigatorios !== [] && count(array_intersect($idsObrigatorios, $permIds)) === 0) {
                throw new RuntimeException('O perfil Administrador precisa manter acesso a Perfis (Operar/Editar).');
            }
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE perfis SET nome = :n, descricao = :d, status = :s WHERE id = :id'
            )->execute([
                'n' => $nome,
                'd' => $descricao !== '' ? $descricao : null,
                's' => $status,
                'id' => $id,
            ]);
            $this->syncPermissoes($id, $permIds);
            $this->audit($id, 'atualizar', [
                'nome' => $atual['nome'], 'status' => $atual['status'], 'permissao_ids' => $atual['permissao_ids'] ?? [],
            ], [
                'nome' => $nome, 'status' => $status, 'permissao_ids' => $permIds,
            ], $actorId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function duplicar(int $id, string $novoCodigo, string $novoNome, ?int $actorId = null): int
    {
        $origem = $this->buscar($id);
        if ($origem === null) {
            throw new RuntimeException('Perfil de origem não encontrado.');
        }

        return $this->criar([
            'codigo' => $novoCodigo,
            'nome' => $novoNome !== '' ? $novoNome : ((string) $origem['nome'] . ' (cópia)'),
            'descricao' => (string) ($origem['descricao'] ?? ''),
            'status' => 'ativo',
            'permissao_ids' => array_map('intval', $origem['permissao_ids'] ?? []),
        ], $actorId);
    }

    /** Exclui perfil não-sistema (inativa se ainda houver usuários vinculados). */
    public function excluir(int $id, ?int $actorId = null): void
    {
        $atual = $this->buscar($id);
        if ($atual === null) {
            throw new RuntimeException('Perfil não encontrado.');
        }
        if (!empty($atual['sistema']) || (string) ($atual['codigo'] ?? '') === 'admin') {
            throw new RuntimeException('O perfil Administrador não pode ser excluído.');
        }

        $usuarios = (int) $this->db->query(
            'SELECT COUNT(*) FROM usuario_perfis WHERE perfil_id = ' . (int) $id
        )->fetchColumn();

        if ($usuarios > 0) {
            if ((string) ($atual['status'] ?? '') === 'inativo') {
                throw new RuntimeException('Perfil já inativo e ainda vinculado a usuários.');
            }
            $this->db->prepare("UPDATE perfis SET status = 'inativo' WHERE id = :id")->execute(['id' => $id]);
            $this->audit($id, 'excluir', [
                'status' => $atual['status'], 'usuarios' => $usuarios,
            ], [
                'status' => 'inativo', 'usuarios' => $usuarios,
            ], $actorId);
            return;
        }

        $this->db->beginTransaction();
        try {
            $this->db->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = :id')->execute(['id' => $id]);
            $this->db->prepare('DELETE FROM perfis WHERE id = :id')->execute(['id' => $id]);
            $this->audit($id, 'excluir', [
                'codigo' => $atual['codigo'], 'nome' => $atual['nome'],
            ], null, $actorId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param list<string> $codigos @return list<int> */
    private function idsPorCodigos(array $codigos): array
    {
        $ids = [];
        foreach ($codigos as $codigo) {
            $id = (int) $this->db->query(
                'SELECT id FROM permissoes WHERE codigo = ' . $this->db->quote($codigo) . ' LIMIT 1'
            )->fetchColumn();
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @param list<int|string> $permIds @return list<int> */
    private function filtrarPermissoesValidas(array $permIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $permIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $valid = $this->db->query("SELECT id FROM permissoes WHERE id IN ({$in})")
            ->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_map('intval', $valid);
    }

    /** @param list<int> $permIds */
    private function syncPermissoes(int $perfilId, array $permIds): void
    {
        $this->db->prepare('DELETE FROM perfil_permissoes WHERE perfil_id = :id')->execute(['id' => $perfilId]);
        $ins = $this->db->prepare(
            'INSERT INTO perfil_permissoes (perfil_id, permissao_id) VALUES (:p, :m)'
        );
        foreach ($permIds as $mid) {
            if ($mid <= 0) {
                continue;
            }
            $ins->execute(['p' => $perfilId, 'm' => $mid]);
        }
    }

    private function codigoExiste(string $codigo, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT id FROM perfis WHERE codigo = :c';
        $params = ['c' => $codigo];
        if ($ignoreId !== null) {
            $sql .= ' AND id <> :id';
            $params['id'] = $ignoreId;
        }
        $st = $this->db->prepare($sql);
        $st->execute($params);

        return (bool) $st->fetchColumn();
    }

    /** @param ?array<string,mixed> $antes @param ?array<string,mixed> $depois */
    private function audit(int $id, string $acao, ?array $antes, ?array $depois, ?int $userId): void
    {
        $this->db->prepare(
            'INSERT INTO rh_auditoria (entidade, entidade_id, acao, antes_json, depois_json, usuario_id)
             VALUES (\'perfis\', :id, :a, :antes, :depois, :u)'
        )->execute([
            'id' => $id,
            'a' => $acao,
            'antes' => $antes ? json_encode($antes, JSON_UNESCAPED_UNICODE) : null,
            'depois' => $depois ? json_encode($depois, JSON_UNESCAPED_UNICODE) : null,
            'u' => $userId,
        ]);
    }
}
