<?php

declare(strict_types=1);

/**
 * Template: folha.envio_colaborador
 *
 * @var string $nome
 * @var string $matricula
 * @var string $periodo
 * @var string $competencia
 * @var string $codigo
 * @var ?string $portalUrl
 */

$nome = trim((string) ($nome ?? 'Colaborador'));
$matricula = trim((string) ($matricula ?? ''));
$periodo = trim((string) ($periodo ?? ''));
$competencia = trim((string) ($competencia ?? ''));
$codigo = trim((string) ($codigo ?? ''));
$portalUrl = isset($portalUrl) ? trim((string) $portalUrl) : '';
if ($periodo === '') {
    $periodo = $competencia;
}

$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$subject = 'Folha de Ponto (' . ($competencia !== '' ? $competencia : $periodo) . ') — ' . $nome;

$textLines = [
    "Olá, {$nome},",
    '',
    "Sua Folha de Ponto ({$periodo}) está pronta para conferência.",
];
if ($codigo !== '') {
    $textLines[] = "Código de verificação: {$codigo}";
}
if ($portalUrl !== '') {
    $textLines[] = '';
    $textLines[] = 'Para revisar os apontamentos e assinar eletronicamente, acesse:';
    $textLines[] = $portalUrl;
    $textLines[] = 'Caso identifique qualquer divergência, utilize o botão "Reportar divergência ao RH" dentro do portal antes de assinar.';
}
$textLines[] = '';
$textLines[] = 'Arcadis RH — Corredor Norte';
$text = implode("\n", $textLines);

$ctaHtml = '';
$fallbackLinkHtml = '';
if ($portalUrl !== '') {
    $urlEsc = $e($portalUrl);
    $ctaHtml = <<<HTML
<tr>
  <td style="padding:8px 0 20px;">
    <a href="{$urlEsc}" target="_blank" rel="noopener"
       style="display:inline-block;background-color:#EA580C;color:#FFFFFF;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:16px;font-weight:700;text-decoration:none;padding:14px 26px;border-radius:8px;">
      Conferir e Assinar Folha de Ponto
    </a>
  </td>
</tr>
<tr>
  <td style="padding:12px 14px;background-color:#FFF7ED;border-left:4px solid #EA580C;border-radius:6px;font-size:13px;line-height:1.5;color:#7C2D12;">
    <strong>Encontrou alguma divergência?</strong> Não assine com erro — abra o link acima e clique em <strong>Reportar divergência ao RH</strong> para correção antes da assinatura.
  </td>
</tr>
HTML;
    $fallbackLinkHtml = <<<HTML
<tr>
  <td style="padding-top:16px;font-size:12px;line-height:1.5;color:#64748B;word-break:break-all;">
    Se o botão não abrir, copie e cole este endereço no navegador:<br>
    <a href="{$urlEsc}" style="color:#EA580C;text-decoration:underline;">{$urlEsc}</a>
  </td>
</tr>
HTML;
}

$matriculaBadge = $matricula !== ''
    ? '<div style="font-size:13px;color:#64748B;margin-top:2px;">Matrícula: ' . $e($matricula) . '</div>'
    : '';
$codigoHtml = $codigo !== ''
    ? '<tr><td style="padding:10px 12px;border-top:1px solid #E2E8F0;font-size:13px;color:#0F172A;"><strong>Código:</strong> <span style="font-family:Consolas,monospace;font-weight:700;">' . $e($codigo) . '</span></td></tr>'
    : '';

$body = <<<HTML
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
  <tr>
    <td style="font-size:15px;line-height:1.6;color:#1E293B;padding-bottom:16px;">
      Olá, <strong>{$e($nome)}</strong>.{$matriculaBadge}
      <p style="margin:12px 0 0;">Sua folha de ponto referente ao período indicado abaixo está disponível. Confira os dias trabalhados, horas extras e ocorrências diretamente pelo celular ou computador e registre sua assinatura eletrônica.</p>
    </td>
  </tr>
  <tr>
    <td style="padding-bottom:20px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;">
        <tr>
          <td style="padding:10px 12px;font-size:13px;color:#0F172A;"><strong>Período:</strong> {$e($periodo)}</td>
        </tr>
        {$codigoHtml}
      </table>
    </td>
  </tr>
  {$ctaHtml}
  {$fallbackLinkHtml}
</table>
HTML;

return [
    'subject' => $subject,
    'text' => $text,
    'title' => 'Folha de Ponto para Conferência',
    'body' => $body,
    'footer' => 'Arcadis RH — O PDF da folha também segue anexado para sua guarda. Em caso de dúvida, contate o RH pelo portal.',
];
