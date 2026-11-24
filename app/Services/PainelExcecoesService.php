<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\AppUrl;
use App\Helpers\DiaUtil;
use App\Helpers\EmailDeliverability;
use App\Helpers\EscopoAcesso;
use App\Helpers\WhatsApp;
use App\Services\EmailOAuth\EmailAccountService;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Painel executivo de exceções e destravamento do fechamento (P0 da Visão Geral).
 * Usa MedicaoService::painel() como única fonte de verdade do funil.
 */
final class PainelExcecoesService
{
    private const MAX_FILA_ITENS = 10;

    private PDO $db;
    private MedicaoService $medicao;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->medicao = new MedicaoService($this->db);
    }

    /**
     * @return array{
     *   periodo:array{data_inicio:string,data_fim:string,label:string},
     *   canal_email:array<string,mixed>,
     *   cobertura:array{esperadas:int,gerados:int,faltantes:int,lista:list<array<string,mixed>>,href:string},
     *   gargalo:array<string,mixed>,
     *   kpis:array<string,array<string,mixed>>,
     *   filas:array<string,array<string,mixed>>,
     *   fila_padrao:string
     * }
     */
    public function carregar(string $dataInicio, string $dataFim, ?string $agora = null): array
    {
        $hoje = ($agora !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $agora))
            ? $agora
            : (new DateTimeImmutable('today'))->format('Y-m-d');

        $painel = $this->medicao->painel($dataInicio, $dataFim);
        $colaboradores = $painel['colaboradores'];
        $resumo = $painel['resumo'];

        $qs = http_build_query([
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
        ]);

        $canalEmail = $this->resolverCanalEmail();
        $semEspelho = $this->listarSemEspelho($dataInicio, $dataFim, $colaboradores);
        $totalAtivosEsperados = count($colaboradores) + count($semEspelho);
        $cobertura = [
            'esperadas' => $totalAtivosEsperados,
            'gerados' => count($colaboradores),
            'faltantes' => count($semEspelho),
            'lista' => $semEspelho,
            'href' => '/rh/ponto?' . $qs . '&filtro=sem_espelho',
        ];

        $divergencias = $this->resolverDivergencias($dataInicio, $dataFim, $qs);
        $heBloqueante = $this->resolverHeBloqueante($dataInicio, $dataFim, $colaboradores, $qs);
        $emailSemEntrega = $this->resolverEmailSemEntrega($colaboradores, $qs);
        $assinaturas = $this->resolverAssinaturasAtraso($dataInicio, $dataFim, $colaboradores, $hoje, $qs);

        $gargalo = $this->resolverGargaloHero(
            $resumo,
            $colaboradores,
            $cobertura,
            $heBloqueante,
            $emailSemEntrega,
            $canalEmail,
            $assinaturas,
            $qs
        );

        $filas = $this->montarFilas(
            $painel,
            $gargalo,
            $divergencias,
            $heBloqueante,
            $emailSemEntrega,
            $assinaturas,
            $qs,
            (string) ($painel['periodo']['label'] ?? '')
        );

        $filaPadrao = $this->escolherFilaPadrao($filas, $divergencias, $heBloqueante, $emailSemEntrega);

        return [
            'periodo' => $painel['periodo'],
            'canal_email' => $canalEmail,
            'cobertura' => $cobertura,
            'gargalo' => $gargalo,
            'kpis' => [
                'divergencias' => $divergencias,
                'he' => $heBloqueante,
                'email' => $emailSemEntrega,
                'assinaturas' => $assinaturas,
            ],
            'filas' => $filas,
            'fila_padrao' => $filaPadrao,
        ];
    }

    /** @return array<string,mixed> */
    private function resolverCanalEmail(): array
    {
        try {
            $svc = new EmailAccountService($this->db);
            $ativa = $svc->contaAtiva();
            $totalContas = count($svc->listarContas());
        } catch (Throwable $e) {
            return [
                'estado' => 'critico',
                'label' => 'Canal de e-mail indisponível',
                'email' => null,
                'provedor_label' => null,
                'detalhe' => 'Não foi possível verificar as contas de envio.',
                'cta_label' => 'Configurar e-mail',
                'href' => '/rh/email',
            ];
        }

        if ($ativa === null) {
            return [
                'estado' => 'critico',
                'label' => 'Nenhuma conta de e-mail ativa',
                'email' => null,
                'provedor_label' => null,
                'detalhe' => $totalContas > 0
                    ? 'Há conta cadastrada, mas nenhuma está conectada para disparo.'
                    : 'Conecte Gmail, Microsoft ou SMTP antes de enviar folhas e cobranças.',
                'cta_label' => 'Conectar e-mail',
                'href' => '/rh/email',
            ];
        }

        $status = (string) ($ativa['status'] ?? 'desconectado');
        $email = (string) ($ativa['email'] ?? '');
        $authMode = (string) ($ativa['auth_mode'] ?? 'oauth');
        $prov = strtoupper((string) ($ativa['provedor'] ?? 'smtp'));
        $provLabel = $authMode === 'smtp' ? $prov . ' · SMTP' : $prov . ' · OAuth';
        $ultimoErro = trim((string) ($ativa['ultimo_erro'] ?? ''));

        $oauthSemRefreshExpirado = false;
        if ($authMode === 'oauth' && !empty($ativa['token_expires_at']) && empty($ativa['refresh_token_enc'])) {
            $expTs = strtotime((string) $ativa['token_expires_at']);
            $oauthSemRefreshExpirado = $expTs !== false && $expTs <= time();
        }

        if ($status !== 'conectado' || $ultimoErro !== '' || $oauthSemRefreshExpirado) {
            $detalhe = $ultimoErro !== ''
                ? $ultimoErro
                : ($status === 'expirado' || $oauthSemRefreshExpirado
                    ? 'Sessão expirada — reconecte para não travar a régua.'
                    : 'Conta com falha de autenticação.');
            return [
                'estado' => 'critico',
                'label' => 'Falha no canal de e-mail',
                'email' => $email,
                'provedor_label' => $provLabel,
                'detalhe' => $detalhe,
                'cta_label' => 'Reconectar agora',
                'href' => '/rh/email',
            ];
        }

        return [
            'estado' => 'ok',
            'label' => 'E-mail ativo',
            'email' => $email,
            'provedor_label' => $provLabel,
            'detalhe' => 'Envio e cobranças operacionais.',
            'cta_label' => 'Gerenciar',
            'href' => '/rh/email',
        ];
    }

    /**
     * Colaboradores ativos sem espelho no período selecionado.
     *
     * @param list<array<string,mixed>> $espelhosPainel
     * @return list<array<string,mixed>>
     */
    private function listarSemEspelho(string $dataInicio, string $dataFim, array $espelhosPainel): array
    {
        $comEspelho = [];
        foreach ($espelhosPainel as $row) {
            $comEspelho[(int) $row['colaborador_id']] = true;
        }
        return self::listarColaboradoresSemEspelho($this->db, $dataInicio, $dataFim, $comEspelho);
    }

    /**
     * @param array<int,bool> $comEspelho Mapa [colaborador_id => true] dos já gerados no período
     * @return list<array<string,mixed>>
     */
    public static function listarColaboradoresSemEspelho(
        PDO $db,
        string $dataInicio,
        string $dataFim,
        array $comEspelho
    ): array {
        $escopo = EscopoAcesso::sqlFiltroColaborador('c');
        $st = $db->prepare(
            "SELECT c.id, c.matricula, c.nome, l.codigo AS lotacao_codigo, loc.municipio, loc.uf
             FROM rh_colaboradores c
             LEFT JOIN rh_colaborador_lotacoes cl ON cl.id = (
                 SELECT vinculo.id FROM rh_colaborador_lotacoes vinculo
                 WHERE vinculo.colaborador_id = c.id AND vinculo.vigencia_inicio <= :fim
                   AND (vinculo.vigencia_fim IS NULL OR vinculo.vigencia_fim >= :inicio)
                 ORDER BY vinculo.vigencia_inicio DESC, vinculo.id DESC LIMIT 1
             )
             LEFT JOIN rh_lotacoes l ON l.id = cl.lotacao_id
             LEFT JOIN rh_localidades loc ON loc.id = l.localidade_id
             WHERE c.status = 'ativo'"
            . $escopo['sql']
            . ' ORDER BY c.nome, c.id'
        );
        $st->execute(array_merge(['inicio' => $dataInicio, 'fim' => $dataFim], $escopo['params']));

        $faltantes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $cid = (int) $row['id'];
            if (isset($comEspelho[$cid])) {
                continue;
            }
            $faltantes[] = [
                'id' => $cid,
                'matricula' => (string) $row['matricula'],
                'nome' => (string) $row['nome'],
                'lotacao_codigo' => $row['lotacao_codigo'] !== null ? (string) $row['lotacao_codigo'] : null,
                'municipio' => $row['municipio'] !== null ? (string) $row['municipio'] : null,
                'uf' => $row['uf'] !== null ? (string) $row['uf'] : null,
            ];
        }
        return $faltantes;
    }

    /** @return array<string,mixed> */
    private function resolverDivergencias(string $dataInicio, string $dataFim, string $qs): array
    {
        $escopo = EscopoAcesso::sqlFiltroColaborador('c');
        $rowsPeriodo = [];
        $outrasPeriodos = 0;

        try {
            $st = $this->db->prepare(
                "SELECT d.id, d.espelho_id, d.data_ocorrencia, d.motivo, d.created_at,
                        c.nome AS colaborador_nome, c.matricula,
                        p.data_inicio, p.data_fim
                 FROM ponto_divergencias d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN ponto_periodos p ON p.id = e.periodo_id
                 INNER JOIN rh_colaboradores c ON c.id = d.colaborador_id
                 WHERE d.status IN ('aberta', 'em_analise')
                   AND p.data_inicio = :ini
                   AND p.data_fim = :fim"
                . $escopo['sql']
                . ' ORDER BY d.created_at ASC, d.id ASC'
            );
            $st->execute(array_merge(['ini' => $dataInicio, 'fim' => $dataFim], $escopo['params']));
            $rowsPeriodo = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

            $stOutras = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM ponto_divergencias d
                 INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
                 INNER JOIN ponto_periodos p ON p.id = e.periodo_id
                 INNER JOIN rh_colaboradores c ON c.id = d.colaborador_id
                 WHERE d.status IN ('aberta', 'em_analise')
                   AND (p.data_inicio <> :ini OR p.data_fim <> :fim)"
                . $escopo['sql']
            );
            $stOutras->execute(array_merge(['ini' => $dataInicio, 'fim' => $dataFim], $escopo['params']));
            $outrasPeriodos = (int) $stOutras->fetchColumn();
        } catch (Throwable) {
            $rowsPeriodo = [];
            $outrasPeriodos = 0;
        }

        $itensPeriodo = [];
        $agoraTs = time();

        foreach ($rowsPeriodo as $div) {
            $createdTs = !empty($div['created_at']) ? (strtotime((string) $div['created_at']) ?: $agoraTs) : $agoraTs;
            $horas = max(0, (int) floor(($agoraTs - $createdTs) / 3600));
            $dias = (int) floor($horas / 24);
            $idadeLabel = $dias >= 1 ? ($dias . 'd') : ($horas >= 1 ? ($horas . 'h') : 'Agora');
            $critico = $horas >= 48;

            $itensPeriodo[] = [
                'id' => (int) ($div['id'] ?? 0),
                'espelho_id' => (int) ($div['espelho_id'] ?? 0),
                'colaborador_nome' => (string) ($div['colaborador_nome'] ?? ''),
                'matricula' => (string) ($div['matricula'] ?? ''),
                'motivo' => (string) ($div['motivo'] ?? ''),
                'data_ocorrencia' => $div['data_ocorrencia'] ?? null,
                'data_inicio' => (string) ($div['data_inicio'] ?? ''),
                'data_fim' => (string) ($div['data_fim'] ?? ''),
                'idade_horas' => $horas,
                'idade_dias' => $dias,
                'idade_label' => $idadeLabel,
                'sla_critico' => $critico,
            ];
        }

        // Mais antigas primeiro (maior risco SLA no topo)
        usort($itensPeriodo, static fn (array $a, array $b): int => $b['idade_horas'] <=> $a['idade_horas']);

        $totalPeriodo = count($itensPeriodo);
        $criticas = count(array_filter($itensPeriodo, static fn (array $i): bool => $i['sla_critico']));
        $maiorIdade = $itensPeriodo !== [] ? (string) $itensPeriodo[0]['idade_label'] : null;

        $href = $totalPeriodo === 1
            ? '/rh/ponto/' . (int) $itensPeriodo[0]['espelho_id'] . '?' . $qs
            : '/rh/ponto?' . $qs . '&filtro=divergencia';

        return [
            'total' => $totalPeriodo,
            'criticas' => $criticas,
            'maior_idade' => $maiorIdade,
            'outras_periodos' => $outrasPeriodos,
            'itens' => $itensPeriodo,
            'tom' => $criticas > 0 ? 'danger' : ($totalPeriodo > 0 ? 'warning' : 'ok'),
            'href' => $href,
            'cta_label' => $totalPeriodo === 1 ? 'Abrir ponto' : 'Ver revisões',
        ];
    }

    /**
     * @param list<array<string,mixed>> $colaboradores
     * @return array<string,mixed>
     */
    private function resolverHeBloqueante(
        string $dataInicio,
        string $dataFim,
        array $colaboradores,
        string $qs
    ): array {
        $detalhes = [];
        $totalDias = 0;
        $totalMin = 0;

        $espelhoIds = [];
        foreach ($colaboradores as $c) {
            if (empty($c['he_pendente'])) {
                continue;
            }
            $eid = (int) ($c['espelho_id'] ?? 0);
            if ($eid > 0) {
                $espelhoIds[$eid] = $eid;
            }
        }

        if ($espelhoIds !== [] && $this->hasColumn('ra_dias', 'he_status')) {
            try {
                $in = implode(',', array_values($espelhoIds));
                $st = $this->db->prepare(
                    "SELECT e.id AS espelho_id, d.data, d.he_hora_inicio, d.he_hora_fim
                     FROM ponto_espelhos e
                     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                     INNER JOIN ra_relatorios r
                        ON r.colaborador_id = e.colaborador_id AND r.periodo_id = e.periodo_id
                     INNER JOIN ra_dias d ON d.relatorio_id = r.id
                     WHERE pp.data_inicio = :ini AND pp.data_fim = :fim
                       AND e.id IN ({$in})
                       AND d.he_status = 'declarada'
                     ORDER BY d.data"
                );
                $st->execute(['ini' => $dataInicio, 'fim' => $dataFim]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $eid = (int) $row['espelho_id'];
                    $min = $this->diffMinutos(
                        (string) ($row['he_hora_inicio'] ?? ''),
                        (string) ($row['he_hora_fim'] ?? '')
                    );
                    $detalhes[$eid] ??= ['qtd_dias' => 0, 'minutos' => 0, 'primeira_data' => (string) $row['data']];
                    $detalhes[$eid]['qtd_dias']++;
                    $detalhes[$eid]['minutos'] += $min;
                }
            } catch (Throwable) {
                $detalhes = [];
            }
        }

        $itens = [];
        foreach ($colaboradores as $c) {
            if (empty($c['he_pendente'])) {
                continue;
            }
            $eid = (int) $c['espelho_id'];
            $temHeDeclarada = isset($detalhes[$eid]) && (int) $detalhes[$eid]['qtd_dias'] > 0;
            $qtdDias = $temHeDeclarada ? (int) $detalhes[$eid]['qtd_dias'] : 0;
            $minutos = $temHeDeclarada ? (int) $detalhes[$eid]['minutos'] : 0;
            $totalDias += $qtdDias;
            $totalMin += $minutos;

            $itens[] = [
                'espelho_id' => $eid,
                'colaborador_id' => (int) $c['colaborador_id'],
                'nome' => (string) $c['nome'],
                'matricula' => (string) $c['matricula'],
                'motivo_bloqueio' => $temHeDeclarada ? 'he_declarada' : 'anexo_pendente',
                'qtd_dias' => $qtdDias,
                'minutos' => $minutos,
                'horas_fmt' => $minutos > 0 ? $this->fmtMin($minutos) : null,
            ];
        }

        $total = count($itens);
        return [
            'total' => $total,
            'total_dias' => $totalDias,
            'horas_fmt' => $totalMin > 0 ? $this->fmtMin($totalMin) : null,
            'itens' => $itens,
            'tom' => $total > 0 ? 'warning' : 'ok',
            'href' => '/rh/medicao?' . $qs . '&filtro=conferir&subfiltro=he_bloqueante',
            'cta_label' => 'Conferir HE',
        ];
    }

    /**
     * Diagnostica apenas quem está retido antes/durante a Etapa 6 (e-mail).
     * Colaboradores que já venceram a Etapa 6 (etapas['email'] === true ou folha assinada) não entram.
     *
     * @param array<string,mixed> $c
     * @return array{tipo:string,badge:string,motivo:string}|null
     */
    public static function diagnosticarFalhaEmail(array $c): ?array
    {
        if (!empty($c['concluido']) || !empty($c['etapas']['email']) || !empty($c['etapas']['assinaturas'])) {
            return null;
        }
        $temLink = !empty($c['etapas']['link']);
        $ultimoSt = (string) ($c['email_ultimo_status'] ?? '');
        if (!$temLink && $ultimoSt !== 'falha') {
            return null;
        }

        $email = trim((string) ($c['email_corp'] ?? ''));
        $ultimoErro = trim((string) ($c['email_ultimo_erro'] ?? ''));

        if ($email === '') {
            return [
                'tipo' => 'sem_email',
                'badge' => 'Sem e-mail',
                'motivo' => 'Sem e-mail corporativo cadastrado',
            ];
        }
        if ($ultimoSt === 'falha') {
            $motivo = $ultimoErro !== '' ? $ultimoErro : 'Falha no último disparo SMTP';
            if (!EmailDeliverability::isDeliverable($email)) {
                $motivo .= ' (' . $email . ' não entregável)';
            }
            return [
                'tipo' => 'falha',
                'badge' => 'Falha no envio',
                'motivo' => $motivo,
            ];
        }
        if (!EmailDeliverability::isDeliverable($email)) {
            return [
                'tipo' => 'invalido',
                'badge' => 'Domínio inválido',
                'motivo' => 'Domínio não entregável (' . $email . ')',
            ];
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $colaboradores
     * @return array<string,mixed>
     */
    private function resolverEmailSemEntrega(array $colaboradores, string $qs): array
    {
        $itens = [];
        $qtdFalha = 0;
        $qtdSemEmail = 0;
        $qtdInvalido = 0;

        foreach ($colaboradores as $c) {
            $diag = self::diagnosticarFalhaEmail($c);
            if ($diag === null) {
                continue;
            }
            match ($diag['tipo']) {
                'falha' => $qtdFalha++,
                'sem_email' => $qtdSemEmail++,
                default => $qtdInvalido++,
            };
            $itens[] = [
                'espelho_id' => (int) $c['espelho_id'],
                'colaborador_id' => (int) $c['colaborador_id'],
                'nome' => (string) $c['nome'],
                'matricula' => (string) $c['matricula'],
                'email' => trim((string) ($c['email_corp'] ?? '')),
                'tipo' => $diag['tipo'],
                'badge' => $diag['badge'],
                'motivo' => $diag['motivo'],
                'etapa_email_ok' => !empty($c['etapas']['email']),
                'tem_link' => !empty($c['etapas']['link']),
                'telefone' => $c['telefone'] ?? null,
                'portal_url' => $c['portal_url'] ?? null,
            ];
        }

        $total = count($itens);
        return [
            'total' => $total,
            'falhas' => $qtdFalha,
            'sem_email' => $qtdSemEmail,
            'invalidos' => $qtdInvalido,
            'itens' => $itens,
            'tom' => $total > 0 ? 'danger' : 'ok',
            'href' => '/rh/medicao?' . $qs . '&filtro=email&subfiltro=email_falha',
            'cta_label' => 'Corrigir envios',
        ];
    }

    /**
     * @param list<array<string,mixed>> $colaboradores
     * @return array<string,mixed>
     */
    private function resolverAssinaturasAtraso(
        string $dataInicio,
        string $dataFim,
        array $colaboradores,
        string $hoje,
        string $qs
    ): array {
        $du1 = $dataFim;
        $du2 = $dataFim;
        try {
            $du1 = DiaUtil::nApos($dataFim, 1, $this->db);
            $du2 = DiaUtil::nApos($dataFim, 2, $this->db);
        } catch (Throwable) {
        }

        $fase = match (true) {
            $hoje <= $dataFim => 'aberto',
            $hoje < $du1 => 'aguardando_du1',
            $hoje === $du1 => 'du1',
            $hoje === $du2 => 'du2',
            default => 'vencido',
        };
        $emJanelaAtraso = in_array($fase, ['du1', 'du2', 'vencido'], true);

        $cobrancaPorDoc = $this->carregarNiveisCobranca($dataInicio, $dataFim);
        $relatorioIds = array_values(array_unique(array_filter(array_map(
            static fn (array $c): int => (int) ($c['relatorio_id'] ?? 0),
            $colaboradores
        ))));
        $raDocsPorRelatorio = $this->carregarRaDocsPorRelatorio($relatorioIds);
        $baseUrl = rtrim(AppUrl::base(dirname(__DIR__, 2)), '/');
        $itens = [];
        $folhasPendentes = 0;
        $rasPendentesAssinatura = 0;
        $rasRascunho = 0;
        $nivelMax = 0;

        foreach ($colaboradores as $c) {
            // Etapa 7 na Medição: só entra quem já concluiu Etapa 6 (email ok ou folha assinada) e ainda não fechou Folha+RA.
            if (!empty($c['concluido']) || empty($c['etapas']['email']) || !empty($c['etapas']['assinaturas'])) {
                continue;
            }
            $folhaAssinada = ($c['folha_status'] ?? '') === 'assinado';
            $raAssinado = ($c['ra_doc_status'] ?? '') === 'assinado';
            $raStatus = (string) ($c['ra_status'] ?? 'rascunho');
            $raEmRascunho = in_array($raStatus, ['rascunho', ''], true);

            if (!$folhaAssinada) {
                $folhasPendentes++;
            }
            if (!$raAssinado) {
                if ($raEmRascunho) {
                    $rasRascunho++;
                } else {
                    $rasPendentesAssinatura++;
                }
            }

            $espelhoId = (int) $c['espelho_id'];
            $docId = (int) ($c['documento_id'] ?? 0);
            $relId = (int) ($c['relatorio_id'] ?? 0);
            $raDoc = $relId > 0 ? ($raDocsPorRelatorio[$relId] ?? null) : null;
            $raDocId = is_array($raDoc) ? (int) ($raDoc['id'] ?? 0) : 0;
            $raToken = is_array($raDoc) ? trim((string) ($raDoc['portal_token'] ?? '')) : '';
            $raPortalUrl = (!$raEmRascunho && $raToken !== '')
                ? ($baseUrl . '/assinar-relatorio/' . $raToken)
                : null;

            $nivelCobranca = 0;
            if (!$folhaAssinada && $docId > 0) {
                $nivelCobranca = max($nivelCobranca, (int) ($cobrancaPorDoc['folha:' . $docId] ?? 0));
            }
            if (!$raAssinado) {
                if ($raEmRascunho) {
                    $chaveRaPreench = $relId > 0 ? $relId : $espelhoId;
                    if ($chaveRaPreench > 0) {
                        $nivelCobranca = max(
                            $nivelCobranca,
                            (int) ($cobrancaPorDoc['ra_preenchimento:' . $chaveRaPreench] ?? 0)
                        );
                    }
                } elseif ($raDocId > 0) {
                    $nivelCobranca = max($nivelCobranca, (int) ($cobrancaPorDoc['ra:' . $raDocId] ?? 0));
                }
            }
            $nivelMax = max($nivelMax, $nivelCobranca);

            $pendencias = [];
            if (!$folhaAssinada) {
                $pendencias[] = 'Folha';
            }
            if (!$raAssinado) {
                $pendencias[] = $raEmRascunho ? 'RA (rascunho)' : 'RA';
            }

            $itens[] = [
                'espelho_id' => $espelhoId,
                'colaborador_id' => (int) $c['colaborador_id'],
                'nome' => (string) $c['nome'],
                'matricula' => (string) $c['matricula'],
                'relatorio_id' => $c['relatorio_id'] ?? null,
                'ra_doc_id' => $raDocId > 0 ? $raDocId : null,
                'folha_assinada' => $folhaAssinada,
                'ra_assinado' => $raAssinado,
                'ra_status' => $raStatus,
                'pendencia_label' => implode(' + ', $pendencias),
                'nivel_cobranca' => $nivelCobranca,
                'telefone' => $c['telefone'] ?? null,
                'portal_url' => $c['portal_url'] ?? null,
                'ra_portal_url' => $raPortalUrl,
                'em_atraso' => $emJanelaAtraso,
            ];
        }

        $totalPendentes = count($itens);
        $tom = $totalPendentes === 0
            ? 'ok'
            : ($fase === 'vencido' || $fase === 'du2' ? 'danger' : ($emJanelaAtraso ? 'warning' : 'neutral'));

        return [
            'total' => $totalPendentes,
            'em_atraso' => $emJanelaAtraso ? $totalPendentes : 0,
            'fase' => $fase,
            'du1_label' => DiaUtil::formatBr($du1),
            'du2_label' => DiaUtil::formatBr($du2),
            'folhas_pendentes' => $folhasPendentes,
            'ras_pendentes' => $rasPendentesAssinatura,
            'ras_rascunho' => $rasRascunho,
            'nivel_max' => $nivelMax,
            'itens' => $itens,
            'tom' => $tom,
            'href' => '/rh/medicao?' . $qs . '&filtro=assinaturas',
            'cta_label' => 'Ver assinaturas',
        ];
    }

    /** @return array<string,int> */
    private function carregarNiveisCobranca(string $dataInicio, string $dataFim): array
    {
        if (!$this->tableExists('cobranca_envios')) {
            return [];
        }
        try {
            $st = $this->db->prepare(
                "SELECT ce.tipo, ce.documento_id, MAX(ce.nivel) AS max_nivel
                 FROM cobranca_envios ce
                 INNER JOIN ponto_periodos pp ON pp.id = ce.periodo_id
                 WHERE pp.data_inicio = :ini AND pp.data_fim = :fim AND ce.status = 'enviado'
                 GROUP BY ce.tipo, ce.documento_id"
            );
            $st->execute(['ini' => $dataInicio, 'fim' => $dataFim]);
            $map = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $map[(string) $row['tipo'] . ':' . (int) $row['documento_id']] = (int) $row['max_nivel'];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Carrega o documento de RA mais recente (não invalidado) por relatorio_id.
     *
     * @param list<int> $relatorioIds
     * @return array<int,array{id:int,portal_token:string}>
     */
    private function carregarRaDocsPorRelatorio(array $relatorioIds): array
    {
        if ($relatorioIds === [] || !$this->tableExists('ra_documentos')) {
            return [];
        }
        try {
            $in = implode(',', array_map('intval', $relatorioIds));
            $st = $this->db->query(
                "SELECT id, relatorio_id, portal_token
                 FROM ra_documentos
                 WHERE relatorio_id IN ({$in})
                   AND status <> 'invalidado'
                 ORDER BY id DESC"
            );
            $map = [];
            foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
                $rid = (int) $row['relatorio_id'];
                if (isset($map[$rid])) {
                    continue;
                }
                $map[$rid] = [
                    'id' => (int) $row['id'],
                    'portal_token' => (string) ($row['portal_token'] ?? ''),
                ];
            }
            return $map;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $resumo
     * @param list<array<string,mixed>> $colaboradores
     * @param array<string,mixed> $cobertura
     * @param array<string,mixed> $heBloqueante
     * @param array<string,mixed> $emailSemEntrega
     * @param array<string,mixed> $canalEmail
     * @param array<string,mixed> $assinaturas
     * @return array<string,mixed>
     */
    private function resolverGargaloHero(
        array $resumo,
        array $colaboradores,
        array $cobertura,
        array $heBloqueante,
        array $emailSemEntrega,
        array $canalEmail,
        array $assinaturas,
        string $qs
    ): array {
        $total = (int) ($resumo['total'] ?? 0);
        $concluidos = (int) ($resumo['concluidos'] ?? 0);
        $pct = (int) ($resumo['percentual'] ?? 0);
        $pronto = !empty($resumo['pronto_dossie']);
        $elegiveis = is_array($resumo['elegiveis'] ?? null) ? $resumo['elegiveis'] : [];
        $proxima = $resumo['proxima_acao'] ?? null;
        $feitos = is_array($resumo['feitos'] ?? null) ? $resumo['feitos'] : [];

        $barra = [];
        foreach (MedicaoService::ETAPAS as $idx => $etapa) {
            $key = (string) $etapa['key'];
            $qtdFeito = (int) ($feitos[$key] ?? 0);
            $barra[] = [
                'num' => $idx + 1,
                'key' => $key,
                'label' => preg_replace('/^\d+\.\s*/', '', (string) $etapa['label']) ?? $key,
                'feitos' => $qtdFeito,
                'pct' => $total > 0 ? (int) round(($qtdFeito / $total) * 100) : 0,
            ];
        }

        if ($total === 0) {
            $faltantes = (int) $cobertura['faltantes'];
            return $this->montarHeroPayload([
                'chave' => 'sem_espelho',
                'kicker' => 'Etapa inicial',
                'titulo' => 'Nenhum cartão gerado neste período',
                'subtitulo' => $faltantes > 0
                    ? $faltantes . ' colaborador(es) ativo(s) aguardam geração de espelho.'
                    : 'Selecione outro período ou gere os espelhos em Gestão de Ponto.',
                'quantidade' => $faltantes,
                'metric_label' => 'espelhos a gerar',
                'progresso_label' => null,
                'cta_label' => 'Gerar cartões no Ponto',
                'cta_href' => '/rh/ponto?' . $qs,
                'tom' => 'brand',
                'total' => 0,
                'concluidos' => 0,
                'pct' => 0,
                'pronto_dossie' => false,
                'etapa_atual' => null,
            ], $barra);
        }

        if ($pronto) {
            return $this->montarHeroPayload([
                'chave' => 'dossie',
                'kicker' => 'Pronto para medição',
                'titulo' => 'Dossiê 100% concluído',
                'subtitulo' => 'Todos os ' . $total . ' colaboradores concluíram cartão de ponto e RA assinados.',
                'quantidade' => $concluidos,
                'metric_label' => 'de ' . $total . ' prontos para o dossiê',
                'progresso_label' => '6 etapas concluídas · 100%',
                'cta_label' => 'Baixar ZIP do Dossiê',
                'cta_href' => '/rh/medicao/dossie?' . $qs,
                'secondary_href' => '/rh/medicao?' . $qs,
                'tom' => 'success',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => 100,
                'pronto_dossie' => true,
                'etapa_atual' => 'assinaturas',
            ], $barra);
        }

        $mapaAcao = [
            'conferir' => [
                'kicker' => 'Gargalo atual · Etapa 2',
                'titulo' => 'Conferir cartões pendentes',
                'cta' => 'Conferir na Medição',
                'feito_label' => 'já conferidos',
                'pendente_label' => 'ainda nesta etapa',
            ],
            'fechar' => [
                'kicker' => 'Gargalo atual · Etapa 3',
                'titulo' => 'Fechar espelhos conferidos',
                'cta' => 'Fechar na Medição',
                'feito_label' => 'já fechados',
                'pendente_label' => 'ainda nesta etapa',
            ],
            'pdf' => [
                'kicker' => 'Gargalo atual · Etapa 4',
                'titulo' => 'Emitir PDFs e links das folhas',
                'cta' => 'Gerar PDFs e links',
                'feito_label' => 'já com PDF e link',
                'pendente_label' => 'ainda nesta etapa',
            ],
            'link' => [
                'kicker' => 'Gargalo atual · Etapa 4',
                'titulo' => 'Emitir PDFs e links das folhas',
                'cta' => 'Gerar PDFs e links',
                'feito_label' => 'já com PDF e link',
                'pendente_label' => 'ainda nesta etapa',
            ],
            'email' => [
                'kicker' => 'Gargalo atual · Etapa 5',
                'titulo' => 'Enviar folhas por e-mail',
                'cta' => 'Enviar e-mails',
                'feito_label' => 'já enviados',
                'pendente_label' => 'ainda nesta etapa',
            ],
        ];

        if (is_string($proxima) && isset($mapaAcao[$proxima]) && (int) ($elegiveis[$proxima] ?? 0) > 0) {
            $qtdElegiveis = (int) $elegiveis[$proxima];
            $feitosEtapa = (int) ($feitos[$proxima] ?? 0);
            $naFilaEtapa = count(array_filter(
                $colaboradores,
                fn (array $c): bool => $this->pertenceAoGargalo($c, $proxima)
            ));

            $prontos = $qtdElegiveis;
            $bloqueados = 0;
            $bloqueioNota = null;
            $bloqueioHref = null;
            $ctaSecLabel = null;
            $ctaSecHref = null;
            $splits = [];

            if ($proxima === 'email') {
                $contagemEmail = $this->contarFilaEmail($colaboradores);
                $prontos = $contagemEmail['prontos'];
                $bloqueados = $contagemEmail['bloqueados'];
                $naFilaEtapa = $contagemEmail['na_fila'];
                $qtdElegiveis = max($prontos, 0);

                $splits = [
                    [
                        'valor' => $prontos,
                        'label' => 'prontos para envio',
                        'tom' => $prontos > 0 ? 'ok' : 'muted',
                    ],
                    [
                        'valor' => $bloqueados,
                        'label' => 'bloqueados por e-mail',
                        'tom' => $bloqueados > 0 ? 'danger' : 'muted',
                    ],
                ];

                if ($bloqueados > 0) {
                    $bloqueioNota = $bloqueados . ' não podem receber e-mail (sem cadastro, domínio inválido ou falha de envio).';
                    $bloqueioHref = (string) $emailSemEntrega['href'];
                }
            } else {
                if ($proxima !== 'conferir' && (int) $heBloqueante['total'] > 0) {
                    $bloqueados = (int) $heBloqueante['total'];
                    $bloqueioNota = $bloqueados . ' ainda bloqueados por HE no RA — resolva na Etapa 2.';
                    $bloqueioHref = (string) $heBloqueante['href'];
                    $ctaSecLabel = 'Revisar HE (' . $bloqueados . ')';
                    $ctaSecHref = $bloqueioHref;
                } elseif ((int) $emailSemEntrega['total'] > 0) {
                    $bloqueados = (int) $emailSemEntrega['total'];
                    $bloqueioNota = $bloqueados . ' terão problema na Etapa 5 (e-mail inválido ou falha).';
                    $bloqueioHref = (string) $emailSemEntrega['href'];
                    $ctaSecLabel = 'Corrigir e-mails (' . $bloqueados . ')';
                    $ctaSecHref = $bloqueioHref;
                }

                if ($naFilaEtapa > 0) {
                    $splits = [
                        [
                            'valor' => $prontos,
                            'label' => 'prontos para esta ação',
                            'tom' => $prontos > 0 ? 'ok' : 'muted',
                        ],
                        [
                            'valor' => max(0, $naFilaEtapa - $prontos),
                            'label' => 'ainda retidos antes desta etapa',
                            'tom' => ($naFilaEtapa - $prontos) > 0 ? 'warning' : 'muted',
                        ],
                    ];
                }
            }

            $meta = $mapaAcao[$proxima];
            $faltamEtapa = max(0, $total - $feitosEtapa);
            $progressoLabel = $feitosEtapa . ' de ' . $total . ' ' . $meta['feito_label']
                . ' · faltam ' . $faltamEtapa;

            $canalOk = ($canalEmail['estado'] ?? '') === 'ok';
            $priorizarBloqueio = $proxima === 'email' && $bloqueados > 0 && $prontos === 0;
            $priorizarBloqueio = $priorizarBloqueio || ($proxima === 'email' && $bloqueados > $prontos && !$canalOk);

            if ($proxima === 'email' && !$canalOk) {
                $ctaLabel = 'Revisar canal de e-mail';
                $ctaHref = (string) ($canalEmail['href'] ?? '/rh/email');
                $quantidade = $naFilaEtapa;
                $metricLabel = $faltamEtapa . ' ainda nesta etapa';
            } elseif ($priorizarBloqueio || ($proxima === 'email' && $bloqueados > 0 && $prontos === 0)) {
                $ctaLabel = 'Corrigir e-mails (' . $bloqueados . ')';
                $ctaHref = (string) $emailSemEntrega['href'];
                $quantidade = $bloqueados;
                $metricLabel = 'bloqueados — sem envio possível';
                if ($prontos > 0) {
                    $ctaSecLabel = 'Enviar os prontos (' . $prontos . ')';
                    $ctaSecHref = '/rh/medicao?' . $qs . '&filtro=email';
                }
            } elseif ($proxima === 'email') {
                $ctaLabel = 'Enviar os prontos (' . $prontos . ')';
                $ctaHref = '/rh/medicao?' . $qs . '&filtro=email';
                $quantidade = $prontos > 0 ? $prontos : $naFilaEtapa;
                $metricLabel = $prontos > 0
                    ? 'prontos para envio agora'
                    : $meta['pendente_label'];
                if ($bloqueados > 0) {
                    $ctaSecLabel = 'Corrigir e-mails (' . $bloqueados . ')';
                    $ctaSecHref = (string) $emailSemEntrega['href'];
                }
            } else {
                $ctaLabel = $meta['cta'] . ' (' . $prontos . ')';
                $ctaHref = '/rh/medicao?' . $qs . '&filtro=' . $proxima;
                $quantidade = $prontos > 0 ? $prontos : $naFilaEtapa;
                $metricLabel = $prontos > 0
                    ? 'prontos para esta ação'
                    : $meta['pendente_label'];
            }

            $subtitulo = $proxima === 'email'
                ? ($prontos . ' podem ser enviados agora'
                    . ($bloqueados > 0 ? ' · ' . $bloqueados . ' precisam corrigir e-mail' : '')
                    . ' · ' . $concluidos . ' já com dossiê completo.')
                : ($prontos . ' prontos para a ação'
                    . ($bloqueados > 0 ? ' · ' . $bloqueados . ' com bloqueio paralelo' : '')
                    . ' · ' . $concluidos . ' já com dossiê completo.');

            return $this->montarHeroPayload([
                'chave' => $proxima,
                'kicker' => $meta['kicker'],
                'titulo' => $meta['titulo'],
                'subtitulo' => $subtitulo,
                'quantidade' => $quantidade,
                'metric_label' => $metricLabel,
                'progresso_label' => $progressoLabel,
                'splits' => $splits,
                'cta_label' => $ctaLabel,
                'cta_href' => $ctaHref,
                'cta_secundario_label' => $ctaSecLabel,
                'cta_secundario_href' => $ctaSecHref,
                'secondary_href' => '/rh/medicao?' . $qs,
                'bloqueio_nota' => $bloqueioNota,
                'bloqueio_href' => $bloqueioHref,
                'tom' => ($proxima === 'email' && $bloqueados > $prontos) ? 'danger' : 'brand',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => $pct,
                'pronto_dossie' => false,
                'etapa_atual' => $proxima,
            ], $barra);
        }

        if ((int) $heBloqueante['total'] > 0) {
            $qtd = (int) $heBloqueante['total'];
            $feitosEtapa = (int) ($feitos['conferir'] ?? 0);
            return $this->montarHeroPayload([
                'chave' => 'he',
                'kicker' => 'Bloqueio na Etapa 2',
                'titulo' => 'Aplicar HE Cliente para destravar',
                'subtitulo' => $qtd . ' colaborador(es) com hora extra no RA impedindo a conferência.',
                'quantidade' => $qtd,
                'metric_label' => 'bloqueados por HE',
                'progresso_label' => $feitosEtapa . ' de ' . $total . ' já conferidos · faltam '
                    . max(0, $total - $feitosEtapa),
                'cta_label' => 'Revisar HE (' . $qtd . ')',
                'cta_href' => (string) $heBloqueante['href'],
                'secondary_href' => '/rh/medicao?' . $qs,
                'tom' => 'warning',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => $pct,
                'pronto_dossie' => false,
                'etapa_atual' => 'conferir',
            ], $barra);
        }

        if ((int) $emailSemEntrega['total'] > 0) {
            $qtd = (int) $emailSemEntrega['total'];
            $feitosEtapa = (int) ($feitos['email'] ?? 0);
            $contagemEmail = $this->contarFilaEmail($colaboradores);
            return $this->montarHeroPayload([
                'chave' => 'email',
                'kicker' => 'Bloqueio na Etapa 5',
                'titulo' => 'Corrigir e-mails que não entregam',
                'subtitulo' => $qtd . ' colaborador(es) sem e-mail válido ou com falha de disparo.',
                'quantidade' => $qtd,
                'metric_label' => 'bloqueados — sem envio possível',
                'progresso_label' => $feitosEtapa . ' de ' . $total . ' já enviados · faltam '
                    . max(0, $total - $feitosEtapa),
                'splits' => [
                    [
                        'valor' => $contagemEmail['prontos'],
                        'label' => 'prontos para envio',
                        'tom' => $contagemEmail['prontos'] > 0 ? 'ok' : 'muted',
                    ],
                    [
                        'valor' => $qtd,
                        'label' => 'bloqueados por e-mail',
                        'tom' => 'danger',
                    ],
                ],
                'cta_label' => 'Corrigir e-mails (' . $qtd . ')',
                'cta_href' => (string) $emailSemEntrega['href'],
                'cta_secundario_label' => $contagemEmail['prontos'] > 0
                    ? 'Enviar os prontos (' . $contagemEmail['prontos'] . ')'
                    : null,
                'cta_secundario_href' => $contagemEmail['prontos'] > 0
                    ? '/rh/medicao?' . $qs . '&filtro=email'
                    : null,
                'secondary_href' => '/rh/medicao?' . $qs,
                'bloqueio_nota' => $qtd . ' não podem receber e-mail até o cadastro/domínio ser corrigido.',
                'tom' => 'danger',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => $pct,
                'pronto_dossie' => false,
                'etapa_atual' => 'email',
            ], $barra);
        }

        $bloqueadosDivergencia = 0;
        $bloqueadosBatidas = 0;
        foreach ($colaboradores as $c) {
            if (!empty($c['concluido'])) {
                continue;
            }
            if (!empty($c['em_divergencia'])) {
                $bloqueadosDivergencia++;
            } elseif (!empty($c['batidas_pendentes']) && empty($c['etapas']['fechado'])) {
                $bloqueadosBatidas++;
            }
        }

        if ($bloqueadosDivergencia > 0) {
            $feitosEtapa = (int) ($feitos['tratado'] ?? 0);
            return $this->montarHeroPayload([
                'chave' => 'divergencias',
                'kicker' => 'Bloqueio na Etapa 2',
                'titulo' => 'Resolver divergências de ponto',
                'subtitulo' => $bloqueadosDivergencia . ' colaborador(es) com revisão de ponto aberta impedindo conferência e fechamento.',
                'quantidade' => $bloqueadosDivergencia,
                'metric_label' => 'em divergência aberta',
                'progresso_label' => $feitosEtapa . ' de ' . $total . ' já conferidos · faltam '
                    . max(0, $total - $feitosEtapa),
                'cta_label' => 'Ver revisões (' . $bloqueadosDivergencia . ')',
                'cta_href' => '/rh/ponto?' . $qs . '&filtro=divergencia',
                'secondary_href' => '/rh/medicao?' . $qs,
                'tom' => 'danger',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => $pct,
                'pronto_dossie' => false,
                'etapa_atual' => 'tratado',
            ], $barra);
        }

        if ($bloqueadosBatidas > 0) {
            $feitosEtapa = (int) ($feitos['tratado'] ?? 0);
            return $this->montarHeroPayload([
                'chave' => 'batidas_pendentes',
                'kicker' => 'Bloqueio na Etapa 2',
                'titulo' => 'Regularizar batidas pendentes',
                'subtitulo' => $bloqueadosBatidas . ' colaborador(es) com dias úteis sem marcação completa impedindo conferência e fechamento.',
                'quantidade' => $bloqueadosBatidas,
                'metric_label' => 'com batidas pendentes',
                'progresso_label' => $feitosEtapa . ' de ' . $total . ' já conferidos · faltam '
                    . max(0, $total - $feitosEtapa),
                'cta_label' => 'Abrir ponto (' . $bloqueadosBatidas . ')',
                'cta_href' => '/rh/ponto?' . $qs,
                'secondary_href' => '/rh/medicao?' . $qs,
                'tom' => 'warning',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => $pct,
                'pronto_dossie' => false,
                'etapa_atual' => 'tratado',
            ], $barra);
        }

        $faltamAss = (int) ($assinaturas['total'] ?? 0);
        $feitosEtapa = (int) ($feitos['assinaturas'] ?? 0);
        return $this->montarHeroPayload([
            'chave' => 'assinaturas',
            'kicker' => 'Reta final · Etapa 6',
            'titulo' => 'Aguardando assinaturas para o dossiê',
            'subtitulo' => (int) ($assinaturas['folhas_pendentes'] ?? 0) . ' folha(s) e '
                . ((int) ($assinaturas['ras_pendentes'] ?? 0) + (int) ($assinaturas['ras_rascunho'] ?? 0))
                . ' RA(s) ainda pendentes.',
            'quantidade' => $faltamAss,
            'metric_label' => 'aguardando assinatura',
            'progresso_label' => $feitosEtapa . ' de ' . $total . ' já assinados · faltam ' . $faltamAss,
            'cta_label' => 'Acompanhar assinaturas (' . $faltamAss . ')',
            'cta_href' => '/rh/medicao?' . $qs . '&filtro=assinaturas',
            'secondary_href' => '/rh/medicao?' . $qs,
            'tom' => !empty($assinaturas['em_atraso']) ? 'warning' : 'brand',
            'total' => $total,
            'concluidos' => $concluidos,
            'pct' => $pct,
            'pronto_dossie' => false,
            'etapa_atual' => 'assinaturas',
        ], $barra);
    }

    /**
     * @param list<array<string,mixed>> $colaboradores
     * @return array{prontos:int,bloqueados:int,na_fila:int}
     */
    private function contarFilaEmail(array $colaboradores): array
    {
        $prontos = 0;
        $bloqueados = 0;
        foreach ($colaboradores as $c) {
            if (!$this->pertenceAoGargalo($c, 'email')) {
                continue;
            }
            if (self::diagnosticarFalhaEmail($c) !== null) {
                $bloqueados++;
            } else {
                $prontos++;
            }
        }

        return [
            'prontos' => $prontos,
            'bloqueados' => $bloqueados,
            'na_fila' => $prontos + $bloqueados,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<array<string,mixed>> $barra
     * @return array<string,mixed>
     */
    private function montarHeroPayload(array $payload, array $barra): array
    {
        $total = (int) ($payload['total'] ?? 0);
        $etapaAtual = $payload['etapa_atual'] ?? null;
        $barraOut = [];
        foreach ($barra as $st) {
            $atual = is_string($etapaAtual) && (string) $st['key'] === $etapaAtual;
            $barraOut[] = [
                'num' => (int) $st['num'],
                'key' => (string) $st['key'],
                'label' => (string) $st['label'],
                'feitos' => (int) $st['feitos'],
                'pct' => (int) $st['pct'],
                'atual' => $atual,
                'rotulo' => $total > 0
                    ? ((int) $st['feitos'] . '/' . $total)
                    : (string) (int) $st['feitos'],
            ];
        }

        return array_merge([
            'metric_label' => null,
            'progresso_label' => null,
            'splits' => [],
            'cta_secundario_label' => null,
            'cta_secundario_href' => null,
            'secondary_href' => null,
            'bloqueio_nota' => null,
            'bloqueio_href' => null,
            'barra' => $barraOut,
            'etapa_atual' => $etapaAtual,
        ], $payload, [
            'barra' => $barraOut,
        ]);
    }

    /**
     * @param array<string,mixed> $painel
     * @param array<string,mixed> $gargalo
     * @param array<string,mixed> $divergencias
     * @param array<string,mixed> $heBloqueante
     * @param array<string,mixed> $emailSemEntrega
     * @param array<string,mixed> $assinaturas
     * @return array<string,array<string,mixed>>
     */
    private function montarFilas(
        array $painel,
        array $gargalo,
        array $divergencias,
        array $heBloqueante,
        array $emailSemEntrega,
        array $assinaturas,
        string $qs,
        string $periodoLabel
    ): array {
        $colaboradores = $painel['colaboradores'] ?? [];

        // 1. Fila do gargalo atual
        $chaveGargalo = (string) $gargalo['chave'];
        $gargaloItens = [];
        foreach ($colaboradores as $c) {
            if (!$this->pertenceAoGargalo($c, $chaveGargalo)) {
                continue;
            }
            $eid = (int) $c['espelho_id'];
            $badge = 'Pendente';
            $badgeTom = 'neutral';
            if ($chaveGargalo === 'email') {
                $diagEmail = self::diagnosticarFalhaEmail($c);
                if ($diagEmail !== null) {
                    $detalhe = $diagEmail['motivo'];
                    $badge = $diagEmail['badge'];
                    $badgeTom = 'danger';
                } else {
                    $detalhe = 'Link gerado — enviar e-mail para ' . (string) ($c['email_corp'] ?? '');
                    $badge = 'Aguardando envio';
                    $badgeTom = 'warning';
                }
            } elseif ($chaveGargalo === 'divergencias') {
                $detalhe = 'Divergência aberta no ponto — revisar e liberar espelho';
                $badge = 'Em divergência';
                $badgeTom = 'danger';
            } elseif ($chaveGargalo === 'batidas_pendentes') {
                $qtdBat = (int) ($c['batidas_pendentes'] ?? 0);
                $detalhe = $qtdBat . ' dia(s) útil(eis) sem batidas completas no ponto';
                $badge = 'Batidas pendentes';
                $badgeTom = 'warning';
            } elseif ($chaveGargalo === 'conferir' || $chaveGargalo === 'he') {
                $hePend = !empty($c['he_pendente']);
                $detalhe = $hePend ? 'HE declarada no RA aguardando aplicação' : 'Pronto para conferir no ponto';
                $badge = $hePend ? 'HE declarada' : (($c['espelho_status'] ?? '') === 'em_tratamento' ? 'Em tratamento' : 'Em aberto');
                $badgeTom = $hePend ? 'warning' : 'neutral';
            } elseif ($chaveGargalo === 'fechar') {
                $detalhe = 'Ponto conferido — falta fechar período';
                $badge = 'Conferido';
            } elseif ($chaveGargalo === 'pdf') {
                $detalhe = 'Espelho fechado — falta gerar PDF da folha';
                $badge = 'Sem PDF';
            } elseif ($chaveGargalo === 'link') {
                $detalhe = 'PDF emitido — falta gerar link de assinatura';
                $badge = 'Sem link';
            } else {
                $folhaOk = ($c['folha_status'] ?? '') === 'assinado';
                $raOk = ($c['ra_doc_status'] ?? '') === 'assinado';
                $detalhe = ($folhaOk ? 'Folha assinada' : 'Falta assinar folha')
                    . ' · ' . ($raOk ? 'RA assinado' : ((($c['ra_status'] ?? '') === 'rascunho') ? 'RA em rascunho' : 'Falta assinar RA'));
                $badge = (!$folhaOk && !$raOk) ? 'Folha + RA' : (!$folhaOk ? 'Folha pendente' : 'RA pendente');
            }

            $ctaHref = match ($chaveGargalo) {
                'conferir', 'fechar', 'divergencias', 'batidas_pendentes' => '/rh/ponto/' . $eid . '?' . $qs,
                default => '/rh/medicao?' . $qs . '&filtro=' . urlencode(in_array($chaveGargalo, ['conferir', 'fechar', 'pdf', 'link', 'email', 'assinaturas'], true) ? $chaveGargalo : ''),
            };
            $gargaloItens[] = [
                'matricula' => (string) $c['matricula'],
                'nome' => (string) $c['nome'],
                'detalhe' => $detalhe,
                'badge' => $badge,
                'badge_tom' => $badgeTom,
                'cta_label' => in_array($chaveGargalo, ['conferir', 'fechar', 'divergencias', 'batidas_pendentes'], true) ? 'Abrir ponto' : 'Abrir na Medição',
                'cta_href' => $ctaHref,
            ];
        }

        // 2. Divergências
        $divItens = [];
        foreach ($divergencias['itens'] as $d) {
            $diaTxt = !empty($d['data_ocorrencia']) ? 'Dia ' . date('d/m', strtotime((string) $d['data_ocorrencia'])) . ' · ' : '';
            $divItens[] = [
                'matricula' => (string) $d['matricula'],
                'nome' => (string) $d['colaborador_nome'],
                'detalhe' => $diaTxt . mb_strimwidth((string) $d['motivo'], 0, 72, '…', 'UTF-8'),
                'badge' => (string) $d['idade_label'],
                'badge_tom' => !empty($d['sla_critico']) ? 'danger' : 'warning',
                'cta_label' => 'Corrigir ponto',
                'cta_href' => '/rh/ponto/' . (int) $d['espelho_id'] . '?' . $qs,
            ];
        }

        // 3. HE bloqueando conferência
        $heItens = [];
        foreach ($heBloqueante['itens'] as $h) {
            if (($h['motivo_bloqueio'] ?? '') === 'anexo_pendente' || (int) ($h['qtd_dias'] ?? 0) === 0) {
                $extra = 'HE no cartão sem anexo de autorização';
            } else {
                $extra = $h['qtd_dias'] . ' dia(s) com HE declarada' . (!empty($h['horas_fmt']) ? ' · ' . $h['horas_fmt'] : '');
            }
            $heItens[] = [
                'matricula' => (string) $h['matricula'],
                'nome' => (string) $h['nome'],
                'detalhe' => $extra,
                'badge' => 'Trava Etapa 2',
                'badge_tom' => 'warning',
                'cta_label' => 'Conferir ponto',
                'cta_href' => '/rh/ponto/' . (int) $h['espelho_id'] . '?' . $qs,
            ];
        }

        // 4. E-mail sem entrega
        $emailItens = [];
        foreach ($emailSemEntrega['itens'] as $em) {
            $emailItens[] = [
                'matricula' => (string) $em['matricula'],
                'nome' => (string) $em['nome'],
                'detalhe' => (string) $em['motivo'],
                'badge' => (string) ($em['badge'] ?? ($em['tipo'] === 'falha' ? 'Falha no envio' : ($em['tipo'] === 'invalido' ? 'Domínio inválido' : 'Sem e-mail'))),
                'badge_tom' => 'danger',
                'cta_label' => $em['tipo'] === 'falha' ? 'Abrir na Medição' : 'Editar cadastro',
                'cta_href' => $em['tipo'] === 'falha'
                    ? ('/rh/medicao?' . $qs . '&filtro=email&subfiltro=email_falha')
                    : ('/rh/colaboradores/' . (int) $em['colaborador_id'] . '/editar'),
            ];
        }

        // 5. Assinaturas pendentes / régua
        $assItens = [];
        foreach ($assinaturas['itens'] as $a) {
            $waHref = null;
            if (!empty($a['telefone'])) {
                if (empty($a['folha_assinada']) && !empty($a['portal_url'])) {
                    $wa = WhatsApp::chatPayload(
                        (string) $a['telefone'],
                        WhatsApp::mensagemLembreteFolha((string) $a['nome'], $periodoLabel, (string) $a['portal_url'])
                    );
                    $waHref = is_array($wa) ? (string) ($wa['url'] ?? '') : null;
                } elseif (!empty($a['folha_assinada']) && empty($a['ra_assinado']) && !empty($a['ra_portal_url'])) {
                    $nomeColab = trim((string) $a['nome']) !== '' ? trim((string) $a['nome']) : 'colaborador(a)';
                    $perTxt = trim($periodoLabel) !== '' ? trim($periodoLabel) : 'período atual';
                    $msgRa = "Olá, *{$nomeColab}*!\n\n"
                        . "*Lembrete — assinatura pendente*\n"
                        . "Período: *{$perTxt}*\n\n"
                        . "Seu Relatório de Atividades (RA) ainda aguarda sua assinatura eletrônica.\n\n"
                        . "Acesse o link abaixo para concluir:\n"
                        . (string) $a['ra_portal_url'] . "\n\n"
                        . '_Arcadis RH — Corredor Norte_';
                    $wa = WhatsApp::chatPayload((string) $a['telefone'], $msgRa);
                    $waHref = is_array($wa) ? (string) ($wa['url'] ?? '') : null;
                }
            }
            $nivel = (int) ($a['nivel_cobranca'] ?? 0);
            $badge = $nivel > 0 ? ('Cobrança ' . $nivel) : (!empty($a['em_atraso']) ? 'Em atraso' : 'Aguardando');
            $assItens[] = [
                'matricula' => (string) $a['matricula'],
                'nome' => (string) $a['nome'],
                'detalhe' => 'Falta: ' . (string) $a['pendencia_label'],
                'badge' => $badge,
                'badge_tom' => !empty($a['em_atraso']) ? 'warning' : 'neutral',
                'cta_label' => $waHref ? 'WhatsApp' : 'Ver na Medição',
                'cta_href' => $waHref ?: ('/rh/medicao?' . $qs . '&filtro=assinaturas'),
                'external' => (bool) $waHref,
            ];
        }

        return [
            'gargalo' => [
                'label' => 'Gargalo atual',
                'total' => count($gargaloItens),
                'itens' => array_slice($gargaloItens, 0, self::MAX_FILA_ITENS),
                'ver_todos_href' => (string) ($gargalo['cta_href'] ?? '/rh/medicao?' . $qs),
            ],
            'divergencias' => [
                'label' => 'Revisões abertas',
                'total' => count($divItens),
                'itens' => array_slice($divItens, 0, self::MAX_FILA_ITENS),
                'ver_todos_href' => (string) $divergencias['href'],
            ],
            'he' => [
                'label' => 'HE bloqueante',
                'total' => count($heItens),
                'itens' => array_slice($heItens, 0, self::MAX_FILA_ITENS),
                'ver_todos_href' => (string) $heBloqueante['href'],
            ],
            'email' => [
                'label' => 'E-mails sem entrega',
                'total' => count($emailItens),
                'itens' => array_slice($emailItens, 0, self::MAX_FILA_ITENS),
                'ver_todos_href' => (string) $emailSemEntrega['href'],
            ],
            'assinaturas' => [
                'label' => 'Assinaturas',
                'total' => count($assItens),
                'itens' => array_slice($assItens, 0, self::MAX_FILA_ITENS),
                'ver_todos_href' => (string) $assinaturas['href'],
            ],
        ];
    }

    /** @param array<string,mixed> $c */
    private function pertenceAoGargalo(array $c, string $chave): bool
    {
        $et = $c['etapas'] ?? [];
        return match ($chave) {
            'divergencias' => !empty($c['em_divergencia']),
            'batidas_pendentes' => empty($et['fechado']) && !empty($c['batidas_pendentes']) && empty($c['em_divergencia']),
            'conferir', 'he' => empty($et['tratado']),
            'fechar' => !empty($et['tratado']) && empty($et['fechado']),
            'pdf' => !empty($et['fechado']) && empty($et['pdf']),
            'link' => !empty($et['pdf']) && empty($et['link']),
            'email' => !empty($et['link']) && empty($et['email']),
            'assinaturas' => !empty($et['email']) && empty($et['assinaturas']),
            default => empty($c['concluido']),
        };
    }

    /**
     * @param array<string,array<string,mixed>> $filas
     * @param array<string,mixed> $divergencias
     * @param array<string,mixed> $heBloqueante
     * @param array<string,mixed> $emailSemEntrega
     */
    private function escolherFilaPadrao(
        array $filas,
        array $divergencias,
        array $heBloqueante,
        array $emailSemEntrega
    ): string {
        if (!empty($divergencias['criticas'])) {
            return 'divergencias';
        }
        if (($filas['gargalo']['total'] ?? 0) > 0) {
            return 'gargalo';
        }
        if (($divergencias['total'] ?? 0) > 0) {
            return 'divergencias';
        }
        if (($heBloqueante['total'] ?? 0) > 0) {
            return 'he';
        }
        if (($emailSemEntrega['total'] ?? 0) > 0) {
            return 'email';
        }
        return 'assinaturas';
    }

    private function diffMinutos(string $ini, string $fim): int
    {
        $a = strtotime('1970-01-01 ' . substr(trim($ini), 0, 8));
        $b = strtotime('1970-01-01 ' . substr(trim($fim), 0, 8));
        if ($a === false || $b === false) {
            return 0;
        }
        if ($b < $a) {
            $b += 86400;
        }
        return max(0, (int) (($b - $a) / 60));
    }

    private function fmtMin(int $min): string
    {
        $h = intdiv($min, 60);
        $m = $min % 60;
        return $m > 0 ? sprintf('%dh%02d', $h, $m) : ($h . 'h');
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1"
        );
        $st->execute(['t' => $table]);
        return (bool) $st->fetchColumn();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1"
        );
        $st->execute(['t' => $table, 'c' => $column]);
        return (bool) $st->fetchColumn();
    }
}
