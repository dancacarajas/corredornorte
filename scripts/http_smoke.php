<?php

declare(strict_types=1);

$base = getenv('APP_URL') ?: 'http://127.0.0.1:8080';
$cookie = tempnam(sys_get_temp_dir(), 'arcadis_cookie_');

function req(string $method, string $url, string $cookie, ?array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [
        'code' => $code,
        'headers' => substr($raw, 0, $headerSize),
        'body' => substr($raw, $headerSize),
    ];
}

function assertContains(string $haystack, string $needle, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException("FAIL HTTP: {$label} (missing '{$needle}')");
    }
    echo "OK: {$label}\n";
}

try {
    $loginPage = req('GET', $base . '/login', $cookie);
    assertContains($loginPage['body'], 'Arcadis RH', 'Tela login');

    $login = req('POST', $base . '/login', $cookie, [
        'email' => 'admin@arcadis.local',
        'password' => 'admin123',
    ]);
    if ($login['code'] !== 302) {
        throw new RuntimeException('Login não redirecionou: HTTP ' . $login['code']);
    }
    echo "OK: Login admin\n";

    $pages = [
        '/' => 'Competência',
        '/rh/efetivo' => 'Fonte de verdade',
        '/rh/lotacoes' => 'Pacote operacional',
        '/rh/colaboradores/1' => 'Lotação vigente',
        '/rh/ponto' => 'Espelhos da competência',
        '/rh/ponto/1' => 'Espelho diário',
        '/rh/folha' => 'Documentos gerados',
        '/rh/folha/1' => 'Pré-visualização',
        '/rh/importacoes' => 'Histórico de importações',
        '/ui/showcase' => 'Design System Arcadis',
    ];

    foreach ($pages as $path => $needle) {
        $res = req('GET', $base . $path, $cookie);
        if ($res['code'] >= 400) {
            throw new RuntimeException("FAIL HTTP {$res['code']} em {$path}");
        }
        // follow one redirect if any
        if ($res['code'] === 302 && preg_match('/Location:\s*(\S+)/i', $res['headers'], $m)) {
            $loc = trim($m[1]);
            if (str_starts_with($loc, '/')) {
                $loc = $base . $loc;
            }
            $res = req('GET', $loc, $cookie);
        }
        assertContains($res['body'], $needle, $path);
    }

    echo "\nHTTP SMOKE PASSED\n";
    @unlink($cookie);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    @unlink($cookie);
    exit(1);
}
