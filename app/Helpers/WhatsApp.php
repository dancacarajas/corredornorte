<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Links e mensagens WhatsApp (api.whatsapp.com).
 * Emojis via escapes HTML para o arquivo-fonte ficar ASCII-safe.
 */
final class WhatsApp
{
    /** @return non-empty-string */
    private static function e(string $codepoint): string
    {
        return html_entity_decode('&#x' . $codepoint . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Normaliza telefone BR para dígitos com DDI 55.
     */
    public static function normalizeBrPhone(?string $telefone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $telefone) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            return $digits;
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55' . $digits;
        }

        if (strlen($digits) >= 12) {
            return $digits;
        }

        return null;
    }

    /**
     * URL absoluta da imagem OG (rota PHP com headers amigáveis ao crawler).
     */
    public static function logoUrl(?string $rootPath = null): string
    {
        return rtrim(AppUrl::base($rootPath), '/') . '/og/arcadis-folha.jpg';
    }

    /**
     * Converte /assinar/{token} em /s/{token} (landing com prévia da logo).
     */
    public static function shareUrlFromPortal(string $portalUrl, ?string $rootPath = null): string
    {
        $portalUrl = trim($portalUrl);
        if ($portalUrl !== '' && preg_match('~/assinar/([^/?#]+)~', $portalUrl, $m) === 1) {
            return rtrim(AppUrl::base($rootPath), '/') . '/s/' . rawurlencode(rawurldecode($m[1]));
        }

        return $portalUrl;
    }

    /**
     * Monta URL api.whatsapp.com com texto pré-preenchido.
     */
    public static function chatUrl(?string $telefone, string $mensagem): ?string
    {
        $phone = self::normalizeBrPhone($telefone);
        if ($phone === null) {
            return null;
        }

        $text = trim($mensagem);
        $qs = 'phone=' . rawurlencode($phone);
        if ($text !== '') {
            $qs .= '&text=' . rawurlencode($text);
        }

        return 'https://api.whatsapp.com/send?' . $qs;
    }

    /**
     * Payload para abertura via JS (evita corrupção de emoji no atributo href).
     *
     * @return array{phone:string,message:string,url:string}|null
     */
    public static function chatPayload(?string $telefone, string $mensagem): ?array
    {
        $phone = self::normalizeBrPhone($telefone);
        if ($phone === null) {
            return null;
        }

        $message = trim($mensagem);
        $url = self::chatUrl($phone, $message);
        if ($url === null) {
            return null;
        }

        return [
            'phone' => $phone,
            'message' => $message,
            'url' => $url,
        ];
    }

    public static function mensagemEnvioFolha(string $nome, string $periodo, string $portalUrl): string
    {
        return self::mensagem('envio', $nome, $periodo, $portalUrl);
    }

    public static function mensagemLembreteFolha(string $nome, string $periodo, string $portalUrl): string
    {
        return self::mensagem('lembrete', $nome, $periodo, $portalUrl);
    }

    private static function mensagem(string $tipo, string $nome, string $periodo, string $portalUrl): string
    {
        $nome = trim($nome) !== '' ? trim($nome) : 'colaborador(a)';
        $periodo = trim($periodo) !== '' ? trim($periodo) : 'período atual';
        $ctaUrl = self::shareUrlFromPortal($portalUrl);

        $building = self::e('1F3E2');
        $wave = self::e('1F44B');
        $memo = self::e('1F4DD');
        $alarm = self::e('23F0');
        $calendar = self::e('1F4C5');
        $point = self::e('1F449');

        $titulo = $tipo === 'lembrete'
            ? "{$alarm} *Lembrete — assinatura pendente*"
            : "{$memo} *Folha de Ponto pronta*";
        $corpo = $tipo === 'lembrete'
            ? 'Sua Folha de Ponto ainda aguarda sua assinatura eletrônica.'
            : 'Sua folha está disponível para conferência e assinatura eletrônica.';
        $cta = $tipo === 'lembrete'
            ? "{$point} Toque no link abaixo para concluir:"
            : "{$point} Toque no link abaixo para acessar:";

        // Um único link = landing /s/{token} com Open Graph da logo Arcadis.
        return implode("\n", [
            "{$building} *Arcadis RH — Corredor Norte*",
            '',
            "Olá, *{$nome}*! {$wave}",
            '',
            $titulo,
            "{$calendar} Período: *{$periodo}*",
            '',
            $corpo,
            '',
            $cta,
            $ctaUrl,
            '',
            '_Arcadis RH — Corredor Norte_',
        ]);
    }
}
