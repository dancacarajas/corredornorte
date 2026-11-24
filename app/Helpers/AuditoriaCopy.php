<?php

declare(strict_types=1);

namespace App\Helpers;

use PDO;

/**
 * Traduz logs técnicos de rh_auditoria para texto legível ao usuário.
 */
final class AuditoriaCopy
{
    /** @var array<string, string> */
    private const ACOES = [
        'atualizar' => 'Dados atualizados',
        'criar' => 'Cadastro criado',
        'excluir' => 'Apagado permanentemente',
        'excluir_massa' => 'Apagado em massa',
        'transferencia_lotacao' => 'Transferência de lotação',
        'encerrar_lotacao' => 'Lotação encerrada',
        'excecao_individual' => 'Exceção individual',
        'percentual_he' => 'Percentual de HE',
        'confirmar' => 'Importação confirmada',
        'apontamento' => 'Apontamento de ponto',
        'link_assinatura' => 'Link de assinatura gerado',
        'assinatura_eletronica' => 'Assinatura eletrônica',
        'divergencia_reportada' => 'Divergência reportada',
        'atualizar_modelo_pdf' => 'Modelo de PDF atualizado',
        'corrigir_periodo_pdf' => 'Período do PDF corrigido',
        'atribuir' => 'Alerta atribuído',
        'desatribuir' => 'Alerta desatribuído',
        'dispensar' => 'Alerta dispensado',
        'dispensar_global' => 'Alertas dispensados',
        'forcar_resolver' => 'Alerta resolvido manualmente',
        'salvar_tipos' => 'Tipos de alerta salvos',
        'salvar_destinos' => 'Destinos de alerta salvos',
        'salvar_config' => 'Configuração de alertas salva',
    ];

    /** @var array<string, string> */
    private const CAMPOS_COLABORADOR = [
        'empresa_id' => 'Empresa',
        'matricula' => 'Matrícula',
        'nome' => 'Nome',
        'cpf' => 'CPF',
        'email_corp' => 'E-mail corporativo',
        'email_alt' => 'E-mail alternativo',
        'telefone' => 'Telefone',
        'data_admissao' => 'Admissão',
        'cargo' => 'Cargo',
        'funcao' => 'Função',
        'status' => 'Status',
        'gestor_id' => 'Gestor',
        'supervisor_id' => 'Supervisor',
        'he_fluxo' => 'Fluxo de HE',
        'gestor_cliente_id' => 'Gestor cliente',
    ];

    /** @var array<string, true> */
    private const CAMPOS_IGNORAR = [
        'id' => true,
        'created_at' => true,
        'updated_at' => true,
        'lotacao_id' => true,
    ];

    /** @var array<string, string> */
    private const STATUS = [
        'ativo' => 'Ativo',
        'desligado' => 'Desligado',
        'afastado' => 'Afastado',
        'inativo' => 'Inativo',
    ];

    /** @var array<string, string> */
    private const MOTIVOS_LOTACAO = [
        'admissao' => 'Admissão',
        'transferencia' => 'Transferência',
        'correcao' => 'Correção',
        'importacao' => 'Importação',
    ];

    public static function labelAcao(string $acao): string
    {
        $key = trim($acao);
        return self::ACOES[$key] ?? self::tituloGenerico($key);
    }

