<?php
$cookie = tempnam(sys_get_temp_dir(), 'c');
$ch = curl_init('http://127.0.0.1:8080/login');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => 'email=admin@arcadis.local&password=admin123',
    CURLOPT_FOLLOWLOCATION => true,
]);
curl_exec($ch);

$ch = curl_init('http://127.0.0.1:8080/rh/jornadas/novo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR => $cookie,
    CURLOPT_COOKIEFILE => $cookie,
]);
$body = (string) curl_exec($ch);
echo (str_contains($body, 'Jornadas') && str_contains($body, 'Salvar')) ? "CRUD_FORM_OK\n" : "CRUD_FORM_FAIL\n";
@unlink($cookie);
