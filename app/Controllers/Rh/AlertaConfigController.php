<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\AppUrl;
use App\Helpers\Auth;
use App\Helpers\CronSecret;
use App\Helpers\Csrf;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\AlertasService;
use App\Services\CobrancaAssinaturaService;
use App\Services\EmailOAuth\EmailAccountService;
use App\Services\PerfilService;
use PDO;

final class AlertaConfigController
{
    public function index(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        $pdo = Db::tryConnection();
        $aba = (string) ($_GET['aba'] ?? 'tipos');
        if (!in_array($aba, ['tipos', 'destinos', 'geral', 'operacao'], true)) {
            $aba = 'tipos';
        }

        $tipos = [];
        $destinos = [];
        $config = [
            'digest_ativo' => false,
            'digest_hora' => '08:00',
            'historico_dias' => 30,
            'limite_lista' => 200,
            'digest_ultimo_disparo' => '',
            'digest_ultima_data' => '',
            'digest_ultimo_resumo' => '',
        ];
        $abertos = [];
        $abertosCount = 0;
        $usuarios = [];
        $perfis = [];
        $canalEmail = [
            'ok' => false,
            'status_label' => 'Sem conta configurada',
            'email_remetente' => '',
            'provider' => '',
            'ultimo_erro' => '',
        ];
        $erro = null;

        if ($pdo instanceof PDO) {
            try {
                $svc = new AlertasService($pdo);
                $tipos = $svc->listarTipos();
                $destinos = $svc->listarDestinosAgrupados();
                $config = $svc->obterConfig();
                $usuarios = $svc->listarUsuariosAtribuicao();
                $perfis = (new PerfilService($pdo))->listar(true);
                $abertos = $svc->listar((int) (Auth::id() ?? 0), [
                    'status' => 'abertos',
                    'ignorar_destinos' => true,
                    'sincronizar' => $aba === 'operacao',
                ]);
                $abertosCount = count($abertos);

                $conta = (new EmailAccountService($pdo))->contaAtiva();
                if ($conta !== null) {
                    $stConta = (string) ($conta['status'] ?? 'desconectado');
                    $ultErro = trim((string) ($conta['ultimo_erro'] ?? ''));
                    $conectado = ($stConta === 'conectado' && $ultErro === '');
                    $canalEmail = [
                        'ok' => $conectado,
                        'status_label' => $conectado
                            ? 'Conectado e pronto para envios'
                            : ($stConta === 'conectado' ? 'Conectado com alerta recente' : 'Desconectado (' . $stConta . ')'),
                        'email_remetente' => (string) ($conta['email_remetente'] ?? ''),
                        'provider' => strtoupper((string) ($conta['provider'] ?? 'OAUTH')),
                        'ultimo_erro' => $ultErro,
                    ];
                }
            } catch (\Throwable $e) {
                $erro = $e->getMessage();
            }
        }

        $appBaseUrl = rtrim(AppUrl::base(), '/');
        if ($appBaseUrl === '') {
            $appBaseUrl = 'http://127.0.0.1:8090';
        }

        View::render('rh/alertas/config', [
            'pageTitle' => 'Configurações da Central de Alertas',
            'pageSubtitle' => 'Regras de disparo, destinatários por perfil, resumo diário por e-mail e manutenção em lote',
            'activeMenu' => 'alertas-config',
            'extraCss' => ['css/alertas-config.css?v=20261116'],
            'extraJs' => ['js/alertas-config.js?v=20261116'],
            'aba' => $aba,
            'tipos' => $tipos,
            'destinos' => $destinos,
            'config' => $config,
            'abertos' => $abertos,
            'abertosCount' => $abertosCount,
            'usuarios' => $usuarios,
            'perfis' => $perfis,
            'canalEmail' => $canalEmail,
            'cronConfigured' => CronSecret::resolve() !== '',
            'appBaseUrl' => $appBaseUrl,
            'flashOk' => $_GET['ok'] ?? null,
            'flashErro' => $_GET['erro'] ?? $erro,
            'dbOk' => $pdo instanceof PDO,
        ]);
    }

