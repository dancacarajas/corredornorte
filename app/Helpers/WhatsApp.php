<?php

declare(strict_types=1);

namespace App\Helpers;

final class WhatsApp
{
    /**
     * Normaliza telefone BR para dígitos com DDI 55 (wa.me).
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

        // DDD + número (10 ou 11 dígitos)
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '55' . $digits;
        }

        // Já veio com 55 incompleto ou formato inesperado
        if (strlen($digits) >= 12) {
            return $digits;
        }

        return null;
    }

    /**
     * Monta URL wa.me com texto pré-preenchido.
     */
    public static function chatUrl(?string $telefone, string $mensagem): ?string
    {
        $phone = self::normalizeBrPhone($telefone);
        if ($phone === null) {
            return null;
        }

        $text = trim($mensagem);
        $qs = $text !== '' ? ('?text=' . rawurlencode($text)) : '';

        return 'https://wa.me/' . $phone . $qs;
    }

    /**
     * Mensagem de 1º envio (etapa 6) — formatação WhatsApp (*negrito*).
     */
    public static function mensagemEnvioFolha(string $nome, string $periodo, string $portalUrl): string
    {
        $nome = trim($nome) !== '' ? trim($nome) : 'colaborador(a)';
        $periodo = trim($periodo) !== '' ? trim($periodo) : 'período atual';
        $portalUrl = trim($portalUrl);

        return implode("\n", [
            '🏢 *Arcadis RH — Corredor Norte*',
            '',
            "Olá, *{$nome}*! 👋",
            '',
            '📝 *Folha de Ponto pronta*',
            "📅 Período: *{$periodo}*",
            '',
            'Sua folha está disponível para conferência e assinatura eletrônica.',
            '',
            '👉 Toque no link abaixo para acessar:',
            $portalUrl,
            '',
            '_Arcadis RH · Corredor Norte_',
        ]);
    }

    /**
     * Mensagem de lembrete (etapa 7).
     */
    public static function mensagemLembreteFolha(string $nome, string $periodo, string $portalUrl): string
    {
        $nome = trim($nome) !== '' ? trim($nome) : 'colaborador(a)';
        $periodo = trim($periodo) !== '' ? trim($periodo) : 'período atual';
        $portalUrl = trim($portalUrl);

        return implode("\n", [
            '🏢 *Arcadis RH — Corredor Norte*',
            '',
            "Olá, *{$nome}*! 👋",
            '',
            '⏰ *Lembrete — assinatura pendente*',
            "📅 Período: *{$periodo}*",
            '',
            'Sua Folha de Ponto ainda aguarda sua assinatura eletrônica.',
            '',
            '👉 Toque no link abaixo para concluir:',
            $portalUrl,
            '',
            '_Arcadis RH · Corredor Norte_',
        ]);
    }
}
