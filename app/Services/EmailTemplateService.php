<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Templates editáveis (DB) com fallback para defaults.
 * Placeholders: {{nome}}, {{periodo}}, {{cta_bloco}}, etc.
 */
final class EmailTemplateService
{
    /**
     * Catálogo fixo — chave, rótulo, gatilho e variáveis.
     *
     * @return array<string,array{label:string,trigger:string,vars:list<string>}>
     */
    public static function catalog(): array
    {
        return [
            'folha.envio_colaborador' => [
                'label' => 'Envio da folha de ponto',
                'trigger' => 'Enviar PDF (folha unitária e lote da medição)',
                'vars' => ['nome', 'matricula', 'periodo', 'competencia', 'codigo', 'portal_url', 'matricula_badge', 'codigo_linha', 'cta_bloco', 'link_fallback'],
            ],
            'sistema.email_teste' => [
                'label' => 'Teste de conexão',
                'trigger' => 'Botão “Testar envio” em Conta de e-mail',
                'vars' => ['destinatario', 'conta_email', 'conta_linha'],
            ],
        ];
    }

    /**
     * @return array{assunto:string,titulo:string,texto:string,corpo_html:string,rodape:string}
     */
    public static function defaults(string $key): array
    {
        $key = self::normalizeKey($key);

        return match ($key) {
            'folha.envio_colaborador' => [
                'assunto' => 'Folha de Ponto ({{competencia}}) — {{nome}}',
                'titulo' => 'Folha de Ponto para Conferência',
                'texto' => "Olá, {{nome}},\n\nSua Folha de Ponto ({{periodo}}) está pronta para conferência.\n{{codigo_txt}}\n{{portal_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
  <tr>
    <td style="font-size:15px;line-height:1.6;color:#1E293B;padding-bottom:16px;">
      Olá, <strong>{{nome}}</strong>.{{matricula_badge}}
      <p style="margin:12px 0 0;">Sua folha de ponto referente ao período indicado abaixo está disponível. Confira os dias trabalhados, horas extras e ocorrências e registre sua assinatura eletrônica.</p>
    </td>
  </tr>
  <tr>
    <td style="padding-bottom:20px;">
      <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;">
        <tr>
          <td style="padding:10px 12px;font-size:13px;color:#0F172A;"><strong>Período:</strong> {{periodo}}</td>
        </tr>
        {{codigo_linha}}
      </table>
    </td>
  </tr>
  {{cta_bloco}}
  {{link_fallback}}
</table>
HTML,
                'rodape' => 'Arcadis RH — O PDF da folha também segue anexado para sua guarda. Em caso de dúvida, contate o RH pelo portal.',
            ],
            'sistema.email_teste' => [
                'assunto' => 'Arcadis RH — teste de conexão de e-mail',
                'titulo' => 'Teste de conexão de e-mail',
                'texto' => "Este é um e-mail de teste do módulo Conta de e-mail do Arcadis RH.\n\nSe você recebeu, a conta está funcionando.\n{{conta_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
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
          <td style="padding:10px 12px;font-size:13px;color:#0F172A;"><strong>Destinatário:</strong> {{destinatario}}</td>
        </tr>
        {{conta_linha}}
      </table>
    </td>
  </tr>
</table>
HTML,
                'rodape' => 'Arcadis RH — Corredor Norte. Mensagem automática de diagnóstico.',
            ],
            default => throw new RuntimeException('Template desconhecido: ' . $key),
        };
    }

    /**
     * Conteúdo efetivo para edição (DB ou default).
     *
     * @return array{chave:string,label:string,trigger:string,vars:list<string>,assunto:string,titulo:string,texto:string,corpo_html:string,rodape:string,customizado:bool,atualizado_em:?string}
     */
    public static function loadForEdit(?PDO $pdo, string $key): array
    {
        $key = self::normalizeKey($key);
        $meta = self::catalog()[$key] ?? null;
        if ($meta === null) {
            throw new RuntimeException('Template desconhecido: ' . $key);
        }
        $base = self::defaults($key);
        $row = self::fetchRow($pdo, $key);
        $custom = $row !== null;

        return [
            'chave' => $key,
            'label' => $meta['label'],
            'trigger' => $meta['trigger'],
            'vars' => $meta['vars'],
            'assunto' => $custom ? (string) $row['assunto'] : $base['assunto'],
            'titulo' => $custom ? (string) $row['titulo'] : $base['titulo'],
            'texto' => $custom ? (string) $row['texto'] : $base['texto'],
            'corpo_html' => $custom ? (string) $row['corpo_html'] : $base['corpo_html'],
            'rodape' => $custom ? (string) $row['rodape'] : $base['rodape'],
            'customizado' => $custom,
            'atualizado_em' => $custom ? (string) ($row['atualizado_em'] ?? '') : null,
        ];
    }