    public function salvarTipos(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectConfig('tipos', null, 'Banco indisponível.');
        }
        $raw = $_POST['tipos'] ?? [];
        $tipos = is_array($raw) ? array_values($raw) : [];
        (new AlertasService($pdo))->salvarTipos($tipos, (int) (Auth::id() ?? 0));
        $this->redirectConfig('tipos', 'Regras, severidades e prazos (SLA) salvos com sucesso.');
    }

    public function salvarDestinos(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectConfig('destinos', null, 'Banco indisponível.');
        }
        $raw = $_POST['destinos'] ?? [];
        $porCodigo = [];
        if (is_array($raw)) {
            foreach ($raw as $codigo => $itens) {
                $porCodigo[(string) $codigo] = is_array($itens) ? array_values($itens) : [];
            }
        }
        // Códigos sem chave no POST precisam limpar destinos (volta para visível a todos)
        $svc = new AlertasService($pdo);
        foreach ($svc->listarTipos() as $t) {
            $c = (string) $t['codigo'];
            if (!array_key_exists($c, $porCodigo)) {
                $porCodigo[$c] = [];
            }
        }
        $svc->salvarDestinos($porCodigo, (int) (Auth::id() ?? 0));
        $this->redirectConfig('destinos', 'Regras de destinatários salvas com sucesso.');
    }

    public function salvarGeral(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectConfig('geral', null, 'Banco indisponível.');
        }
        (new AlertasService($pdo))->salvarConfig([
            'digest_ativo' => !empty($_POST['digest_ativo']),
            'digest_hora' => (string) ($_POST['digest_hora'] ?? '08:00'),
            'historico_dias' => (int) ($_POST['historico_dias'] ?? 30),
            'limite_lista' => (int) ($_POST['limite_lista'] ?? 200),
        ], (int) (Auth::id() ?? 0));
        $this->redirectConfig('geral', 'Configurações de resumo diário e retenção salvas com sucesso.');
    }

    public function testarDigest(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectConfig('geral', null, 'Banco indisponível.');
        }
        $escopo = (string) ($_POST['escopo'] ?? 'meu_usuario');
        $apenasUid = $escopo === 'todos' ? null : (int) (Auth::id() ?? 0);
        try {
            $res = (new AlertasService($pdo))->executarDigest(null, true, $apenasUid);
            $env = (int) ($res['enviados'] ?? 0);
            $falhas = (int) ($res['falhas'] ?? 0);
            $erroDet = trim((string) ($res['erro'] ?? ''));
            if ($env > 0) {
                $this->redirectConfig(
                    'geral',
                    sprintf('Resumo diário disparado com sucesso (%d e-mail(s) enviado(s)).', $env)
                );
            }
            if ($falhas > 0) {
                $this->redirectConfig(
                    'geral',
                    null,
                    'Não foi possível enviar o resumo por e-mail' . ($erroDet !== '' ? ': ' . $erroDet : '. Verifique a conta de e-mail em /rh/email.')
                );
            }
            $this->redirectConfig(
                'geral',
                null,
                'Nenhum destinatário elegível com e-mail cadastrado encontrado para o disparo.'
            );
        } catch (\Throwable $e) {
            $this->redirectConfig('geral', null, 'Erro ao disparar resumo: ' . $e->getMessage());
        }
    }

    public function executarCobranca(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirectConfig('geral', null, 'Banco indisponível.');
        }
        try {
            $res = (new CobrancaAssinaturaService($pdo))->executar('auto');
            $env = (int) ($res['enviados'] ?? 0);
            $pul = (int) ($res['pulados'] ?? 0);
            $fal = (int) ($res['falhas'] ?? 0);
            $reason = trim((string) ($res['reason'] ?? ''));
            if ($reason === 'conta_email_indisponivel') {
                $this->redirectConfig(
                    'geral',
                    null,
                    'A régua de cobrança não pôde enviar e-mails porque o canal de e-mail está desconectado. Verifique em /rh/email.'
                );
            }
            if ($reason === 'dia_nao_util') {
                $this->redirectConfig(
                    'geral',
                    'Verificação concluída: hoje não é dia útil no calendário operacional (nenhuma cobrança disparada).'
                );
            }
            $this->redirectConfig(
                'geral',
                sprintf('Régua de cobrança executada: %d enviado(s), %d aguardando janela/pulado(s), %d falha(s).', $env, $pul, $fal)
            );
        } catch (\Throwable $e) {
            $this->redirectConfig('geral', null, 'Erro ao executar régua de cobrança: ' . $e->getMessage());
        }
    }

    public function adminResolver(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $ids = $this->idsFromPost();
        if ($ids === []) {
            $this->redirectConfig('operacao', null, 'Selecione ao menos um alerta na tabela antes de executar a ação.');
        }
        $n = 0;
        if ($pdo instanceof PDO) {
            $n = (new AlertasService($pdo))->forcarResolver($ids, (int) (Auth::id() ?? 0));
        }
        $this->redirectConfig('operacao', $n . ' alerta(s) marcado(s) como resolvido(s).');
    }

    public function adminReatribuir(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $ids = $this->idsFromPost();
        if ($ids === []) {
            $this->redirectConfig('operacao', null, 'Selecione ao menos um alerta para atribuir responsável.');
        }
        $para = (int) ($_POST['usuario_id'] ?? 0);
        $n = 0;
        if ($pdo instanceof PDO) {
            $n = (new AlertasService($pdo))->reatribuirEmMassa(
                $ids,
                $para,
                (int) (Auth::id() ?? 0)
            );
        }
        $this->redirectConfig('operacao', $n . ' alerta(s) atualizado(s) com o novo responsável.');
    }

    public function adminDispensar(): void
    {
        Auth::requirePermission('alertas.gerenciar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $ids = $this->idsFromPost();
        if ($ids === []) {
            $this->redirectConfig('operacao', null, 'Selecione ao menos um alerta para arquivar.');
        }
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        $n = 0;
        if ($pdo instanceof PDO) {
            $n = (new AlertasService($pdo))->dispensarGlobal(
                $ids,
                (int) (Auth::id() ?? 0),
                $motivo !== '' ? $motivo : null
            );
        }
        $this->redirectConfig('operacao', $n . ' alerta(s) arquivado(s) para toda a equipe.');
    }

    /** @return list<int> */
    private function idsFromPost(): array
    {
        $raw = $_POST['ids'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $raw), static fn (int $i): bool => $i > 0));
    }

    private function redirectConfig(string $aba, ?string $ok = null, ?string $erro = null): void
    {
        $qs = ['aba' => $aba];
        if ($ok !== null) {
            $qs['ok'] = $ok;
        }
        if ($erro !== null) {
            $qs['erro'] = $erro;
        }
        header('Location: /rh/alertas/config?' . http_build_query($qs));
        exit;
    }
}
