<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Helpers\DiaUtil;
use App\Helpers\EmailDeliverability;
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
             WHERE c.status = 'ativo'
             ORDER BY c.nome, c.id"
        );
        $st->execute(['inicio' => $dataInicio, 'fim' => $dataFim]);

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
        $todas = AssinaturaEletronicaService::listarDivergenciasAbertas($this->db, 100);
        $itensPeriodo = [];
        $outrasPeriodos = 0;
        $agoraTs = time();

        foreach ($todas as $div) {
            $createdTs = !empty($div['created_at']) ? (strtotime((string) $div['created_at']) ?: $agoraTs) : $agoraTs;
            $horas = max(0, (int) floor(($agoraTs - $createdTs) / 3600));
            $dias = (int) floor($horas / 24);
            $idadeLabel = $dias >= 1 ? ($dias . 'd') : ($horas >= 1 ? ($horas . 'h') : 'Agora');
            $critico = $horas >= 48;

            $item = [
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

            if ($item['data_inicio'] === $dataInicio && $item['data_fim'] === $dataFim) {
                $itensPeriodo[] = $item;
            } else {
                $outrasPeriodos++;
            }
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

        if ($this->hasColumn('ra_dias', 'he_status')) {
            try {
                $st = $this->db->prepare(
                    "SELECT e.id AS espelho_id, d.data, d.he_hora_inicio, d.he_hora_fim
                     FROM ponto_espelhos e
                     INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
                     INNER JOIN ra_relatorios r
                        ON r.colaborador_id = e.colaborador_id AND r.periodo_id = e.periodo_id
                     INNER JOIN ra_dias d ON d.relatorio_id = r.id
                     WHERE pp.data_inicio = :ini AND pp.data_fim = :fim
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
                    $totalDias++;
                    $totalMin += $min;
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
            $info = $detalhes[$eid] ?? ['qtd_dias' => 1, 'minutos' => 0, 'primeira_data' => null];
            $itens[] = [
                'espelho_id' => $eid,
                'colaborador_id' => (int) $c['colaborador_id'],
                'nome' => (string) $c['nome'],
                'matricula' => (string) $c['matricula'],
                'qtd_dias' => (int) $info['qtd_dias'],
                'minutos' => (int) $info['minutos'],
                'horas_fmt' => $info['minutos'] > 0 ? $this->fmtMin((int) $info['minutos']) : null,
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

            if (!$folhaAssinada) {
                $folhasPendentes++;
            }
            if (!$raAssinado) {
                if (in_array($raStatus, ['rascunho', ''], true)) {
                    $rasRascunho++;
                } else {
                    $rasPendentesAssinatura++;
                }
            }

            $docId = (int) ($c['documento_id'] ?? 0);
            $nivelFolha = $docId > 0 ? (int) ($cobrancaPorDoc['folha:' . $docId] ?? 0) : 0;
            $nivelMax = max($nivelMax, $nivelFolha);

            $pendencias = [];
            if (!$folhaAssinada) {
                $pendencias[] = 'Folha';
            }
            if (!$raAssinado) {
                $pendencias[] = in_array($raStatus, ['rascunho', ''], true) ? 'RA (rascunho)' : 'RA';
            }

            $itens[] = [
                'espelho_id' => (int) $c['espelho_id'],
                'colaborador_id' => (int) $c['colaborador_id'],
                'nome' => (string) $c['nome'],
                'matricula' => (string) $c['matricula'],
                'relatorio_id' => $c['relatorio_id'] ?? null,
                'folha_assinada' => $folhaAssinada,
                'ra_assinado' => $raAssinado,
                'ra_status' => $raStatus,
                'pendencia_label' => implode(' + ', $pendencias),
                'nivel_cobranca' => $nivelFolha,
                'telefone' => $c['telefone'] ?? null,
                'portal_url' => $c['portal_url'] ?? null,
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
     * @param array<string,mixed> $resumo
     * @param list<array<string,mixed>> $colaboradores
     * @param array<string,mixed> $cobertura
     * @param array<string,mixed> $heBloqueante
     * @param array<string,mixed> $emailSemEntrega
     * @param array<string,mixed> $canalEmail
     * @return array<string,mixed>
     */
    private function resolverGargaloHero(
        array $resumo,
        array $colaboradores,
        array $cobertura,
        array $heBloqueante,
        array $emailSemEntrega,
        array $canalEmail,
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
            return [
                'chave' => 'sem_espelho',
                'kicker' => 'Etapa inicial',
                'titulo' => 'Nenhum cartão gerado neste período',
                'subtitulo' => $cobertura['faltantes'] > 0
                    ? $cobertura['faltantes'] . ' colaborador(es) ativo(s) aguardam geração de espelho.'
                    : 'Selecione outro período ou gere os espelhos em Gestão de Ponto.',
                'quantidade' => (int) $cobertura['faltantes'],
                'cta_label' => 'Gerar cartões no Ponto',
                'cta_href' => '/rh/ponto?' . $qs,
                'secondary_href' => null,
                'bloqueio_nota' => null,
                'tom' => 'brand',
                'total' => 0,
                'concluidos' => 0,
                'pct' => 0,
                'pronto_dossie' => false,
                'barra' => $barra,
            ];
        }

        if ($pronto) {
            return [
                'chave' => 'dossie',
                'kicker' => 'Pronto para medição',
                'titulo' => 'Dossiê 100% concluído',
                'subtitulo' => 'Todos os ' . $total . ' colaboradores concluíram cartão de ponto e RA assinados.',
                'quantidade' => $concluidos,
                'cta_label' => 'Baixar ZIP do Dossiê',
                'cta_href' => '/rh/medicao/dossie?' . $qs,
                'secondary_href' => '/rh/medicao?' . $qs,
                'bloqueio_nota' => null,
                'tom' => 'success',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => 100,
                'pronto_dossie' => true,
                'barra' => $barra,
            ];
        }

        $mapaAcao = [
            'conferir' => [
                'kicker' => 'Gargalo atual · Etapa 2',
                'titulo' => 'Conferir cartões pendentes',
                'cta' => 'Conferir na Medição',
            ],
            'fechar' => [
                'kicker' => 'Gargalo atual · Etapa 3',
                'titulo' => 'Fechar espelhos conferidos',
                'cta' => 'Fechar na Medição',
            ],
            'pdf' => [
                'kicker' => 'Gargalo atual · Etapa 4',
                'titulo' => 'Emitir PDFs das folhas',
                'cta' => 'Gerar PDFs',
            ],
            'link' => [
                'kicker' => 'Gargalo atual · Etapa 5',
                'titulo' => 'Gerar links de assinatura',
                'cta' => 'Gerar links',
            ],
            'email' => [
                'kicker' => 'Gargalo atual · Etapa 6',
                'titulo' => 'Enviar folhas por e-mail',
                'cta' => 'Enviar e-mails',
            ],
        ];

        if (is_string($proxima) && isset($mapaAcao[$proxima]) && (int) ($elegiveis[$proxima] ?? 0) > 0) {
            $qtdElegiveis = (int) $elegiveis[$proxima];
            $naFilaEtapa = count(array_filter(
                $colaboradores,
                fn (array $c): bool => $this->pertenceAoGargalo($c, $proxima)
            ));
            $qtdExibida = max($qtdElegiveis, $naFilaEtapa);

            $notas = [];
            if ($proxima !== 'conferir' && (int) $heBloqueante['total'] > 0) {
                $notas[] = $heBloqueante['total'] . ' bloqueado(s) por HE no RA (Etapa 2)';
            }
            if ($proxima === 'email') {
                if ((int) $emailSemEntrega['total'] > 0 && ($canalEmail['estado'] ?? '') === 'ok') {
                    $notas[] = $emailSemEntrega['total'] . ' na fila com falha/e-mail inválido';
                }
            } elseif ((int) $emailSemEntrega['total'] > 0) {
                $notas[] = $emailSemEntrega['total'] . ' com bloqueio de e-mail na Etapa 6';
            }

            $subtitulo = $proxima === 'email' && $naFilaEtapa > $qtdElegiveis
                ? $qtdElegiveis . ' pronto(s) para envio e ' . ($naFilaEtapa - $qtdElegiveis) . ' sem e-mail cadastrado · ' . $concluidos . ' concluído(s).'
                : $qtdExibida . ' de ' . $total . ' na fila desta etapa · ' . $concluidos . ' concluído(s).';

            $ctaLabel = ($proxima === 'email' && ($canalEmail['estado'] ?? '') !== 'ok')
                ? 'Revisar fila na Medição (' . $qtdExibida . ')'
                : ($mapaAcao[$proxima]['cta'] . ' (' . $qtdElegiveis . ')');

            return [
                'chave' => $proxima,
                'kicker' => $mapaAcao[$proxima]['kicker'],
                'titulo' => $mapaAcao[$proxima]['titulo'],
                'subtitulo' => $subtitulo,
                'quantidade' => $qtdExibida,
                'cta_label' => $ctaLabel,
                'cta_href' => '/rh/medicao?' . $qs . '&filtro=' . $proxima,
                'secondary_href' => '/rh/medicao?' . $qs,
                'bloqueio_nota' => $notas !== [] ? implode(' · ', $notas) : null,
                'tom' => 'brand',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => $pct,
                'pronto_dossie' => false,
                'barra' => $barra,
            ];
        }

        if ((int) $heBloqueante['total'] > 0) {
            $qtd = (int) $heBloqueante['total'];
            return [
                'chave' => 'he',
                'kicker' => 'Trava na Etapa 2',
                'titulo' => 'Aplicar HE Cliente para destravar',
                'subtitulo' => $qtd . ' colaborador(es) com hora extra declarada no RA impedindo conferência.',
                'quantidade' => $qtd,
                'cta_label' => 'Revisar HE (' . $qtd . ')',
                'cta_href' => (string) $heBloqueante['href'],
                'secondary_href' => '/rh/medicao?' . $qs,
                'bloqueio_nota' => null,
                'tom' => 'warning',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => $pct,
                'pronto_dossie' => false,
                'barra' => $barra,
            ];
        }

        if ((int) $emailSemEntrega['total'] > 0) {
            $qtd = (int) $emailSemEntrega['total'];
            return [
                'chave' => 'email',
                'kicker' => 'Trava na Etapa 6',
                'titulo' => 'Corrigir e-mails que não entregam',
                'subtitulo' => $qtd . ' colaborador(es) sem e-mail válido ou com falha de disparo.',
                'quantidade' => $qtd,
                'cta_label' => 'Corrigir envios (' . $qtd . ')',
                'cta_href' => (string) $emailSemEntrega['href'],
                'secondary_href' => '/rh/medicao?' . $qs,
                'bloqueio_nota' => null,
                'tom' => 'danger',
                'total' => $total,
                'concluidos' => $concluidos,
                'pct' => $pct,
                'pronto_dossie' => false,
                'barra' => $barra,
            ];
        }

        $faltamAss = max(0, $total - $concluidos);
        return [
            'chave' => 'assinaturas',
            'kicker' => 'Reta final · Etapa 7',
            'titulo' => 'Aguardando assinaturas para o dossiê',
            'subtitulo' => $assinaturas['folhas_pendentes'] . ' folha(s) e '
                . ($assinaturas['ras_pendentes'] + $assinaturas['ras_rascunho']) . ' RA(s) ainda pendentes.',
            'quantidade' => $faltamAss,
            'cta_label' => 'Acompanhar assinaturas (' . $faltamAss . ')',
            'cta_href' => '/rh/medicao?' . $qs . '&filtro=assinaturas',
            'secondary_href' => '/rh/medicao?' . $qs,
            'bloqueio_nota' => null,
            'tom' => !empty($assinaturas['em_atraso']) ? 'warning' : 'brand',
            'total' => $total,
            'concluidos' => $concluidos,
            'pct' => $pct,
            'pronto_dossie' => false,
            'barra' => $barra,
        ];
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
                'conferir', 'fechar' => '/rh/ponto/' . $eid . '?' . $qs,
                default => '/rh/medicao?' . $qs . '&filtro=' . urlencode(in_array($chaveGargalo, ['conferir', 'fechar', 'pdf', 'link', 'email', 'assinaturas'], true) ? $chaveGargalo : ''),
            };
            $gargaloItens[] = [
                'matricula' => (string) $c['matricula'],
                'nome' => (string) $c['nome'],
                'detalhe' => $detalhe,
                'badge' => $badge,
                'badge_tom' => $badgeTom,
                'cta_label' => in_array($chaveGargalo, ['conferir', 'fechar'], true) ? 'Abrir ponto' : 'Abrir na Medição',
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
            $extra = $h['qtd_dias'] . ' dia(s) com HE declarada' . (!empty($h['horas_fmt']) ? ' · ' . $h['horas_fmt'] : '');
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
            if (!empty($a['portal_url']) && !empty($a['telefone'])) {
                $wa = WhatsApp::chatPayload(
                    (string) $a['telefone'],
                    WhatsApp::mensagemLembreteFolha((string) $a['nome'], $periodoLabel, (string) $a['portal_url'])
                );
                $waHref = is_array($wa) ? (string) ($wa['url'] ?? '') : null;
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
