<?php
$c = tempnam(sys_get_temp_dir(), 'c');
$ch = curl_init('http://127.0.0.1:8080/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $c,
    CURLOPT_COOKIEFILE => $c,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'email=admin@arcadis.local&password=admin123',
    CURLOPT_FOLLOWLOCATION => true,
]);
curl_exec($ch);

$pages = [
    '/rh/calendarios' => 'Calendários',
    '/rh/escalas/3' => 'Escala por ciclo',
    '/rh/efetivo?contrato_id=1' => 'Consulta do efetivo',
    '/rh/colaboradores/1' => 'Exceções individuais',
    '/rh/folha/1' => 'Gerar link assinatura',
];
foreach ($pages as $path => $needle) {
    $ch = curl_init('http://127.0.0.1:8080' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $c,
        CURLOPT_COOKIEFILE => $c,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code >= 400 || !str_contains($body, $needle)) {
        fwrite(STDERR, "FAIL {$path} code={$code}\n");
        exit(1);
    }
    echo "OK: {$path}\n";
}
echo "EXTRA PAGES OK\n";
@unlink($c);
