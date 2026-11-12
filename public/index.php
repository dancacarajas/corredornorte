<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\Rh\CadastroController;
use App\Controllers\Rh\CalendarioController;
use App\Controllers\Rh\ColaboradorController;
use App\Controllers\Rh\EfetivoController;
use App\Controllers\Rh\EmailContaController;
use App\Controllers\Rh\EscalaController;
use App\Controllers\Rh\FolhaController;
use App\Controllers\Rh\ImportacaoController;
use App\Controllers\Rh\PerfilController;
use App\Controllers\Rh\PontoController;
use App\Controllers\Rh\UsuarioController;
use App\Helpers\Auth;
use App\Helpers\Router;

$root = dirname(__DIR__);

require $root . '/vendor/autoload.php';

$config = require $root . '/config/app.php';
date_default_timezone_set($config['timezone']);

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

if (str_starts_with($path, '/assinar/')) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => str_starts_with($config['url'], 'https://')
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}
Auth::start();

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
$escala = EscalaController::class;
$emailConta = EmailContaController::class;
$usuarios = UsuarioController::class;
$perfisCtrl = PerfilController::class;

$router->get('/login', [$auth, 'showLogin']);
$router->post('/login', [$auth, 'login']);
$router->get('/logout', [$auth, 'logout']);
$router->get('/verificar/{codigo}', [$folha, 'verificar']);
$router->get('/assinar/{token}', [$folha, 'portalAssinatura']);
$router->get('/assinar/{token}/pdf', [$folha, 'portalPdf']);
$router->post('/assinar/{token}/assinar', [$folha, 'portalAssinar']);

$public = ['/login'];
$isPublic = in_array($normalizedPath, $public, true)
    || str_starts_with($path, '/assets')
    || str_starts_with($path, '/verificar/')
    || str_starts_with($path, '/assinar/')
    || str_starts_with($path, '/install');
if (!$isPublic) {
    Auth::requireLogin();
}

$router->get('/', [$home, 'index']);
$router->get('/ui/showcase', [$home, 'showcase']);

$router->get('/rh/efetivo', [$efetivo, 'index']);
$router->get('/rh/efetivo/exportar', [$efetivo, 'exportar']);

foreach ([
    'empresas' => 'empresas',
    'contratos' => 'contratos',
    'centros-custo' => 'centrosCusto',
    'ordens-servico' => 'ordensServico',
    'localidades' => 'localidades',
    'jornadas' => 'jornadas',
    'escalas' => 'escalas',
    'lotacoes' => 'lotacoes',
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
}

$router->get('/rh/escalas/{id}', [$escala, 'show']);
$router->post('/rh/escalas/{id}/ciclo', [$escala, 'saveCiclo']);

$router->get('/rh/calendarios', [$calendario, 'index']);
$router->get('/rh/calendarios/novo', [$calendario, 'create']);
$router->post('/rh/calendarios', [$calendario, 'store']);
$router->get('/rh/calendarios/{id}', [$calendario, 'edit']);
$router->post('/rh/calendarios/{id}', [$calendario, 'update']);
$router->post('/rh/calendarios/{id}/feriados', [$calendario, 'addFeriado']);
$router->post('/rh/calendarios/{id}/feriados/{feriadoId}/excluir', [$calendario, 'deleteFeriado']);

$router->get('/rh/localidades/{id}/feriados', [$calendario, 'porCidade']);
$router->post('/rh/localidades/{id}/feriados', [$calendario, 'addFeriadoCidade']);
$router->post('/rh/localidades/{id}/feriados/{feriadoId}/excluir', [$calendario, 'deleteFeriadoCidade']);

$router->get('/rh/colaboradores/novo', [$colaborador, 'createForm']);
$router->post('/rh/colaboradores', [$colaborador, 'store']);
$router->get('/rh/colaboradores/{id}/editar', [$colaborador, 'editForm']);
$router->post('/rh/colaboradores/{id}', [$colaborador, 'update']);
$router->get('/rh/colaboradores/{id}', [$colaborador, 'show']);
$router->post('/rh/colaboradores/{id}/transferir', [$colaborador, 'transfer']);
$router->post('/rh/colaboradores/{id}/excecoes', [$colaborador, 'storeExcecao']);

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

$router->get('/rh/usuarios', [$usuarios, 'index']);
$router->get('/rh/usuarios/novo', [$usuarios, 'createForm']);
$router->post('/rh/usuarios', [$usuarios, 'store']);
$router->get('/rh/usuarios/{id}/editar', [$usuarios, 'editForm']);
$router->post('/rh/usuarios/{id}', [$usuarios, 'update']);

$router->get('/rh/perfis', [$perfisCtrl, 'index']);
$router->get('/rh/perfis/novo', [$perfisCtrl, 'createForm']);
$router->post('/rh/perfis', [$perfisCtrl, 'store']);
$router->get('/rh/perfis/{id}/editar', [$perfisCtrl, 'editForm']);
$router->post('/rh/perfis/{id}', [$perfisCtrl, 'update']);

$router->get('/rh/ponto', [$ponto, 'index']);
$router->post('/rh/ponto/gerar', [$ponto, 'gerar']);
$router->post('/rh/ponto/justificativa-tipos', [$ponto, 'tipoJustificativa']);
$router->get('/rh/ponto/{id}', [$ponto, 'show']);
$router->post('/rh/ponto/{id}/dia', [$ponto, 'salvarDia']);
$router->post('/rh/ponto/{id}/justificativa', [$ponto, 'justificativa']);
$router->post('/rh/ponto/{id}/ocorrencia', [$ponto, 'ocorrencia']);
$router->post('/rh/ponto/{id}/status', [$ponto, 'status']);
$router->post('/rh/ponto/{id}/apontar', [$ponto, 'apontar']);

$router->get('/rh/folha', [$folha, 'index']);
$router->get('/rh/folha/documento/{id}', [$folha, 'download']);
$router->get('/rh/folha/{id}', [$folha, 'show']);
$router->post('/rh/folha/{id}/gerar', [$folha, 'gerar']);
$router->post('/rh/folha/{id}/enviar', [$folha, 'enviar']);
$router->post('/rh/folha/{id}/link-assinatura', [$folha, 'gerarLinkAssinatura']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
