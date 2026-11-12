<?php

declare(strict_types=1);

namespace App\Services\EmailOAuth;

use RuntimeException;

final class OAuthHttp
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body
     * @return array{status:int,body:string,json:?array}
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $body = null,
        bool $form = false
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL indisponível.');
        }

        $hdrs = $headers;
        $payload = null;
        if (is_array($body)) {
            if ($form) {
                $payload = http_build_query($body);
                $hdrs[] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $hdrs[] = 'Content-Type: application/json';
            }
        } elseif (is_string($body)) {
            $payload = $body;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Falha HTTP OAuth: ' . $err);
        }

        $json = json_decode($raw, true);

        return [
            'status' => $status,
            'body' => $raw,
            'json' => is_array($json) ? $json : null,
        ];
    }
}