    /**
     * @return list<string>
     */
    public static function detalheLinhas(
        string $acao,
        ?string $antesJson,
        ?string $depoisJson,
        ?PDO $pdo = null
    ): array {
        $antes = self::decode($antesJson);
        $depois = self::decode($depoisJson);

        return match (trim($acao)) {
            'atualizar' => self::diffCampos($antes, $depois, $pdo),
            'criar' => self::resumoCriacao($depois, $pdo),
            'excluir', 'excluir_massa' => self::resumoExclusao($antes, $depois),
            'transferencia_lotacao' => self::resumoTransferencia($antes, $depois, $pdo),
            'encerrar_lotacao', 'excecao_individual', 'percentual_he' => self::resumoComTexto($depois, match (trim($acao)) {
                'encerrar_lotacao' => 'Lotação vigente encerrada.',
                'excecao_individual' => 'Exceção individual registrada.',
                default => 'Percentual de HE atualizado.',
            }),
            'confirmar' => self::resumoImportacao($depois),
            'apontamento' => self::resumoApontamento($depois),
            'link_assinatura' => ['Link de assinatura gerado para o documento.'],
            'assinatura_eletronica' => self::resumoAssinatura($depois),
            'divergencia_reportada' => self::linhaOu('Divergência reportada pelo colaborador.', $depois, 'motivo'),
            'atualizar_modelo_pdf' => ['Modelo do PDF da folha foi atualizado.'],
            'corrigir_periodo_pdf' => ['Período do PDF da folha foi corrigido.'],
            'atribuir', 'desatribuir', 'dispensar', 'dispensar_global', 'forcar_resolver',
            'salvar_tipos', 'salvar_destinos', 'salvar_config' => self::resumoAlerta(trim($acao), $depois),
            default => self::fallback($depois, $antes),
        };
    }

