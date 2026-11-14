<?php

declare(strict_types=1);

namespace App\Helpers;

use RuntimeException;

final class UploadGuard
{
    /**
     * Valida extensão, tamanho, upload HTTP e MIME real via finfo.
     *
     * @param array<string,mixed> $file Entrada de $_FILES[...]
     * @param list<string> $allowedExtensions Extensões minúsculas sem ponto
     * @param list<string> $allowedMimes Tipos MIME permitidos
     * @return array{ext:string,mime:string}
     */
    public static function validate(
        array $file,
        array $allowedExtensions,
        array $allowedMimes,
        int $maxBytes = 10485760
    ): array {
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha no envio do arquivo.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Origem do arquivo inválida.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > $maxBytes) {
            $mb = (int) ceil($maxBytes / 1048576);
            throw new RuntimeException("Arquivo inválido ou maior que {$mb} MB.");
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
            throw new RuntimeException('Extensão de arquivo não permitida (' . implode(', ', $allowedExtensions) . ').');
        }

        $mime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $tmp);
                finfo_close($finfo);
                if (is_string($detected)) {
                    $mime = $detected;
                }
            }
        }
        if ($mime === '' && function_exists('mime_content_type')) {
            $detected = @mime_content_type($tmp);
            if (is_string($detected)) {
                $mime = $detected;
            }
        }

        if ($mime !== '' && !in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException('Tipo MIME de arquivo não permitido.');
        }

        return ['ext' => $ext, 'mime' => $mime];
    }
}