    /**
     * @return list<array{chave:string,label:string,trigger:string,customizado:bool,atualizado_em:?string}>
     */
    public static function listForUi(?PDO $pdo): array
    {
        $out = [];
        foreach (self::catalog() as $key => $meta) {
            $row = self::fetchRow($pdo, $key);
            $out[] = [
                'chave' => $key,
                'label' => $meta['label'],
                'trigger' => $meta['trigger'],
                'customizado' => $row !== null,
                'atualizado_em' => $row !== null ? (string) ($row['atualizado_em'] ?? '') : null,
            ];
        }

        return $out;
    }

    /**
     * @param array{assunto:string,titulo:string,texto:string,corpo_html:string,rodape:string} $data
     */
    public static function save(PDO $pdo, string $key, array $data, ?int $userId): void
    {
        $key = self::normalizeKey($key);
        if (!isset(self::catalog()[$key])) {
            throw new RuntimeException('Template desconhecido: ' . $key);
        }

        $assunto = trim((string) ($data['assunto'] ?? ''));
        $titulo = trim((string) ($data['titulo'] ?? ''));
        $texto = (string) ($data['texto'] ?? '');
        $corpo = (string) ($data['corpo_html'] ?? '');
        $rodape = trim((string) ($data['rodape'] ?? ''));

        if ($assunto === '' || $titulo === '' || trim($texto) === '' || trim($corpo) === '') {
            throw new RuntimeException('Preencha assunto, título, texto e corpo HTML.');
        }

        $pdo->prepare(
            'INSERT INTO email_templates (chave, assunto, titulo, texto, corpo_html, rodape, atualizado_por, atualizado_em)
             VALUES (:k, :a, :t, :x, :c, :r, :u, NOW())
             ON DUPLICATE KEY UPDATE
                assunto = VALUES(assunto),
                titulo = VALUES(titulo),
                texto = VALUES(texto),
                corpo_html = VALUES(corpo_html),
                rodape = VALUES(rodape),
                atualizado_por = VALUES(atualizado_por),
                atualizado_em = NOW()'
        )->execute([
            'k' => $key,
            'a' => mb_substr($assunto, 0, 255),
            't' => mb_substr($titulo, 0, 160),
            'x' => $texto,
            'c' => $corpo,
            'r' => $rodape,
            'u' => $userId,
        ]);
    }

    public static function reset(PDO $pdo, string $key): void
    {
        $key = self::normalizeKey($key);
        $pdo->prepare('DELETE FROM email_templates WHERE chave = :k')->execute(['k' => $key]);
    }

    /**
     * @param array<string,mixed> $vars
     * @return array{subject:string,text:string,html:string}
     */
    public static function render(string $key, array $vars = [], ?PDO $pdo = null): array
    {
        $key = self::normalizeKey($key);
        if ($pdo === null) {
            $pdo = \App\Helpers\Db::tryConnection();
        }
        $tpl = self::loadForEdit($pdo, $key);
        $map = self::buildPlaceholderMap($key, $vars);

        $subject = self::interpolate($tpl['assunto'], $map, false);
        $text = self::interpolate($tpl['texto'], $map, false);
        $title = self::interpolate($tpl['titulo'], $map, false);
        $body = self::interpolate($tpl['corpo_html'], $map, true);
        $footer = self::interpolate($tpl['rodape'], $map, false);

        if (trim($subject) === '' || trim($text) === '') {
            throw new RuntimeException('Template de e-mail sem subject/text: ' . $key);
        }

        return [
            'subject' => $subject,
            'text' => $text,
            'html' => self::wrapLayout($title, $body, $footer),
        ];
    }