    /**
     * @param array<string, mixed> $row
     * @return array{acao_label:string,quando:string,detalhe_linhas:list<string>}
     */
    public static function formatarLinha(array $row, ?PDO $pdo = null): array
    {
        $acao = (string) ($row['acao'] ?? '');

        return [
            'acao_label' => self::labelAcao($acao),
            'quando' => AppClock::format(isset($row['created_at']) ? (string) $row['created_at'] : null),
            'detalhe_linhas' => self::detalheLinhas(
                $acao,
                isset($row['antes_json']) ? (string) $row['antes_json'] : null,
                isset($row['depois_json']) ? (string) $row['depois_json'] : null,
                $pdo
            ),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function formatarLista(array $rows, ?PDO $pdo = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            $fmt = self::formatarLinha($row, $pdo);
            $out[] = array_merge($row, $fmt);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private static function tituloGenerico(string $acao): string
    {
        if ($acao === '') {
            return 'Alteração';
        }
        $txt = str_replace('_', ' ', $acao);

        return mb_convert_case($txt, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function diffCampos(array $antes, array $depois, ?PDO $pdo): array
    {
        if ($depois === []) {
            return ['Atualização registrada sem detalhes.'];
        }

        $campos = array_unique(array_merge(array_keys($antes), array_keys($depois)));
        $linhas = [];

        foreach ($campos as $campo) {
            if (!is_string($campo) || isset(self::CAMPOS_IGNORAR[$campo])) {
                continue;
            }
            if (!isset(self::CAMPOS_COLABORADOR[$campo]) && !isset($depois[$campo]) && !isset($antes[$campo])) {
                continue;
            }

            $label = self::CAMPOS_COLABORADOR[$campo] ?? self::tituloGenerico($campo);
            $de = self::valorCampo($campo, $antes[$campo] ?? null, $pdo);
            $para = self::valorCampo($campo, $depois[$campo] ?? null, $pdo);

            if (self::iguais($de, $para)) {
                continue;
            }

            $linhas[] = sprintf('%s: %s → %s', $label, $de, $para);
        }

        if ($linhas === []) {
            return ['Dados salvos sem mudanças relevantes nos campos cadastrais.'];
        }

        return $linhas;
    }

    /**
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function resumoCriacao(array $depois, ?PDO $pdo): array
    {
        $nome = trim((string) ($depois['nome'] ?? ''));
        $mat = trim((string) ($depois['matricula'] ?? ''));
        if ($nome !== '' || $mat !== '') {
            $partes = array_filter([$nome !== '' ? $nome : null, $mat !== '' ? 'Mat. ' . $mat : null]);

            return ['Colaborador cadastrado: ' . implode(' · ', $partes) . '.'];
        }

        return self::diffCampos([], $depois, $pdo);
    }

    /**
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function resumoExclusao(array $antes, array $depois): array
    {
        $de = self::labelStatus((string) ($antes['status'] ?? 'ativo'));
        $para = self::labelStatus((string) ($depois['status'] ?? 'desligado'));

        return ["Cadastro apagado permanentemente do sistema (antes: {$de})."];
    }

    /**
     * @param array<string, mixed> $antes
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function resumoTransferencia(array $antes, array $depois, ?PDO $pdo): array
    {
        if (isset($depois['resumo']) && is_string($depois['resumo']) && trim($depois['resumo']) !== '') {
            return [trim($depois['resumo'])];
        }

        $novaId = (int) ($depois['colaborador_lotacao_id'] ?? $depois['nova_id'] ?? 0);
        $info = $novaId > 0 ? self::buscarLotacaoVinculo($pdo, $novaId) : null;

        if ($info !== null) {
            $linhas = [
                sprintf(
                    'Nova lotação: %s%s',
                    $info['codigo'],
                    $info['nome'] !== '' ? ' — ' . $info['nome'] : ''
                ),
            ];
            if ($info['localidade'] !== '') {
                $linhas[] = 'Localidade: ' . $info['localidade'];
            }
            if ($info['inicio'] !== '') {
                $linhas[] = 'Vigência a partir de ' . AppClock::format($info['inicio'], 'd/m/Y');
            }
            if ($info['motivo'] !== '') {
                $linhas[] = 'Motivo: ' . (self::MOTIVOS_LOTACAO[$info['motivo']] ?? self::tituloGenerico($info['motivo']));
            }
            if ($info['percentual_he'] !== null) {
                $linhas[] = 'Percentual de HE: ' . $info['percentual_he'] . '%';
            }

            return $linhas;
        }

        $fechada = $antes['colaborador_lotacao_id'] ?? null;
        $nova = $depois['colaborador_lotacao_id'] ?? null;
        if ($nova !== null) {
            return ['Lotação transferida (vínculo #' . (int) $nova . ').'];
        }
        if ($fechada !== null) {
            return ['Lotação anterior encerrada (vínculo #' . (int) $fechada . ').'];
        }

        return ['Transferência de lotação registrada.'];
    }

    /**
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function resumoComTexto(array $depois, string $padrao): array
    {
        $resumo = trim((string) ($depois['resumo'] ?? ''));
        return [$resumo !== '' ? $resumo : $padrao];
    }

    /**
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function resumoImportacao(array $depois): array
    {
        $n = (int) ($depois['aplicadas'] ?? 0);

        return [$n > 0
            ? "Importação confirmada: {$n} linha(s) aplicada(s)."
            : 'Importação confirmada.'];
    }

    /**
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function resumoApontamento(array $depois): array
    {
        $hora = trim((string) ($depois['horario'] ?? ''));
        $origem = trim((string) ($depois['origem'] ?? ''));
        $seq = $depois['sequencia'] ?? null;
        $partes = [];
        if ($hora !== '') {
            $partes[] = 'Horário ' . $hora;
        }
        if ($seq !== null && $seq !== '') {
            $partes[] = 'marcação #' . (int) $seq;
        }
        if ($origem !== '') {
            $partes[] = 'origem ' . $origem;
        }

        return [$partes === [] ? 'Apontamento registrado.' : implode(' · ', $partes) . '.'];
    }

    /**
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function resumoAssinatura(array $depois): array
    {
        $linhas = ['Documento assinado eletronicamente.'];
        if (!empty($depois['nome'])) {
            $linhas[] = 'Assinante: ' . (string) $depois['nome'];
        }
        if (!empty($depois['assinado_em'])) {
            $linhas[] = 'Em: ' . AppClock::format((string) $depois['assinado_em']);
        }

        return $linhas;
    }

    /**
     * @param array<string, mixed> $depois
     * @return list<string>
     */
    private static function resumoAlerta(string $acao, array $depois): array
    {
        return match ($acao) {
            'atribuir' => ['Alerta atribuído a um responsável.'],
            'desatribuir' => ['Responsável removido do alerta.'],
            'dispensar' => self::linhaOu('Alerta dispensado.', $depois, 'motivo'),
            'dispensar_global' => self::linhaOu('Alertas dispensados em lote.', $depois, 'motivo'),
            'forcar_resolver' => ['Alerta marcado como resolvido manualmente.'],
            'salvar_tipos' => ['Tipos de alerta atualizados' . (isset($depois['qtd']) ? ' (' . (int) $depois['qtd'] . ').' : '.')],
            'salvar_destinos' => ['Destinos de alerta atualizados.'],
            'salvar_config' => ['Configuração de alertas salva.'],
            default => ['Alteração em alerta registrada.'],
        };
    }

    /**
     * @param array<string, mixed> $dados
     * @return list<string>
     */
    private static function linhaOu(string $padrao, array $dados, string $chave): array
    {
        $extra = trim((string) ($dados[$chave] ?? ''));
        if ($extra === '') {
            return [$padrao];
        }

        return [rtrim($padrao, '.') . ': ' . $extra . '.'];
    }

    /**
     * @param array<string, mixed> $depois
     * @param array<string, mixed> $antes
     * @return list<string>
     */
    private static function fallback(array $depois, array $antes): array
    {
        $fonte = $depois !== [] ? $depois : $antes;
        if ($fonte === []) {
            return ['Sem detalhes adicionais.'];
        }

        $linhas = [];
        foreach ($fonte as $k => $v) {
            if (!is_string($k) || isset(self::CAMPOS_IGNORAR[$k]) || is_array($v)) {
                continue;
            }
            $label = self::CAMPOS_COLABORADOR[$k] ?? self::tituloGenerico($k);
            $linhas[] = $label . ': ' . self::textoSimples($v);
            if (count($linhas) >= 6) {
                break;
            }
        }

        return $linhas === [] ? ['Alteração registrada.'] : $linhas;
    }

    private static function valorCampo(string $campo, mixed $valor, ?PDO $pdo): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }

        return match ($campo) {
            'status' => self::labelStatus((string) $valor),
            'he_fluxo' => ((string) $valor === 'cliente') ? 'Via gestor cliente' : 'Padrão',
            'empresa_id' => self::nomeEmpresa($pdo, (int) $valor),
            'gestor_id' => self::nomeGestorArcadis($pdo, (int) $valor),
            'supervisor_id' => self::nomeSupervisorCadastro($pdo, (int) $valor),
            'gestor_cliente_id' => self::nomeGestorCliente($pdo, (int) $valor),
            'cpf' => self::formatarCpf((string) $valor),
            'data_admissao' => AppClock::format((string) $valor, 'd/m/Y'),
            default => self::textoSimples($valor),
        };
    }

    private static function labelStatus(string $status): string
    {
        $key = strtolower(trim($status));

        return self::STATUS[$key] ?? self::tituloGenerico($status);
    }

    private static function iguais(string $a, string $b): bool
    {
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    private static function textoSimples(mixed $valor): string
    {
        if (is_bool($valor)) {
            return $valor ? 'Sim' : 'Não';
        }
        if (is_scalar($valor)) {
            $t = trim((string) $valor);

            return $t === '' ? '—' : $t;
        }

        return '—';
    }

    private static function formatarCpf(string $cpf): string
    {
        $d = preg_replace('/\D+/', '', $cpf) ?? '';
        if (strlen($d) !== 11) {
            return $cpf !== '' ? $cpf : '—';
        }

        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
    }

    private static function nomeEmpresa(?PDO $pdo, int $id): string
    {
        if ($pdo === null || $id <= 0) {
            return $id > 0 ? '#' . $id : '—';
        }
        $st = $pdo->prepare('SELECT nome FROM rh_empresas WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $nome = $st->fetchColumn();

        return is_string($nome) && $nome !== '' ? $nome : '#' . $id;
    }

    private static function nomeColaborador(?PDO $pdo, int $id): string
    {
        if ($pdo === null || $id <= 0) {
            return $id > 0 ? '#' . $id : '—';
        }
        $st = $pdo->prepare('SELECT CONCAT(matricula, \' — \', nome) FROM rh_colaboradores WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $nome = $st->fetchColumn();

        return is_string($nome) && $nome !== '' ? $nome : '#' . $id;
    }

    private static function nomeGestorCliente(?PDO $pdo, int $id): string
    {
        if ($pdo === null || $id <= 0) {
            return $id > 0 ? '#' . $id : '—';
        }
        $st = $pdo->prepare('SELECT nome FROM rh_gestores_cliente WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $nome = $st->fetchColumn();

        return is_string($nome) && $nome !== '' ? $nome : '#' . $id;
    }

    private static function nomeGestorArcadis(?PDO $pdo, int $id): string
    {
        if ($pdo === null || $id <= 0) {
            return $id > 0 ? '#' . $id : '—';
        }
        $st = $pdo->prepare('SELECT CONCAT(COALESCE(matricula, \'\'), \' — \', nome) FROM rh_gestores_arcadis WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $nome = $st->fetchColumn();

        return is_string($nome) && trim($nome, ' —') !== '' ? trim((string) $nome, ' —') : '#' . $id;
    }

    private static function nomeSupervisorCadastro(?PDO $pdo, int $id): string
    {
        if ($pdo === null || $id <= 0) {
            return $id > 0 ? '#' . $id : '—';
        }
        $st = $pdo->prepare('SELECT CONCAT(COALESCE(matricula, \'\'), \' — \', nome) FROM rh_supervisores WHERE id = :id LIMIT 1');
        $st->execute(['id' => $id]);
        $nome = $st->fetchColumn();

        return is_string($nome) && trim($nome, ' —') !== '' ? trim((string) $nome, ' —') : '#' . $id;
    }

    /**
     * @return array{codigo:string,nome:string,localidade:string,inicio:string,motivo:string,percentual_he:?int}|null
     */
    private static function buscarLotacaoVinculo(?PDO $pdo, int $vinculoId): ?array
    {
        if ($pdo === null || $vinculoId <= 0) {
            return null;
        }

        $st = $pdo->prepare(
            'SELECT l.codigo, l.nome,
                    TRIM(CONCAT(COALESCE(loc.municipio, \'\'), \'/\', COALESCE(loc.uf, \'\'))) AS localidade,
                    cl.vigencia_inicio, cl.motivo, cl.percentual_he
             FROM rh_colaborador_lotacoes cl
             INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
             LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
             WHERE cl.id = :id
             LIMIT 1'
        );
        try {
            $st->execute(['id' => $vinculoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            // Schema sem localidades: tenta versão mínima.
            $st = $pdo->prepare(
                'SELECT l.codigo, l.nome, \'\' AS localidade,
                        cl.vigencia_inicio, cl.motivo, cl.percentual_he
                 FROM rh_colaborador_lotacoes cl
                 INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
                 WHERE cl.id = :id
                 LIMIT 1'
            );
            $st->execute(['id' => $vinculoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        }

        if (!is_array($row)) {
            return null;
        }

        return [
            'codigo' => (string) ($row['codigo'] ?? ''),
            'nome' => (string) ($row['nome'] ?? ''),
            'localidade' => self::limparLocalidade((string) ($row['localidade'] ?? '')),
            'inicio' => (string) ($row['vigencia_inicio'] ?? ''),
            'motivo' => (string) ($row['motivo'] ?? ''),
            'percentual_he' => isset($row['percentual_he']) && $row['percentual_he'] !== null
                ? (int) $row['percentual_he']
                : null,
        ];
    }

    private static function limparLocalidade(string $local): string
    {
        $local = trim($local);
        if ($local === '' || $local === '/') {
            return '';
        }

        return $local;
    }
}
