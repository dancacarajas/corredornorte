<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\ContaController;
use App\Controllers\He\PortalController as HePortalController;
use App\Controllers\He\RelatorioAssinaturaController;
use App\Controllers\HomeController;
use App\Controllers\Rh\CadastroController;
use App\Controllers\Rh\CalendarioController;
use App\Controllers\Rh\ColaboradorController;
use App\Controllers\Rh\EfetivoController;
use App\Controllers\Rh\EmailContaController;
use App\Controllers\Rh\HorarioController;
use App\Controllers\Rh\FolhaController;
use App\Controllers\Rh\ImportacaoController;
use App\Controllers\Rh\PerfilController;
use App\Controllers\Rh\PontoController;
use App\Controllers\Rh\RelatorioAtividadesController;
use App\Controllers\Rh\UsuarioController;
use App\Controllers\Rh\AtividadeController;
use App\Controllers\CronController;
use App\Controllers\Rh\HeRelatorioController;
use App\Controllers\Rh\MedicaoController;
use App\Controllers\Rh\ValeRefeicaoController;
use App\Controllers\Rh\EntregaEquipamentoController;
use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\HePortalAuth;
use App\Helpers\Router;
use App\Helpers\View;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$config = require $root . '/config/app.php';
$tz = (string) ($config['timezone'] ?? \App\Helpers\AppClock::TIMEZONE);
if ($tz === '' || $tz === 'America/Belem') {
    $tz = \App\Helpers\AppClock::TIMEZONE;
}
date_default_timezone_set($tz);
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