    /**
     * @param array<string,mixed> $vars
     * @return array<string,string>
     */
    private static function buildPlaceholderMap(string $key, array $vars): array
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($key === 'folha.envio_colaborador') {
            $nome = trim((string) ($vars['nome'] ?? 'Colaborador'));
            $matricula = trim((string) ($vars['matricula'] ?? ''));
            $periodo = trim((string) ($vars['periodo'] ?? ''));
            $competencia = trim((string) ($vars['competencia'] ?? ''));
            $codigo = trim((string) ($vars['codigo'] ?? ''));
            $portalUrl = trim((string) ($vars['portalUrl'] ?? $vars['portal_url'] ?? ''));
            if ($periodo === '') {
                $periodo = $competencia;
            }
            if ($competencia === '') {
                $competencia = $periodo;
            }

            $cta = '';
            $fallback = '';
            $portalTxt = '';
            if ($portalUrl !== '') {
                $urlEsc = $e($portalUrl);
                $cta = <<<HTML
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
                $fallback = <<<HTML
<tr>
  <td style="padding-top:16px;font-size:12px;line-height:1.5;color:#64748B;word-break:break-all;">
    Se o botão não abrir, copie e cole este endereço no navegador:<br>
    <a href="{$urlEsc}" style="color:#EA580C;text-decoration:underline;">{$urlEsc}</a>
  </td>
</tr>
HTML;
                $portalTxt = "\nPara revisar os apontamentos e assinar eletronicamente, acesse:\n{$portalUrl}\nCaso identifique qualquer divergência, utilize o botão \"Reportar divergência ao RH\" dentro do portal antes de assinar.\n";
            }

            return [
                'nome' => $nome,
                'matricula' => $matricula,
                'periodo' => $periodo,
                'competencia' => $competencia,
                'codigo' => $codigo,
                'portal_url' => $portalUrl,
                'matricula_badge' => $matricula !== ''
                    ? '<div style="font-size:13px;color:#64748B;margin-top:2px;">Matrícula: ' . $e($matricula) . '</div>'
                    : '',
                'codigo_linha' => $codigo !== ''
                    ? '<tr><td style="padding:10px 12px;border-top:1px solid #E2E8F0;font-size:13px;color:#0F172A;"><strong>Código:</strong> <span style="font-family:Consolas,monospace;font-weight:700;">' . $e($codigo) . '</span></td></tr>'
                    : '',
                'codigo_txt' => $codigo !== '' ? "Código de verificação: {$codigo}\n" : '',
                'cta_bloco' => $cta,
                'link_fallback' => $fallback,
                'portal_txt' => $portalTxt,
            ];
        }

        $dest = trim((string) ($vars['destinatario'] ?? ''));
        $conta = trim((string) ($vars['contaEmail'] ?? $vars['conta_email'] ?? ''));

        return [
            'destinatario' => $dest,
            'conta_email' => $conta,
            'conta_linha' => $conta !== ''
                ? '<tr><td style="padding:10px 12px;border-top:1px solid #E2E8F0;font-size:13px;color:#0F172A;"><strong>Conta:</strong> ' . $e($conta) . '</td></tr>'
                : '',
            'conta_txt' => $conta !== '' ? "Conta remetente: {$conta}\n" : '',
        ];
    }

    /**
     * @param array<string,string> $map
     */
    private static function interpolate(string $template, array $map, bool $htmlContext): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            static function (array $m) use ($map, $htmlContext): string {
                $k = strtolower($m[1]);
                if (!array_key_exists($k, $map)) {
                    return '';
                }
                $val = (string) $map[$k];
                // Blocos HTML pré-montados não escapam de novo
                if (in_array($k, ['cta_bloco', 'link_fallback', 'matricula_badge', 'codigo_linha', 'conta_linha'], true)) {
                    return $val;
                }
                if ($htmlContext) {
                    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }

                return $val;
            },
            $template
        );
    }

    /** @return ?array<string,mixed> */
    private static function fetchRow(?PDO $pdo, string $key): ?array
    {
        if (!$pdo instanceof PDO) {
            return null;
        }
        try {
            $st = $pdo->prepare('SELECT * FROM email_templates WHERE chave = :k LIMIT 1');
            $st->execute(['k' => $key]);
            $row = $st->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[a-z0-9]+(?:\.[a-z0-9_]+)+$/', $key)) {
            throw new RuntimeException('Chave de template de e-mail inválida.');
        }

        return $key;
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
