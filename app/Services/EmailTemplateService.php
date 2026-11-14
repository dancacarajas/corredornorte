<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Templates de e-mail em app/Views/emails/{pasta}/{arquivo}.php
 * Chave: "pasta.arquivo" (ex.: folha.envio_colaborador).
 *
 * Cada template retorna:
 *   subject, text, title? (cabeçalho), body (HTML interno) ou html (documento completo).
 */
final class EmailTemplateService
{
    /**
     * @param array<string,mixed> $vars
     * @return array{subject:string,text:string,html:string}
     */
    public static function render(string $key, array $vars = []): array
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9]+(?:\.[a-z0-9_]+)+$/', $key)) {
            throw new RuntimeException('Chave de template de e-mail inválida.');
        }

        $rel = str_replace('.', '/', $key) . '.php';
        $file = dirname(__DIR__) . '/Views/emails/' . $rel;
        if (!is_file($file)) {
            throw new RuntimeException('Template de e-mail não encontrado: ' . $key);
        }

        /** @var mixed $raw */
        $raw = (static function (string $__file, array $vars) {
            extract($vars, EXTR_SKIP);

            return require $__file;
        })($file, $vars);

        if (!is_array($raw)) {
            throw new RuntimeException('Template de e-mail inválido: ' . $key);
        }

        $subject = trim((string) ($raw['subject'] ?? ''));
        $text = (string) ($raw['text'] ?? '');
        if ($subject === '' || $text === '') {
            throw new RuntimeException('Template de e-mail sem subject/text: ' . $key);
        }

        if (!empty($raw['html']) && is_string($raw['html'])) {
            $html = $raw['html'];
        } else {
            $html = self::wrapLayout(
                (string) ($raw['title'] ?? $subject),
                (string) ($raw['body'] ?? ''),
                (string) ($raw['footer'] ?? 'Arcadis RH — Corredor Norte')
            );
        }

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => $html,
        ];
    }

    private static function wrapLayout(string $title, string $body, string $footer): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleEsc = $e($title);
        $footerEsc = $e($footer);

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>{$titleEsc}</title>
</head>
<body style="margin:0;padding:0;background-color:#F1F5F9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F1F5F9;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:580px;background-color:#FFFFFF;border-radius:12px;border:1px solid #E2E8F0;overflow:hidden;">
          <tr>
            <td style="background-color:#0F172A;padding:20px 24px;border-bottom:4px solid #EA580C;">
              <div style="font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#FDBA74;">ARCADIS — CORREDOR NORTE</div>
              <div style="font-size:20px;font-weight:700;color:#FFFFFF;margin-top:4px;">{$titleEsc}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              {$body}
            </td>
          </tr>
          <tr>
            <td style="background-color:#F8FAFC;padding:14px 24px;border-top:1px solid #E2E8F0;font-size:12px;color:#64748B;">
              {$footerEsc}
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
