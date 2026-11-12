<?php

declare(strict_types=1);

$base = 'http://127.0.0.1:8080';
$cookie = tempnam(sys_get_temp_dir(), 'arc');

function req(string $url, string $cookie, array $opts = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 20,
    ] + $opts);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    return ['code' => $code, 'headers' => substr((string) $raw, 0, $headerSize), 'body' => substr((string) $raw, $headerSize), 'err' => $err];
}

$loginPage = req($base . '/login', $cookie);
if ($loginPage['code'] !== 200) {
    fwrite(STDERR, "login page {$loginPage['code']} {$loginPage['err']}\n");
    exit(1);
}

$post = req($base . '/login', $cookie, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'email' => 'admin@arcadis.local',
        'password' => 'admin123',
    ]),
]);
echo "login={$post['code']}\n";

$ponto = req($base . '/rh/ponto?ano=2026&mes=11', $cookie);
echo "ponto_index={$ponto['code']} len=" . strlen($ponto['body']) . "\n";
echo 'has_gerar=' . (str_contains($ponto['body'], 'Gerar espelhos') ? '1' : '0') . "\n";

$show = req($base . '/rh/ponto/2', $cookie);
echo "ponto_show={$show['code']} len=" . strlen($show['body']) . "\n";
foreach (['Ent. 1', 'H. Previstas', 'Dia Falta', 'Adicionar justificativa', 'ponto.css', 'batida-form'] as $needle) {
    echo "show[{$needle}]=" . (str_contains($show['body'], $needle) ? '1' : '0') . "\n";
}

$folha = req($base . '/rh/folha/2', $cookie);
echo "folha_show={$folha['code']}\n";
$css = req($base . '/assets/css/ponto.css', $cookie);
echo "css={$css['code']} len=" . strlen($css['body']) . "\n";

@unlink($cookie);
echo "SMOKE OK\n";