// PHP 8.4+/8.5: depreciações de libs (FPDI/QRCode) não podem ir para o browser —
// quebram redirect/PDF com "headers already sent".
// Não referenciar E_STRICT: a constante em si é deprecated desde 8.4.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
$debug = (($config['env'] ?? '') === 'local') && !empty($config['debug']);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; img-src 'self' data: blob:; font-src 'self' https://fonts.gstatic.com data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; script-src 'self' 'unsafe-inline'; worker-src 'self' blob:; connect-src 'self'; frame-src 'self' blob:");
if ((($config['env'] ?? '') === 'production')
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptName = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if ($scriptName !== '/' && $scriptName !== '.' && $scriptName !== '' && str_starts_with($path, $scriptName)) {
    $path = substr($path, strlen($scriptName)) ?: '/';
}

$installed = is_file($root . '/storage/install.lock')
    && is_file($root . '/config/database.local.php');
$normalizedPath = rtrim($path, '/') ?: '/';
if (!$installed && !str_starts_with($normalizedPath, '/install')) {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    if ($base === '/' || $base === '\\') {
        $base = '';
    }
    header('Location: ' . $base . '/install/');
    exit;
}

Auth::start();

$httpMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($httpMethod === 'POST') {
    $isDedicatedPortalSign = (bool) preg_match('#^/(assinar|assinar-relatorio)/[^/]+/(assinar|divergencia)$#', $normalizedPath);
    if (!$isDedicatedPortalSign && !str_starts_with($normalizedPath, '/install')) {
        Csrf::requireValid();
    }
}

$router = new Router();
$auth = AuthController::class;
$home = HomeController::class;
$efetivo = EfetivoController::class;
$cadastro = CadastroController::class;
$colaborador = ColaboradorController::class;
$importacao = ImportacaoController::class;
$ponto = PontoController::class;
$folha = FolhaController::class;
$calendario = CalendarioController::class;
$emailConta = EmailContaController::class;
$usuarios = UsuarioController::class;
$atividade = AtividadeController::class;
$perfisCtrl = PerfilController::class;
$alertas = \App\Controllers\Rh\AlertaController::class;
$alertasConfig = \App\Controllers\Rh\AlertaConfigController::class;

$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->post('/logout', [$auth, 'logout']);
$router->get('/esqueci-senha', [$auth, 'showForgot']);
$router->post('/esqueci-senha', [$auth, 'forgot']);
$router->get('/redefinir-senha/{token}', [$auth, 'showReset']);
$router->post('/redefinir-senha/{token}', [$auth, 'reset']);

$conta = ContaController::class;
$router->get('/conta', [$conta, 'perfil']);
$router->post('/conta/perfil', [$conta, 'atualizarPerfil']);
$router->post('/conta/senha', [$conta, 'alterarSenha']);
$router->post('/conta/avatar', [$conta, 'uploadAvatar']);
$router->post('/conta/avatar/remover', [$conta, 'removerAvatar']);
$router->post('/conta/solicitar-reset', [$conta, 'solicitarReset']);
$router->get('/conta/avatar', [$conta, 'avatar']);

$router->get('/verificar/{codigo}', [$folha, 'verificar']);
$router->get('/assinar/{token}', [$folha, 'portalAssinatura']);
$router->get('/assinar/{token}/pdf', [$folha, 'portalPdf']);
$router->post('/assinar/{token}/assinar', [$folha, 'portalAssinar']);
$router->post('/assinar/{token}/divergencia', [$folha, 'portalDivergencia']);
$router->get('/s/{token}', [$folha, 'shareWhatsapp']);
$router->get('/og/arcadis-folha.jpg', [$folha, 'ogLogo']);

$cron = CronController::class;
$router->get('/cron/cobranca-assinatura', [$cron, 'cobrancaAssinatura']);
$router->get('/cron/alertas-digest', [$cron, 'alertasDigest']);

$raAssinatura = RelatorioAssinaturaController::class;
$router->get('/assinar-relatorio/{token}', [$raAssinatura, 'portalAssinatura']);
$router->get('/assinar-relatorio/{token}/pdf', [$raAssinatura, 'portalPdf']);
$router->post('/assinar-relatorio/{token}/assinar', [$raAssinatura, 'portalAssinar']);
$router->get('/verificar-relatorio/{codigo}', [$raAssinatura, 'verificar']);

$hePortal = HePortalController::class;
$router->get('/he/login', [$hePortal, 'showLogin']);
$router->post('/he/login', [$hePortal, 'login']);
$router->post('/he/logout', [$hePortal, 'logout']);
$router->get('/he', [$hePortal, 'overview']);
$router->get('/he/solicitar', [$hePortal, 'fluxoHeDesativado']);
$router->post('/he/solicitar', [$hePortal, 'fluxoHeDesativado']);
$router->get('/he/aprovacoes', [$hePortal, 'fluxoHeDesativado']);
$router->post('/he/{id}/aprovar', [$hePortal, 'fluxoHeDesativado']);
$router->post('/he/{id}/recusar', [$hePortal, 'fluxoHeDesativado']);
$router->post('/he/{id}/cancelar', [$hePortal, 'fluxoHeDesativado']);
$router->get('/he/relatorios', [$hePortal, 'relatorios']);
$router->get('/he/relatorios/{id}', [$hePortal, 'relatorioShow']);
$router->post('/he/relatorios/{id}', [$hePortal, 'relatorioSalvar']);
$router->get('/he/relatorios/{id}/pdf', [$hePortal, 'relatorioPdf']);
$router->get('/he/relatorios/{id}/excel', [$hePortal, 'relatorioExcel']);
$router->post('/he/relatorios/{id}/link-assinatura', [$raAssinatura, 'gerarLink']);
$router->post('/he/relatorios/{id}/assinar', [$hePortal, 'relatorioAssinar']);
$router->get('/he/folhas', [$hePortal, 'folhas']);
$router->get('/he/folhas/{id}', [$hePortal, 'folhaShow']);
$router->get('/he/folhas/{id}/pdf', [$hePortal, 'folhaPdf']);
$router->post('/he/folhas/{id}/assinar', [$hePortal, 'folhaAssinar']);
$router->get('/he/ordens', [$hePortal, 'ordensJson']);

// Instalador bloqueado após instalação (evita reabrir com senha previsível).
if ($installed && str_starts_with($normalizedPath, '/install')) {
    http_response_code(404);
    echo 'Not Found';
    exit;
}

$public = ['/login', '/he/login', '/esqueci-senha'];
$isPublic = in_array($normalizedPath, $public, true)
    || str_starts_with($path, '/assets')
    || str_starts_with($path, '/verificar/')
    || str_starts_with($path, '/assinar/')
    || str_starts_with($path, '/assinar-relatorio/')
    || str_starts_with($path, '/verificar-relatorio/')
    || str_starts_with($path, '/s/')
    || str_starts_with($path, '/cron/')
    || str_starts_with($normalizedPath, '/redefinir-senha/')
    || $normalizedPath === '/og/arcadis-folha.jpg'
    || str_starts_with($path, '/he')
    || str_starts_with($path, '/install');
if (!$isPublic) {
    Auth::requireLogin();

    $requireAny = static function (string ...$perms): void {
        if (!Auth::canAny(...$perms)) {
            Auth::requirePermission($perms[0]);
        }
    };

    if ($normalizedPath === '/') {
        $requireAny('dashboard.ver');
    } elseif ($normalizedPath === '/rh/efetivo/exportar') {
        $requireAny('efetivo.ver', 'efetivo.editar', 'efetivo.criar', 'efetivo.operar', 'importacoes.gerenciar', 'importacoes.operar');
    } elseif (str_starts_with($normalizedPath, '/rh/efetivo')) {
        $requireAny('efetivo.ver', 'efetivo.editar', 'efetivo.criar', 'efetivo.operar');
    } elseif (str_starts_with($normalizedPath, '/rh/colaboradores')) {
        if ($normalizedPath === '/rh/colaboradores/excluir-massa' || str_ends_with($normalizedPath, '/excluir')) {
            $requireAny('efetivo.excluir');
        } elseif (str_contains($normalizedPath, '/transferir') || str_contains($normalizedPath, '/excecoes') || str_contains($normalizedPath, '/percentual-he')) {
            $requireAny('efetivo.operar', 'efetivo.editar');
        } elseif ($normalizedPath === '/rh/colaboradores/novo' || ($httpMethod === 'POST' && !preg_match('#/\d+#', $normalizedPath))) {
            $requireAny('efetivo.criar', 'efetivo.editar', 'efetivo.operar');
        } elseif ($httpMethod === 'POST' || str_ends_with($normalizedPath, '/editar')) {
            $requireAny('efetivo.editar', 'efetivo.operar');
        } else {
            $requireAny('efetivo.ver', 'efetivo.editar', 'efetivo.criar', 'efetivo.operar', 'efetivo.excluir');
        }
    } elseif ($normalizedPath === '/rh/importacoes/modelo') {
        $requireAny('importacoes.gerenciar', 'importacoes.ver', 'importacoes.operar', 'efetivo.ver', 'efetivo.editar', 'efetivo.criar', 'efetivo.operar');
    } elseif (str_starts_with($normalizedPath, '/rh/importacoes')) {
        $requireAny('importacoes.gerenciar', 'importacoes.ver', 'importacoes.operar');
    } elseif (str_starts_with($normalizedPath, '/rh/email')) {
        $requireAny('email.gerenciar', 'email.ver', 'email.operar');
    } elseif (str_starts_with($normalizedPath, '/rh/usuarios')) {
        if (str_ends_with($normalizedPath, '/excluir')) {
            $requireAny('usuarios.excluir', 'usuarios.gerenciar');
        } elseif (str_contains($normalizedPath, '/enviar-reset')) {
            $requireAny('usuarios.operar', 'usuarios.gerenciar');
        } elseif ($httpMethod === 'POST' || str_contains($normalizedPath, '/novo') || str_ends_with($normalizedPath, '/editar')) {
            $requireAny('usuarios.gerenciar', 'usuarios.criar', 'usuarios.editar', 'usuarios.operar');
        } else {
            $requireAny('usuarios.gerenciar', 'usuarios.ver', 'usuarios.criar', 'usuarios.editar', 'usuarios.operar', 'usuarios.excluir');
        }
    } elseif ($normalizedPath === '/rh/atividade/heartbeat') {
        // qualquer usuário autenticado — presença
    } elseif (str_starts_with($normalizedPath, '/rh/atividade')) {
        $requireAny('atividade.ver');
    } elseif (str_starts_with($normalizedPath, '/rh/perfis')) {
        if (str_ends_with($normalizedPath, '/excluir')) {
            $requireAny('perfis.excluir', 'perfis.gerenciar');
        } elseif ($httpMethod === 'POST' || str_contains($normalizedPath, '/novo') || str_ends_with($normalizedPath, '/editar')) {
            $requireAny('perfis.gerenciar', 'perfis.criar', 'perfis.editar', 'perfis.operar');
        } else {
            $requireAny('perfis.gerenciar', 'perfis.ver', 'perfis.criar', 'perfis.editar', 'perfis.operar', 'perfis.excluir');
        }
    } elseif (str_starts_with($normalizedPath, '/rh/folha')) {
        $httpMethod === 'POST'
            ? $requireAny('folha.enviar', 'folha.operar')
            : $requireAny('folha.ver', 'folha.enviar', 'folha.operar');
    } elseif ($normalizedPath === '/rh/ponto/periodo/excluir') {
        $requireAny('ponto.excluir_periodo', 'ponto.excluir', 'ponto.operar');
    } elseif (str_starts_with($normalizedPath, '/rh/ponto/justificativa-tipos')) {
        if ($httpMethod === 'POST' || str_contains($normalizedPath, '/novo') || str_ends_with($normalizedPath, '/editar')) {
            $requireAny('justificativa_tipos.criar', 'justificativa_tipos.editar', 'ponto.editar', 'ponto.operar');
        } else {
            $requireAny('justificativa_tipos.ver', 'ponto.ver', 'ponto.editar', 'ponto.operar');
        }
    } elseif (str_starts_with($normalizedPath, '/rh/ponto')) {
        $httpMethod === 'POST'
            ? $requireAny('ponto.editar', 'ponto.operar')
            : $requireAny('ponto.ver', 'ponto.editar', 'ponto.operar');
    } elseif (str_starts_with($normalizedPath, '/rh/medicao')) {
        $httpMethod === 'POST'
            ? $requireAny('medicao.operar', 'medicao.editar', 'ponto.editar', 'ponto.operar')
            : $requireAny('medicao.ver', 'medicao.operar', 'medicao.editar', 'ponto.ver', 'ponto.editar');
    } elseif (str_starts_with($normalizedPath, '/rh/relatorio-he')) {
        $httpMethod === 'POST'
            ? $requireAny('he_relatorio.operar', 'medicao.operar', 'medicao.editar', 'ponto.editar')
            : $requireAny('he_relatorio.ver', 'he_relatorio.operar', 'medicao.ver', 'medicao.operar', 'ponto.ver');
    } elseif (str_starts_with($normalizedPath, '/rh/relatorios-atividades')) {
        $httpMethod === 'POST'
            ? $requireAny('ra.editar', 'ra.operar', 'ponto.editar')
            : $requireAny('ra.ver', 'ra.editar', 'ra.operar', 'ponto.ver', 'ponto.editar');
    } elseif (str_starts_with($normalizedPath, '/rh/vale-refeicao/relatorio')) {
        $httpMethod === 'POST'
            ? $requireAny('vr_relatorio.operar', 'vr.editar', 'ponto.editar')
            : $requireAny('vr_relatorio.ver', 'vr_relatorio.operar', 'vr.ver', 'vr.editar');
    } elseif (str_starts_with($normalizedPath, '/rh/vale-refeicao')) {
        $httpMethod === 'POST'
            ? $requireAny('vr.criar', 'vr.editar', 'vr.excluir', 'ponto.editar')
            : $requireAny('vr.ver', 'vr.criar', 'vr.editar', 'ponto.ver', 'ponto.editar');
    } elseif (str_starts_with($normalizedPath, '/rh/entregas-equipamentos')) {
        $httpMethod === 'POST'
            ? $requireAny('entregas.gerir', 'entregas.operar')
            : $requireAny('entregas.ver', 'entregas.gerir', 'entregas.operar');
    } elseif (str_starts_with($normalizedPath, '/rh/alertas/config')) {
        $requireAny('alertas.gerenciar', 'alertas_config.ver', 'alertas_config.editar', 'alertas_config.operar');
    } elseif (preg_match('#^/rh/(empresas|contratos|centros-custo|ordens-servico|localidades|lotacoes|cargos|funcoes|gestores-arcadis|gestores-cliente|supervisores|calendarios|horarios)(/|$)#', $normalizedPath, $mCad)) {
        $slugCad = (string) ($mCad[1] ?? '');
        $mapaCad = \App\Services\PerfilService::mapaCadastrosMenu()[$slugCad] ?? null;
        if ($mapaCad !== null) {
            $isNovo = str_ends_with($normalizedPath, '/novo')
                || ($httpMethod === 'POST' && preg_match('#^/rh/' . preg_quote($slugCad, '#') . '/?$#', $normalizedPath) === 1);
            $isExcluir = str_contains($normalizedPath, '/excluir');
            if ($isExcluir) {
                $requireAny($mapaCad['excluir'], $mapaCad['editar'], 'cadastros.editar');
            } elseif ($isNovo) {
                $requireAny($mapaCad['criar'], $mapaCad['editar'], 'cadastros.editar');
            } elseif ($httpMethod === 'POST' || str_ends_with($normalizedPath, '/editar') || preg_match('#/\d+$#', $normalizedPath)) {
                $requireAny($mapaCad['editar'], $mapaCad['criar'], 'cadastros.editar');
            } else {
                $requireAny($mapaCad['ver'], $mapaCad['criar'], $mapaCad['editar'], 'cadastros.ver', 'cadastros.editar');
            }
        } else {
            $httpMethod === 'POST' || str_ends_with($normalizedPath, '/novo') || str_ends_with($normalizedPath, '/editar')
                ? $requireAny('cadastros.editar')
                : $requireAny('cadastros.ver', 'cadastros.editar');
        }
    }
} elseif (str_starts_with($path, '/he') && !in_array($normalizedPath, ['/he/login'], true)) {
    // Gate central do portal HE (evita endpoint novo sem requireLogin).
    HePortalAuth::requireLogin();
}

$router->get('/', [$home, 'index']);
$router->get('/ui/showcase', [$home, 'showcase']);
$router->get('/ui/mapa-sistema', [$home, 'mapaSistema']);

$router->get('/rh/alertas', [$alertas, 'index']);
$router->post('/rh/alertas/sincronizar', [$alertas, 'sincronizar']);
$router->post('/rh/alertas/marcar-todos-lidos', [$alertas, 'marcarTodosLidos']);
$router->post('/rh/alertas/{id}/lido', [$alertas, 'marcarLido']);
$router->post('/rh/alertas/{id}/dispensar', [$alertas, 'dispensar']);
$router->post('/rh/alertas/{id}/atribuir', [$alertas, 'atribuir']);
$router->get('/rh/alertas/config', [$alertasConfig, 'index']);
$router->post('/rh/alertas/config/tipos', [$alertasConfig, 'salvarTipos']);
$router->post('/rh/alertas/config/destinos', [$alertasConfig, 'salvarDestinos']);
$router->post('/rh/alertas/config/geral', [$alertasConfig, 'salvarGeral']);
$router->post('/rh/alertas/config/testar-digest', [$alertasConfig, 'testarDigest']);
$router->post('/rh/alertas/config/executar-cobranca', [$alertasConfig, 'executarCobranca']);
$router->post('/rh/alertas/admin/resolver', [$alertasConfig, 'adminResolver']);
$router->post('/rh/alertas/admin/reatribuir', [$alertasConfig, 'adminReatribuir']);
$router->post('/rh/alertas/admin/dispensar', [$alertasConfig, 'adminDispensar']);

$router->get('/rh/efetivo', [$efetivo, 'index']);
$router->get('/rh/efetivo/exportar', [$efetivo, 'exportar']);

foreach ([
    'empresas' => 'empresas',
    'contratos' => 'contratos',
    'centros-custo' => 'centrosCusto',
    'ordens-servico' => 'ordensServico',
    'localidades' => 'localidades',
    'lotacoes' => 'lotacoes',
    'cargos' => 'cargos',
    'funcoes' => 'funcoes',
    'gestores-arcadis' => 'gestoresArcadis',
    'gestores-cliente' => 'gestoresCliente',
    'supervisores' => 'supervisores',
] as $slug => $listAction) {
    $router->get('/rh/' . $slug, [$cadastro, $listAction]);
    $router->get('/rh/' . $slug . '/novo', static function () use ($cadastro, $slug): void {
        (new $cadastro())->createForm($slug);
    });
    $router->post('/rh/' . $slug, static function () use ($cadastro, $slug): void {
        (new $cadastro())->store($slug);
    });
    $router->get('/rh/' . $slug . '/{id}/editar', static function (string $id) use ($cadastro, $slug): void {
        (new $cadastro())->editForm($slug, $id);
    });
    $router->post('/rh/' . $slug . '/{id}', static function (string $id) use ($cadastro, $slug): void {
        (new $cadastro())->update($slug, $id);
    });
    $router->post('/rh/' . $slug . '/{id}/excluir', static function (string $id) use ($cadastro, $slug): void {
        (new $cadastro())->destroy($slug, $id);
    });
}

$horario = HorarioController::class;
$router->get('/rh/horarios', [$horario, 'index']);
$router->get('/rh/horarios/novo', [$horario, 'novo']);
$router->post('/rh/horarios', [$horario, 'save']);
$router->get('/rh/horarios/{id}', [$horario, 'show']);
$router->post('/rh/horarios/{id}', [$horario, 'save']);

$router->get('/rh/calendarios', [$calendario, 'index']);
$router->get('/rh/calendarios/novo', [$calendario, 'create']);
$router->post('/rh/calendarios', [$calendario, 'store']);
$router->get('/rh/calendarios/{id}', [$calendario, 'edit']);
$router->post('/rh/calendarios/{id}', [$calendario, 'update']);
$router->post('/rh/calendarios/{id}/feriados', [$calendario, 'addFeriado']);
$router->post('/rh/calendarios/{id}/feriados/{feriadoId}/excluir', [$calendario, 'deleteFeriado']);
$router->post('/rh/calendarios/{id}/excluir', [$calendario, 'destroy']);

$router->get('/rh/localidades/{id}/feriados', [$calendario, 'porCidade']);
$router->post('/rh/localidades/{id}/feriados', [$calendario, 'addFeriadoCidade']);
$router->post('/rh/localidades/{id}/feriados/{feriadoId}/excluir', [$calendario, 'deleteFeriadoCidade']);

$router->get('/rh/colaboradores/novo', [$colaborador, 'createForm']);
$router->post('/rh/colaboradores', [$colaborador, 'store']);
// Rotas literais ANTES de {id}, senão "excluir-massa" cai em update((int)'excluir-massa'=0).
$router->post('/rh/colaboradores/excluir-massa', [$colaborador, 'excluirMassa']);
$router->get('/rh/colaboradores/{id}/editar', [$colaborador, 'editForm']);
$router->post('/rh/colaboradores/{id}/excluir', [$colaborador, 'excluir']);
$router->post('/rh/colaboradores/{id}/transferir', [$colaborador, 'transfer']);
$router->post('/rh/colaboradores/{id}/percentual-he', [$colaborador, 'atualizarPercentualHe']);
$router->post('/rh/colaboradores/{id}/excecoes', [$colaborador, 'storeExcecao']);
$router->post('/rh/colaboradores/{id}', [$colaborador, 'update']);
$router->get('/rh/colaboradores/{id}', [$colaborador, 'show']);

$router->get('/rh/importacoes', [$importacao, 'index']);
$router->get('/rh/importacoes/modelo', [$importacao, 'baixarModelo']);
$router->get('/rh/importacoes/nova', [$importacao, 'create']);
$router->post('/rh/importacoes', [$importacao, 'store']);
$router->get('/rh/importacoes/{id}', [$importacao, 'show']);
$router->post('/rh/importacoes/{id}/confirmar', [$importacao, 'confirm']);

$router->get('/rh/email', [$emailConta, 'index']);
$router->post('/rh/email/config', [$emailConta, 'salvarConfig']);
$router->get('/rh/email/conectar/{provedor}', [$emailConta, 'conectar']);
$router->post('/rh/email/conectar-smtp', [$emailConta, 'conectarSmtp']);
$router->get('/rh/email/oauth/{provedor}/callback', [$emailConta, 'callback']);
$router->post('/rh/email/desconectar', [$emailConta, 'desconectar']);
$router->post('/rh/email/ativar', [$emailConta, 'ativar']);
$router->post('/rh/email/testar', [$emailConta, 'testar']);
$router->get('/rh/email/template/preview', [$emailConta, 'previewTemplate']);
$router->post('/rh/email/template', [$emailConta, 'salvarTemplate']);
$router->post('/rh/email/template/reset', [$emailConta, 'resetTemplate']);

$router->get('/rh/usuarios', [$usuarios, 'index']);
$router->get('/rh/usuarios/novo', [$usuarios, 'createForm']);
$router->post('/rh/usuarios', [$usuarios, 'store']);
$router->get('/rh/usuarios/{id}/editar', [$usuarios, 'editForm']);
$router->post('/rh/usuarios/{id}', [$usuarios, 'update']);
$router->post('/rh/usuarios/{id}/excluir', [$usuarios, 'excluir']);
$router->post('/rh/usuarios/{id}/enviar-reset', [$usuarios, 'enviarReset']);

$router->get('/rh/atividade', [$atividade, 'index']);
$router->get('/rh/atividade/live.json', [$atividade, 'live']);
$router->post('/rh/atividade/heartbeat', [$atividade, 'heartbeat']);
$router->get('/rh/atividade/{id}', [$atividade, 'show']);

$router->get('/rh/perfis', [$perfisCtrl, 'index']);
$router->get('/rh/perfis/novo', [$perfisCtrl, 'createForm']);
$router->post('/rh/perfis', [$perfisCtrl, 'store']);
$router->get('/rh/perfis/{id}/editar', [$perfisCtrl, 'editForm']);
$router->post('/rh/perfis/{id}/duplicar', [$perfisCtrl, 'duplicar']);
$router->post('/rh/perfis/{id}/excluir', [$perfisCtrl, 'excluir']);
$router->post('/rh/perfis/{id}', [$perfisCtrl, 'update']);

$justTipos = \App\Controllers\Rh\JustificativaTipoController::class;
$router->get('/rh/ponto', [$ponto, 'index']);
$router->post('/rh/ponto/gerar', [$ponto, 'gerar']);
$router->post('/rh/ponto/periodo/excluir', [$ponto, 'excluirPeriodo']);
$router->post('/rh/ponto/lote-status', [$ponto, 'loteStatus']);
$router->get('/rh/ponto/justificativa-tipos', [$justTipos, 'index']);
$router->post('/rh/ponto/justificativa-tipos', [$justTipos, 'store']);
$router->post('/rh/ponto/justificativa-tipos/{id}/excluir', [$justTipos, 'excluir']);
$router->post('/rh/ponto/justificativa-tipos/{id}', [$justTipos, 'update']);
$router->get('/rh/ponto/anexos/{justificativaId}', [$ponto, 'baixarAnexo']);
$router->get('/rh/ponto/{id}/he-autorizacao/{anexoId}', [$ponto, 'baixarHeAutorizacao']);
$router->post('/rh/ponto/{id}/turno-autorizacao', [$ponto, 'salvarTurnoAutorizacao']);
$router->get('/rh/ponto/{id}/turno-autorizacao/{authId}', [$ponto, 'baixarTurnoAutorizacao']);
$router->post('/rh/ponto/{id}/turno-autorizacao/{authId}/excluir', [$ponto, 'excluirTurnoAutorizacao']);
$router->get('/rh/ponto/{id}', [$ponto, 'show']);
$router->post('/rh/ponto/{id}/dia', [$ponto, 'salvarDia']);
$router->post('/rh/ponto/{id}/justificativa', [$ponto, 'justificativa']);
$router->post('/rh/ponto/{id}/justificativa/{justificativaId}/excluir', [$ponto, 'excluirJustificativa']);
$router->post('/rh/ponto/{id}/ocorrencia', [$ponto, 'ocorrencia']);
$router->post('/rh/ponto/{id}/status', [$ponto, 'status']);
$router->post('/rh/ponto/{id}/apontar', [$ponto, 'apontar']);
$router->post('/rh/ponto/{id}/he-ra/{raDiaId}/aplicar', [$ponto, 'aplicarHeRa']);
$router->post('/rh/ponto/{id}/he-ra/{raDiaId}/divergente', [$ponto, 'divergenteHeRa']);
$router->post('/rh/ponto/{id}/he-cliente/aplicar-todas', [$ponto, 'heClienteAplicarTodas']);
$router->post('/rh/ponto/{id}/apontar-previsto', [$ponto, 'apontarPrevisto']);
$router->post('/rh/ponto/{id}/resolver-divergencia', [$ponto, 'resolverDivergencia']);
$router->post('/rh/ponto/{id}/resolver-divergencia-reenviar', [$ponto, 'resolverDivergenciaEReenviar']);
$router->post('/rh/ponto/{id}/folha/gerar', [$ponto, 'gerarFolhaPdf']);
$router->post('/rh/ponto/{id}/folha/enviar', [$ponto, 'enviarFolhaEmail']);

$heRelatorio = HeRelatorioController::class;
$router->get('/rh/relatorio-he', [$heRelatorio, 'index']);
$router->get('/rh/relatorio-he/excel', [$heRelatorio, 'exportarExcel']);
$router->post('/rh/relatorio-he/pendentes', [$heRelatorio, 'salvarPendente']);
$router->post('/rh/relatorio-he/pendentes/{id}/excluir', [$heRelatorio, 'excluirPendente']);

$raRh = RelatorioAtividadesController::class;
$router->get('/rh/relatorios-atividades', [$raRh, 'index']);
$router->post('/rh/relatorios-atividades/lote', [$raRh, 'acaoLote']);
$router->get('/rh/relatorios-atividades/{id}/pdf', [$raRh, 'pdf']);
$router->get('/rh/relatorios-atividades/{id}/excel', [$raRh, 'excel']);
$router->get('/rh/relatorios-atividades/{id}', [$raRh, 'show']);
$router->post('/rh/relatorios-atividades/{id}/aprovar', [$raRh, 'aprovar']);
$router->post('/rh/relatorios-atividades/{id}/reabrir', [$raRh, 'reabrir']);

$vrRh = ValeRefeicaoController::class;
$router->get('/rh/vale-refeicao', [$vrRh, 'index']);
$router->get('/rh/vale-refeicao/relatorio', [$vrRh, 'relatorio']);
$router->get('/rh/vale-refeicao/relatorio/pdf', [$vrRh, 'exportarPdf']);
$router->get('/rh/vale-refeicao/relatorio/excel', [$vrRh, 'exportarExcel']);
$router->get('/rh/vale-refeicao/exportar', [$vrRh, 'exportarCsv']);
$router->post('/rh/vale-refeicao/config', [$vrRh, 'salvarConfig']);
$router->post('/rh/vale-refeicao/config/{id}/excluir', [$vrRh, 'excluirConfig']);

$entregasRh = EntregaEquipamentoController::class;
$router->get('/rh/entregas-equipamentos', [$entregasRh, 'index']);
$router->get('/rh/entregas-equipamentos/nova', [$entregasRh, 'createForm']);
$router->post('/rh/entregas-equipamentos', [$entregasRh, 'store']);
$router->get('/rh/entregas-equipamentos/equipamentos', [$entregasRh, 'equipamentos']);
$router->post('/rh/entregas-equipamentos/equipamentos', [$entregasRh, 'salvarEquipamento']);
$router->post('/rh/entregas-equipamentos/equipamentos/{id}/excluir', [$entregasRh, 'excluirEquipamento']);
$router->get('/rh/entregas-equipamentos/perifericos', [$entregasRh, 'perifericos']);
$router->post('/rh/entregas-equipamentos/perifericos', [$entregasRh, 'salvarPeriferico']);
$router->post('/rh/entregas-equipamentos/perifericos/{id}/excluir', [$entregasRh, 'excluirPeriferico']);
$router->get('/rh/entregas-equipamentos/colaborador/{id}', [$entregasRh, 'colaboradorJson']);
$router->get('/rh/entregas-equipamentos/{id}/pdf', [$entregasRh, 'pdf']);
$router->get('/rh/entregas-equipamentos/{id}/anexo/{anexoId}', [$entregasRh, 'anexo']);
$router->get('/rh/entregas-equipamentos/{id}/emitir', [$entregasRh, 'emitir']);
$router->post('/rh/entregas-equipamentos/{id}/emitir', [$entregasRh, 'emitir']);
$router->post('/rh/entregas-equipamentos/{id}/anexar-assinado', [$entregasRh, 'anexarAssinado']);
$router->post('/rh/entregas-equipamentos/{id}/devolucao', [$entregasRh, 'devolucao']);
$router->post('/rh/entregas-equipamentos/{id}/cancelar', [$entregasRh, 'cancelar']);
$router->get('/rh/entregas-equipamentos/{id}', [$entregasRh, 'editForm']);
$router->post('/rh/entregas-equipamentos/{id}', [$entregasRh, 'update']);

$medicao = MedicaoController::class;
$router->get('/rh/medicao', [$medicao, 'index']);
$router->get('/rh/medicao/dossie', [$medicao, 'dossie']);
$router->post('/rh/medicao/lote/preencher-previstos', [$medicao, 'preencherPrevistosLote']);
$router->post('/rh/medicao/lote/conferir', [$medicao, 'conferirLote']);
$router->post('/rh/medicao/lote/fechar', [$medicao, 'fecharLote']);
$router->post('/rh/medicao/lote/pdf', [$medicao, 'gerarPdfLote']);
$router->post('/rh/medicao/lote/link', [$medicao, 'linkLote']);
$router->post('/rh/medicao/lote/email', [$medicao, 'emailLote']);
$router->post('/rh/medicao/lote/email-reenvio', [$medicao, 'emailReenvioLote']);

$router->get('/rh/folha', [$folha, 'index']);
$router->get('/rh/folha/documento/{id}', [$folha, 'download']);
$router->get('/rh/folha/{id}', [$folha, 'show']);
$router->post('/rh/folha/{id}/gerar', [$folha, 'gerar']);
$router->post('/rh/folha/{id}/enviar', [$folha, 'enviar']);
$router->post('/rh/folha/{id}/lembrar', [$folha, 'lembrar']);
$router->post('/rh/folha/{id}/link-assinatura', [$folha, 'gerarLinkAssinatura']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
