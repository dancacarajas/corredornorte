<?php

declare(strict_types=1);

/**
 * Template: sistema.email_teste
 *
 * @var string $destinatario
 * @var string $contaEmail
 */

$destinatario = trim((string) ($destinatario ?? ''));
$contaEmail = trim((string) ($contaEmail ?? ''));
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$subject = 'Arcadis RH — teste de conexão de e-mail';

$text = "Este é um e-mail de teste do módulo Conta de e-mail do Arcadis RH.\n\n"
    . "Se você recebeu, a conta está funcionando.\n";
if ($contaEmail !== '') {
    $text .= "Conta remetente: {$contaEmail}\n";
}
$text .= "\nArcadis RH — Corredor Norte";

$contaLinha = $contaEmail !== ''
    ? '<tr><td style="padding:10px 12px;border-top:1px solid #E2E8F0;font-size:13px;color:#0F172A;"><strong>Conta:</strong> ' . $e($contaEmail) . '</td></tr>'
    : '';

$body = <<<HTML
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
  <tr>
    <td style="font-size:15px;line-height:1.6;color:#1E293B;padding-bottom:16px;">
      <p style="margin:0;">Este é um e-mail de teste do módulo <strong>Conta de e-mail</strong>.</p>
      <p style="margin:12px 0 0;">Se você recebeu esta mensagem, a conexão está funcionando.</p>
    </td>
  </tr>
  <tr>
    <td>
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;">
        <tr>
          <td style="padding:10px 12px;font-size:13px;color:#0F172A;"><strong>Destinatário:</strong> {$e($destinatario)}</td>
        </tr>
        {$contaLinha}
      </table>
    </td>
  </tr>
</table>
HTML;

return [
    'subject' => $subject,
    'text' => $text,
    'title' => 'Teste de conexão de e-mail',
    'body' => $body,
    'footer' => 'Arcadis RH — Corredor Norte. Mensagem automática de diagnóstico.',
];
