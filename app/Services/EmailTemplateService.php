<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\AppUrl;
use PDO;
use RuntimeException;

/**
 * Templates editáveis (DB) com fallback para defaults.
 * Layout visual padrão: Arcadis / Enboarder (logo, card branco, CTA laranja #e4610f).
 * Placeholders: {{nome}}, {{periodo}}, {{cta_bloco}}, etc.
 */
final class EmailTemplateService
{
    private const BRAND_ORANGE = '#e4610f';
    private const BG_GRAY = '#e1e1e1';
    private const TEXT = '#212121';

    /**
     * @return array<string,array{label:string,trigger:string,vars:list<string>}>
     */
    public static function catalog(): array
    {
        $folhaVars = ['nome', 'matricula', 'periodo', 'competencia', 'codigo', 'portal_url', 'matricula_linha', 'codigo_linha', 'cta_bloco', 'link_fallback', 'tentativa'];
        $cobrancaVars = ['nome', 'matricula', 'periodo', 'competencia', 'codigo', 'portal_url', 'matricula_linha', 'codigo_linha', 'cta_bloco', 'link_fallback', 'prazo_limite', 'nivel', 'documento_tipo'];

        return [
            'folha.envio_colaborador' => [
                'label' => 'Envio da folha de ponto',
                'group' => 'Folha de ponto',
                'trigger' => 'Etapa 6 da jornada — só com link de assinatura (PDF + link prontos)',
                'vars' => $folhaVars,
            ],
            'folha.reenvio_colaborador' => [
                'label' => 'Reenvio da folha de ponto',
                'group' => 'Folha de ponto',
                'trigger' => '2º envio em diante — também exige link de assinatura',
                'vars' => $folhaVars,
            ],
            'folha.lembrete_assinatura' => [
                'label' => 'Lembrete de assinatura',
                'group' => 'Folha de ponto',
                'trigger' => 'Botão “Enviar lembrete” na folha (status enviado, sem assinar)',
                'vars' => $folhaVars,
            ],
            'folha.cobranca_1' => [
                'label' => 'Cobrança 1 — Folha (dia 1)',
                'group' => 'Cobrança automática',
                'trigger' => 'Cron manhã — 1º dia útil após fechamento do período',
                'vars' => $cobrancaVars,
            ],
            'folha.cobranca_2' => [
                'label' => 'Cobrança 2 — Folha (dia 2 manhã)',
                'group' => 'Cobrança automática',
                'trigger' => 'Cron manhã — 2º dia útil (último dia do prazo)',
                'vars' => $cobrancaVars,
            ],
            'folha.cobranca_3' => [
                'label' => 'Cobrança 3 — Folha (prazo final)',
                'group' => 'Cobrança automática',
                'trigger' => 'Cron tarde — 2º dia útil (prazo final hoje)',
                'vars' => $cobrancaVars,
            ],
            'folha.divergencia_aviso_rh' => [
                'label' => 'Divergência reportada (RH)',
                'group' => 'Folha de ponto',
                'trigger' => 'Colaborador reporta divergência no portal /assinar',
                'vars' => ['nome', 'matricula', 'periodo', 'motivo', 'data_ocorrencia', 'link_rh', 'cta_bloco', 'link_fallback'],
            ],
            'folha.assinada_confirmacao' => [
                'label' => 'Confirmação de assinatura',
                'group' => 'Folha de ponto',
                'trigger' => 'Colaborador conclui a assinatura eletrônica',
                'vars' => ['nome', 'matricula', 'periodo', 'codigo', 'verificar_url', 'cta_bloco', 'link_fallback'],
            ],
            'ra.cobranca_1' => [
                'label' => 'Cobrança 1 — RA (dia 1)',
                'group' => 'Cobrança automática',
                'trigger' => 'Cron manhã — 1º dia útil após fechamento (RA pendente)',
                'vars' => $cobrancaVars,
            ],
            'ra.cobranca_2' => [
                'label' => 'Cobrança 2 — RA (dia 2 manhã)',
                'group' => 'Cobrança automática',
                'trigger' => 'Cron manhã — 2º dia útil (RA pendente)',
                'vars' => $cobrancaVars,
            ],
            'ra.cobranca_3' => [
                'label' => 'Cobrança 3 — RA (prazo final)',
                'group' => 'Cobrança automática',
                'trigger' => 'Cron tarde — 2º dia útil (prazo final RA)',
                'vars' => $cobrancaVars,
            ],
            'sistema.email_teste' => [
                'label' => 'Teste de conexão',
                'group' => 'Sistema',
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

        $rodapePadrao = 'Este e-mail contém informações pessoais. Se você não for o destinatário correto, por favor avise o RH.';

        return match ($key) {
            'folha.envio_colaborador' => [
                'assunto' => 'Folha de Ponto ({{competencia}}) — {{nome}}',
                'titulo' => 'Folha de Ponto',
                'texto' => "Oi {{nome}},\n\nEsperamos que você esteja bem hoje.\n\nSua Folha de Ponto ({{periodo}}) está pronta para conferência e assinatura eletrônica.{{codigo_txt}}{{portal_txt}}\nO PDF também segue anexado para sua guarda.\n\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p>Esperamos que você esteja bem hoje.</p>
<p>Sua folha de ponto do período <strong>{{periodo}}</strong> está pronta para conferência. Confira os dias trabalhados, horas extras e ocorrências e registre sua assinatura eletrônica.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Clique no botão abaixo para começar.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' O PDF da folha também segue anexado para sua guarda.',
            ],
            'folha.reenvio_colaborador' => [
                'assunto' => 'Reenvio — Folha de Ponto ({{competencia}}) — {{nome}}',
                'titulo' => 'Reenvio da Folha de Ponto',
                'texto' => "Oi {{nome}},\n\nEstamos reenviando sua Folha de Ponto ({{periodo}}) para conferência e assinatura.{{codigo_txt}}{{portal_txt}}\nO PDF atualizado segue anexado.\n\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p>Esperamos que você esteja bem hoje.</p>
<p>Estamos <strong>reenviando</strong> sua folha de ponto do período <strong>{{periodo}}</strong>. Use o link abaixo para conferir e assinar a via atual.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Clique no botão abaixo para continuar.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Este é um reenvio da via atual da folha.',
            ],
            'folha.lembrete_assinatura' => [
                'assunto' => 'Lembrete — assine sua Folha de Ponto ({{competencia}})',
                'titulo' => 'Lembrete de assinatura',
                'texto' => "Oi {{nome}},\n\nPassando para lembrar: sua Folha de Ponto ({{periodo}}) ainda aguarda sua assinatura eletrônica.{{codigo_txt}}{{portal_txt}}\n\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p>Esperamos que você esteja bem hoje.</p>
<p>Passando para lembrar: sua folha de ponto do período <strong>{{periodo}}</strong> ainda aguarda sua <strong>assinatura eletrônica</strong>.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Clique no botão abaixo para concluir.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao,
            ],
            'folha.cobranca_1' => [
                'assunto' => 'Assinatura pendente — Folha de Ponto ({{competencia}})',
                'titulo' => 'Assinatura pendente',
                'texto' => "Oi {{nome}},\n\nO período {{periodo}} foi encerrado e sua Folha de Ponto ainda não foi assinada.\nPrazo para assinatura: {{prazo_limite}} (2 dias úteis após o fechamento).{{codigo_txt}}{{portal_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p>O período <strong>{{periodo}}</strong> foi encerrado e sua <strong>Folha de Ponto</strong> ainda aguarda assinatura eletrônica.</p>
<p>O prazo máximo é <strong>{{prazo_limite}}</strong> (2 dias úteis após o fechamento). Fora disso, a entrega da medição atrasa.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Assine agora pelo botão abaixo.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Cobrança automática — dia 1 do prazo.',
            ],
            'folha.cobranca_2' => [
                'assunto' => 'Urgente — assine sua Folha de Ponto hoje ({{competencia}})',
                'titulo' => 'Último dia do prazo',
                'texto' => "Oi {{nome}},\n\nHoje ({{prazo_limite}}) é o último dia do prazo para assinar sua Folha de Ponto ({{periodo}}).\nApós essa data a entrega da medição fica atrasada.{{codigo_txt}}{{portal_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p><strong>Hoje ({{prazo_limite}}) é o último dia</strong> do prazo para assinar sua Folha de Ponto do período <strong>{{periodo}}</strong>.</p>
<p>Depois disso a entrega da medição fica atrasada.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Assine ainda hoje.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Cobrança automática — dia 2 (manhã).',
            ],
            'folha.cobranca_3' => [
                'assunto' => 'PRAZO FINAL hoje — Folha de Ponto ({{competencia}})',
                'titulo' => 'Prazo final hoje',
                'texto' => "Oi {{nome}},\n\nPRAZO FINAL: assine sua Folha de Ponto ({{periodo}}) ainda hoje ({{prazo_limite}}).\nSem a assinatura a medição atrasa.{{codigo_txt}}{{portal_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p><strong>Prazo final hoje ({{prazo_limite}})</strong>: sua Folha de Ponto do período <strong>{{periodo}}</strong> ainda não foi assinada.</p>
<p>Sem essa assinatura a entrega da medição atrasa.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Assine agora.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Cobrança automática — prazo final (tarde).',
            ],
            'ra.cobranca_1' => [
                'assunto' => 'Assinatura pendente — Relatório de Atividades ({{competencia}})',
                'titulo' => 'Assinatura pendente — RA',
                'texto' => "Oi {{nome}},\n\nO período {{periodo}} foi encerrado e seu Relatório de Atividades ainda não foi assinado.\nPrazo para assinatura: {{prazo_limite}} (2 dias úteis após o fechamento).{{codigo_txt}}{{portal_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p>O período <strong>{{periodo}}</strong> foi encerrado e seu <strong>Relatório de Atividades</strong> ainda aguarda assinatura eletrônica.</p>
<p>O prazo máximo é <strong>{{prazo_limite}}</strong> (2 dias úteis após o fechamento). Fora disso, a entrega da medição atrasa.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Assine agora pelo botão abaixo.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Cobrança automática RA — dia 1 do prazo.',
            ],
            'ra.cobranca_2' => [
                'assunto' => 'Urgente — assine seu Relatório de Atividades hoje ({{competencia}})',
                'titulo' => 'Último dia do prazo — RA',
                'texto' => "Oi {{nome}},\n\nHoje ({{prazo_limite}}) é o último dia do prazo para assinar seu Relatório de Atividades ({{periodo}}).\nApós essa data a entrega da medição fica atrasada.{{codigo_txt}}{{portal_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p><strong>Hoje ({{prazo_limite}}) é o último dia</strong> do prazo para assinar seu Relatório de Atividades do período <strong>{{periodo}}</strong>.</p>
<p>Depois disso a entrega da medição fica atrasada.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Assine ainda hoje.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Cobrança automática RA — dia 2 (manhã).',
            ],
            'ra.cobranca_3' => [
                'assunto' => 'PRAZO FINAL hoje — Relatório de Atividades ({{competencia}})',
                'titulo' => 'Prazo final hoje — RA',
                'texto' => "Oi {{nome}},\n\nPRAZO FINAL: assine seu Relatório de Atividades ({{periodo}}) ainda hoje ({{prazo_limite}}).\nSem a assinatura a medição atrasa.{{codigo_txt}}{{portal_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p><strong>Prazo final hoje ({{prazo_limite}})</strong>: seu Relatório de Atividades do período <strong>{{periodo}}</strong> ainda não foi assinado.</p>
<p>Sem essa assinatura a entrega da medição atrasa.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Assine agora.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Cobrança automática RA — prazo final (tarde).',
            ],
            'folha.divergencia_aviso_rh' => [
                'assunto' => 'Divergência na folha — {{nome}} ({{periodo}})',
                'titulo' => 'Divergência reportada',
                'texto' => "Olá RH,\n\n{{nome}} reportou uma divergência na Folha de Ponto ({{periodo}}).\n\nMotivo:\n{{motivo}}\n\nData da ocorrência: {{data_ocorrencia}}\n\nAcesse o ponto no sistema para analisar.\n{{portal_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Olá, equipe de RH,</p>
<p>O colaborador <strong>{{nome}}</strong> reportou uma divergência na folha de ponto do período <strong>{{periodo}}</strong>.</p>
{{matricula_linha}}
<p><strong>Data da ocorrência:</strong> {{data_ocorrencia}}</p>
<p><strong>Motivo informado:</strong></p>
<p>{{motivo}}</p>
<p>Acesse o sistema para analisar e tratar a divergência.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Notificação automática do portal de assinatura.',
            ],
            'folha.assinada_confirmacao' => [
                'assunto' => 'Folha assinada — {{periodo}}',
                'titulo' => 'Assinatura confirmada',
                'texto' => "Oi {{nome}},\n\nRecebemos sua assinatura eletrônica da Folha de Ponto ({{periodo}}).{{codigo_txt}}\nObrigado.\n\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi <strong>{{nome}}</strong>,</p>
<p>Esperamos que você esteja bem hoje.</p>
<p>Recebemos sua <strong>assinatura eletrônica</strong> da folha de ponto do período <strong>{{periodo}}</strong>.</p>
{{matricula_linha}}
{{codigo_linha}}
<p>Você pode validar o documento pelo link abaixo, se precisar.</p>
{{cta_bloco}}
{{link_fallback}}
HTML,
                'rodape' => $rodapePadrao . ' Guarde o PDF assinado para seus registros.',
            ],
            'sistema.email_teste' => [
                'assunto' => 'Arcadis RH — teste de conexão de e-mail',
                'titulo' => 'Teste de conexão',
                'texto' => "Oi,\n\nEste é um e-mail de teste do módulo Conta de e-mail do Arcadis RH.\n\nSe você recebeu, a conta está funcionando.\n{{conta_txt}}\nArcadis RH — Corredor Norte",
                'corpo_html' => <<<'HTML'
<p>Oi,</p>
<p>Esperamos que você esteja bem hoje.</p>
<p>Este é um e-mail de teste do módulo <strong>Conta de e-mail</strong>. Se você recebeu esta mensagem, a conexão está funcionando.</p>
<p><strong>Destinatário:</strong> {{destinatario}}</p>
{{conta_linha}}
HTML,
                'rodape' => $rodapePadrao . ' Mensagem automática de diagnóstico.',
            ],
            default => throw new RuntimeException('Template desconhecido: ' . $key),
        };
    }

    /**
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
            'group' => (string) ($meta['group'] ?? 'Outros'),
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
     * @return list<array{chave:string,label:string,group:string,trigger:string,customizado:bool,atualizado_em:?string}>
     */
    public static function listForUi(?PDO $pdo): array
    {
        $out = [];
        foreach (self::catalog() as $key => $meta) {
            $row = self::fetchRow($pdo, $key);
            $out[] = [
                'chave' => $key,
                'label' => $meta['label'],
                'group' => (string) ($meta['group'] ?? 'Outros'),
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
     * Variáveis de exemplo para pré-visualização no navegador.
     *
     * @return array<string,mixed>
     */
    public static function sampleVars(string $key): array
    {
        $key = self::normalizeKey($key);

        $folhaSample = [
            'nome' => 'Jarbas',
            'matricula' => '12345',
            'periodo' => '16/10/2026 a 15/11/2026',
            'competencia' => '2026-11',
            'codigo' => 'ABC-123',
            'portalUrl' => '#preview-assinatura',
            'tentativa' => '2',
        ];
        $cobrancaSample = $folhaSample + [
            'prazo_limite' => '17/11/2026',
            'nivel' => '1',
            'documento_tipo' => 'Folha de Ponto',
            'cta_label' => 'ASSINAR AGORA',
        ];

        return match ($key) {
            'folha.envio_colaborador',
            'folha.reenvio_colaborador',
            'folha.lembrete_assinatura' => $folhaSample,
            'folha.cobranca_1',
            'folha.cobranca_2',
            'folha.cobranca_3' => $cobrancaSample,
            'ra.cobranca_1',
            'ra.cobranca_2',
            'ra.cobranca_3' => array_merge($cobrancaSample, [
                'documento_tipo' => 'Relatório de Atividades',
                'portalUrl' => '#preview-assinatura-ra',
            ]),
            'folha.divergencia_aviso_rh' => [
                'nome' => 'Jarbas',
                'matricula' => '12345',
                'periodo' => '16/10/2026 a 15/11/2026',
                'motivo' => 'A hora de saída do dia 20/10 está diferente do ponto biométrico.',
                'data_ocorrencia' => '20/10/2026',
                'portalUrl' => '#preview-ponto-rh',
                'cta_label' => 'ABRA AGORA',
            ],
            'folha.assinada_confirmacao' => [
                'nome' => 'Jarbas',
                'matricula' => '12345',
                'periodo' => '16/10/2026 a 15/11/2026',
                'codigo' => 'ABC-123',
                'portalUrl' => '#preview-verificar',
                'cta_label' => 'VALIDAR DOCUMENTO',
            ],
            'sistema.email_teste' => [
                'destinatario' => 'seu@email.com',
                'contaEmail' => 'rh@empresa.com',
            ],
            default => [],
        };
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

        if (str_starts_with($key, 'folha.') || str_starts_with($key, 'ra.')) {
            $nome = trim((string) ($vars['nome'] ?? 'Colaborador'));
            $matricula = trim((string) ($vars['matricula'] ?? ''));
            $periodo = trim((string) ($vars['periodo'] ?? ''));
            $competencia = trim((string) ($vars['competencia'] ?? ''));
            $codigo = trim((string) ($vars['codigo'] ?? ''));
            $motivo = trim((string) ($vars['motivo'] ?? ''));
            $dataOcorrencia = trim((string) ($vars['data_ocorrencia'] ?? ''));
            $tentativa = trim((string) ($vars['tentativa'] ?? ''));
            $prazoLimite = trim((string) ($vars['prazo_limite'] ?? ''));
            $nivel = trim((string) ($vars['nivel'] ?? ''));
            $documentoTipo = trim((string) ($vars['documento_tipo'] ?? ''));
            $portalUrl = trim((string) ($vars['portalUrl'] ?? $vars['portal_url'] ?? $vars['link_rh'] ?? $vars['verificar_url'] ?? ''));
            $ctaLabel = trim((string) ($vars['cta_label'] ?? 'ABRA AGORA'));
            if ($ctaLabel === '') {
                $ctaLabel = 'ABRA AGORA';
            }
            if ($periodo === '') {
                $periodo = $competencia;
            }
            if ($competencia === '') {
                $competencia = $periodo;
            }
            if ($dataOcorrencia === '') {
                $dataOcorrencia = 'Não informada';
            }
            if ($documentoTipo === '') {
                $documentoTipo = str_starts_with($key, 'ra.') ? 'Relatório de Atividades' : 'Folha de Ponto';
            }

            $cta = '';
            $fallback = '';
            $portalTxt = '';
            if ($portalUrl !== '') {
                $cta = self::ctaButtonHtml($portalUrl, $ctaLabel);
                $fallback = self::ctaFallbackHtml($portalUrl);
                $portalTxt = "\n\nAcesse:\n{$portalUrl}\n";
            }

            return [
                'nome' => $nome,
                'matricula' => $matricula,
                'periodo' => $periodo,
                'competencia' => $competencia,
                'codigo' => $codigo,
                'motivo' => $motivo,
                'data_ocorrencia' => $dataOcorrencia,
                'tentativa' => $tentativa,
                'prazo_limite' => $prazoLimite,
                'nivel' => $nivel,
                'documento_tipo' => $documentoTipo,
                'portal_url' => $portalUrl,
                'link_rh' => $portalUrl,
                'verificar_url' => $portalUrl,
                'matricula_linha' => $matricula !== ''
                    ? '<p style="margin-top:12px;"><strong>Matrícula:</strong> ' . $e($matricula) . '</p>'
                    : '',
                'codigo_linha' => $codigo !== ''
                    ? '<p style="margin-top:12px;"><strong>Código de verificação:</strong> <span style="font-family:Consolas,monospace;font-weight:700;">' . $e($codigo) . '</span></p>'
                    : '',
                'codigo_txt' => $codigo !== '' ? "\nCódigo de verificação: {$codigo}\n" : '',
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
                ? '<p style="margin-top:12px;"><strong>Conta:</strong> ' . $e($conta) . '</p>'
                : '',
            'conta_txt' => $conta !== '' ? "Conta remetente: {$conta}\n" : '',
        ];
    }

    private static function ctaButtonHtml(string $url, string $label): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $urlEsc = $e($url);
        $labelEsc = $e($label);
        $orange = self::BRAND_ORANGE;

        return <<<HTML
<table width="100%" border="0" cellspacing="0" cellpadding="0" style="margin-top:24px;">
  <tr>
    <td align="center" style="padding-top:16px;">
      <table border="0" cellspacing="0" cellpadding="0">
        <tr>
          <td align="center" style="background-color:{$orange};border-radius:8px;padding:12px 18px;">
            <a href="{$urlEsc}" target="_blank" rel="noopener"
               style="font-size:18px;font-weight:bold;font-family:Helvetica,Arial,sans-serif;color:#FFFFFF;text-decoration:none;display:block;">
              <strong style="text-decoration:none;">{$labelEsc}</strong>
            </a>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
HTML;
    }

    private static function ctaFallbackHtml(string $url): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $urlEsc = $e($url);

        return <<<HTML
<p style="padding-top:24px;font-size:13px;line-height:1.5;color:#212121;text-align:center;">
  Caso não consiga visualizar ou clicar no botão acima, favor
  <a target="_blank" rel="noopener" href="{$urlEsc}" style="color:#212121;text-decoration:underline;">utilizar este link</a>.
</p>
HTML;
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
                if (in_array($k, ['cta_bloco', 'link_fallback', 'matricula_linha', 'codigo_linha', 'conta_linha', 'matricula_badge'], true)) {
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

    private static function logoUrl(): string
    {
        $app = require dirname(__DIR__, 2) . '/config/app.php';
        $base = rtrim(trim((string) ($app['url'] ?? '')), '/');
        if ($base === '') {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $host = AppUrl::sanitizeHost((string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8090'));
            $base = ($https ? 'https' : 'http') . '://' . $host;
        }
        if (str_ends_with(strtolower($base), '/public')) {
            $base = rtrim(substr($base, 0, -7), '/');
        }

        return $base . '/assets/img/logo-arcadis-email.png';
    }

    /**
     * Layout padrão Arcadis (referência Enboarder): fundo cinza, card branco, logo, CTA laranja.
     */
    private static function wrapLayout(string $title, string $body, string $footer): string
    {
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $titleEsc = $e($title !== '' ? $title : 'Arcadis');
        $footerEsc = $e($footer);
        $logo = $e(self::logoUrl());
        $bg = self::BG_GRAY;
        $text = self::TEXT;

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{$titleEsc}</title>
  <style type="text/css">
    body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
    table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
    img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }
    table { border-collapse: collapse !important; }
    body { background: {$bg}; height: 100% !important; margin: 0 !important; padding: 0 !important; width: 100% !important; }
    p, ul, ol { margin-bottom: 12px; font-weight: normal; color: {$text}; font-size: 18px; line-height: 1.6; }
    p { margin-top: 18px; }
    a { color: {$text}; text-decoration: underline; word-break: break-all; }
    a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }
    @media screen and (max-width: 525px) {
      .wrapper { width: 100% !important; max-width: 100% !important; }
      .responsive-table { width: 100% !important; }
      .img-max { max-width: 100% !important; height: auto !important; }
    }
  </style>
</head>
<body style="margin:0 !important;padding:0 !important;background-color:{$bg};">
  <table border="0" cellpadding="0" cellspacing="0" width="100%">
    <tr>
      <td bgcolor="{$bg}" align="center" style="padding-top:24px;">
        <!--[if (gte mso 9)|(IE)]>
        <table align="center" border="0" cellspacing="0" cellpadding="0" width="600"><tr><td align="center" valign="top" width="600">
        <![endif]-->
        <table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;" class="responsive-table wrapper" bgcolor="{$bg}">
          <tr>
            <td bgcolor="#ffffff">
              <table width="100%" border="0" cellspacing="0" cellpadding="0">
                <tr>
                  <td align="center" style="padding-top:24px;">
                    <img src="{$logo}" width="auto" height="120" border="0" alt="Arcadis"
                         style="display:block;max-height:120px;max-width:100%;color:{$text};font-family:Helvetica,Arial,sans-serif;font-size:16px;"
                         class="img-max">
                  </td>
                </tr>
                <tr>
                  <td align="left" style="font-size:18px;line-height:25px;font-family:Helvetica,Arial,sans-serif;color:{$text};padding:24px;">
                    {$body}
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
        <!--[if (gte mso 9)|(IE)]>
        </td></tr></table>
        <![endif]-->
      </td>
    </tr>
    <tr>
      <td align="center" style="padding:20px 25px;background-color:{$bg};">
        <!--[if (gte mso 9)|(IE)]>
        <table align="center" border="0" cellspacing="0" cellpadding="0" width="600"><tr><td align="center" valign="top" width="600">
        <![endif]-->
        <table width="100%" border="0" cellspacing="0" cellpadding="0" align="center" style="max-width:600px;" class="responsive-table">
          <tr>
            <td align="center" style="font-size:12px;line-height:18px;font-family:Helvetica,Arial,sans-serif;color:{$text};padding:0 25px;">
              {$footerEsc}
            </td>
          </tr>
        </table>
        <!--[if (gte mso 9)|(IE)]>
        </td></tr></table>
        <![endif]-->
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
