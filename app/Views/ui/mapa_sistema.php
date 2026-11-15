<?php

use App\Helpers\Auth;
use App\Helpers\View;

$canRh = Auth::canAny(
    'dashboard.ver',
    'ponto.ver',
    'medicao.ver',
    'ra.ver',
    'folha.ver',
    'efetivo.ver'
);

$journeys = [
    'rh' => [
        'label' => 'Guia do RH',
        'footer_primary' => [
            'label' => 'Abrir a Medição',
            'href' => '/rh/medicao',
        ],
        'steps' => [
            [
                'id' => 'base',
                'title' => 'Preparar a base',
                'sub' => 'Pessoas e lotação',
                'actor' => 'RH',
                'lead' => 'Cadastre o colaborador e a lotação vigente no contrato. É o ponto de partida de tudo.',
                'does' => [
                    'Manter o efetivo atualizado',
                    'Conferir lotação, contrato e jornada',
                    'Importar a planilha quando houver muitos cadastros',
                ],
                'unlocks' => 'Com a base certa, o Ponto gera os espelhos corretamente.',
                'chain' => ['Efetivo', 'Contrato', 'Lotação'],
                'links' => [
                    ['label' => 'Abrir o Efetivo', 'href' => '/rh/efetivo', 'primary' => true],
                    ['label' => 'Abrir Contratos', 'href' => '/rh/contratos'],
                    ['label' => 'Abrir Importações', 'href' => '/rh/importacoes'],
                ],
            ],
            [
                'id' => 'gerar',
                'title' => 'Gerar o período',
                'sub' => 'Gestão de Ponto',
                'actor' => 'RH',
                'lead' => 'Gere o período de ponto. O sistema cria um espelho por colaborador e inicia o Relatório de Atividades (RA).',
                'does' => [
                    'Escolher as datas do ciclo',
                    'Gerar os espelhos do período',
                    'Abrir um espelho para revisar',
                ],
                'unlocks' => 'Depois disso, o colaborador pode preencher o relatório e o RH passa a tratar o espelho.',
                'chain' => ['Período', 'Espelhos', 'RA iniciado'],
                'links' => [
                    ['label' => 'Revisar espelhos no Ponto', 'href' => '/rh/ponto', 'primary' => true],
                    ['label' => 'Ver pendências na Visão geral', 'href' => '/'],
                ],
            ],
            [
                'id' => 'coletar',
                'title' => 'Receber o relatório',
                'sub' => 'Aguardando o colaborador',
                'actor' => 'RH e colaborador',
                'lead' => 'O colaborador preenche o Relatório de Atividades. Se houver horas extras no fluxo do cliente, elas entram neste mesmo passo.',
                'does' => [
                    'Acompanhar quem ainda não entregou o relatório',
                    'Orientar o acesso pelo portal do colaborador',
                    'Conferir no espelho o que foi declarado',
                ],
                'unlocks' => 'Sem o relatório entregue (e as horas extras tratadas, quando houver), a conferência fica bloqueada.',
                'chain' => ['Portal', 'Relatório', 'Conferência'],
                'default_fork' => 'com_he',
                'forks' => [
                    'com_he' => [
                        'choice_title' => 'Com horas extras',
                        'choice_text' => 'O colaborador declara as horas extras no relatório. O RH precisa tratá-las no espelho.',
                        'title' => 'Receber o relatório e as horas extras',
                        'sub' => 'Relatório + horas extras',
                        'actor' => 'RH e colaborador',
                        'lead' => 'O colaborador preenche o Relatório de Atividades (RA) e declara as horas extras (HE), quando aplicável. O RH acompanha a entrega e confere as HE no espelho de ponto.',
                        'does' => [
                            'Acompanhar a entrega do relatório',
                            'Orientar o colaborador no portal',
                            'Ver as horas extras declaradas no espelho',
                        ],
                        'unlocks' => 'As horas extras precisam ser aplicadas (ou marcadas como divergência) antes da conferência.',
                        'chain' => ['Portal', 'Relatório', 'Horas extras', 'Espelho'],
                        'links' => [
                            ['label' => 'Abrir o portal do colaborador', 'href' => '/he/login', 'primary' => true],
                            ['label' => 'Ver relatórios no RH', 'href' => '/rh/relatorios-atividades'],
                            ['label' => 'Revisar espelhos no Ponto', 'href' => '/rh/ponto'],
                        ],
                    ],
                    'sem_he' => [
                        'choice_title' => 'Sem horas extras',
                        'choice_text' => 'Só há atividades no relatório. Depois da entrega, o RH confere o espelho.',
                        'title' => 'Receber o Relatório de Atividades',
                        'sub' => 'Somente relatório',
                        'actor' => 'RH e colaborador',
                        'lead' => 'O colaborador registra as atividades do período. Depois da entrega, o RH pode conferir o espelho de ponto.',
                        'does' => [
                            'Acompanhar quem ainda não entregou o relatório',
                            'Orientar o preenchimento no portal',
                            'Seguir para a conferência do espelho',
                        ],
                        'unlocks' => 'Com o relatório entregue, o RH pode conferir o espelho sem tratar horas extras.',
                        'chain' => ['Portal', 'Relatório', 'Conferência'],
                        'links' => [
                            ['label' => 'Abrir o portal do colaborador', 'href' => '/he/login', 'primary' => true],
                            ['label' => 'Ver relatórios no RH', 'href' => '/rh/relatorios-atividades'],
                            ['label' => 'Revisar espelhos no Ponto', 'href' => '/rh/ponto'],
                        ],
                    ],
                ],
            ],
            [
                'id' => 'tratar',
                'title' => 'Tratar e conferir',
                'sub' => 'Espelho de ponto',
                'actor' => 'RH',
                'lead' => 'Ajuste batidas e ocorrências. Se houver horas extras declaradas, aplique ou registre divergência. Só então confira o espelho.',
                'does' => [
                    'Corrigir marcações e justificativas',
                    'Tratar horas extras, quando houver',
                    'Conferir o espelho',
                ],
                'unlocks' => 'Espelho conferido entra na Medição como tratado.',
                'chain' => ['Tratar', 'Conferir'],
                'links' => [
                    ['label' => 'Revisar espelhos no Ponto', 'href' => '/rh/ponto', 'primary' => true],
                    ['label' => 'Acompanhar na Medição', 'href' => '/rh/medicao'],
                ],
                'fork_content' => [
                    'com_he' => [
                        'title' => 'Tratar horas extras e conferir',
                        'sub' => 'Espelho + horas extras',
                        'lead' => 'Aplique as horas extras declaradas no relatório (ou marque divergência). Depois confira o espelho.',
                        'does' => [
                            'Corrigir marcações e justificativas',
                            'Aplicar as horas extras no espelho',
                            'Conferir o espelho',
                        ],
                        'unlocks' => 'Com as horas extras tratadas e o espelho conferido, a Medição libera o fechamento.',
                        'chain' => ['Tratar', 'Aplicar horas extras', 'Conferir'],
                    ],
                    'sem_he' => [
                        'title' => 'Conferir o espelho',
                        'sub' => 'Sem horas extras',
                        'lead' => 'Ajuste batidas e ocorrências, se precisar, e confira o espelho. Neste ramo não há horas extras para aplicar.',
                        'does' => [
                            'Corrigir marcações e justificativas',
                            'Conferir o espelho',
                        ],
                        'unlocks' => 'Espelho conferido entra na Medição como tratado.',
                        'chain' => ['Tratar', 'Conferir'],
                    ],
                ],
            ],
            [
                'id' => 'materializar',
                'title' => 'Gerar documentos e enviar',
                'sub' => 'Medição',
                'actor' => 'RH',
                'lead' => 'Finalize os registros elegíveis e envie para assinatura. Na Medição, feche o lote, gere os PDFs e envie os links por e-mail.',
                'does' => [
                    'Filtrar quem já está pronto para avançar',
                    'Fechar o lote',
                    'Gerar os PDFs e enviar os links por e-mail',
                ],
                'unlocks' => 'O colaborador recebe o link para assinar a folha e o relatório.',
                'chain' => ['Fechar', 'PDF', 'Link', 'E-mail'],
                'links' => [
                    ['label' => 'Acompanhar assinaturas na Medição', 'href' => '/rh/medicao', 'primary' => true],
                    ['label' => 'Ver folhas enviadas', 'href' => '/rh/folha'],
                    ['label' => 'Configurar conta de e-mail', 'href' => '/rh/email'],
                ],
            ],
            [
                'id' => 'assinar',
                'title' => 'Acompanhar as assinaturas',
                'sub' => 'Folha e relatório',
                'actor' => 'Colaborador e lembretes automáticos',
                'lead' => 'O colaborador assina a folha e o Relatório de Atividades. Se atrasar, o sistema envia lembretes nos dias úteis. Divergência volta para o Ponto.',
                'does' => [
                    'Acompanhar pendências na Medição e na Visão geral',
                    'Reenviar o e-mail, se precisar',
                    'Tratar divergências no espelho',
                ],
                'unlocks' => 'Com folha e relatório assinados, a linha fica pronta para o dossiê.',
                'chain' => ['Link', 'Assina folha', 'Assina relatório', 'Lembrete'],
                'links' => [
                    ['label' => 'Acompanhar assinaturas na Medição', 'href' => '/rh/medicao', 'primary' => true],
                    ['label' => 'Ver folhas enviadas', 'href' => '/rh/folha'],
                    ['label' => 'Ver pendências na Visão geral', 'href' => '/'],
                ],
            ],
            [
                'id' => 'dossie',
                'title' => 'Fechar o dossiê',
                'sub' => 'Entrega do período',
                'actor' => 'RH',
                'lead' => 'Quando todas as assinaturas do período estiverem concluídas, abra a Medição para gerar e baixar o dossiê.',
                'does' => [
                    'Confirmar que as assinaturas estão completas',
                    'Abrir a Medição e gerar o dossiê',
                    'Arquivar o ciclo',
                ],
                'unlocks' => 'Fim do processo deste período.',
                'chain' => ['Assinaturas ok', 'Dossiê', 'Arquivo'],
                'links' => [
                    ['label' => 'Abrir a Medição para baixar o dossiê', 'href' => '/rh/medicao', 'primary' => true],
                    ['label' => 'Ver pendências na Visão geral', 'href' => '/'],
                ],
            ],
        ],
    ],
    'colab' => [
        'label' => 'Guia do colaborador',
        'footer_primary' => [
            'label' => 'Abrir o portal do colaborador',
            'href' => '/he/login',
        ],
        'steps' => [
            [
                'id' => 'c_login',
                'title' => 'Entrar no portal',
                'sub' => 'Código por e-mail',
                'actor' => 'Colaborador',
                'lead' => 'Abra o portal, informe o CPF e confirme o código recebido por e-mail.',
                'does' => [
                    'Abrir o portal do colaborador',
                    'Informar o CPF',
                    'Confirmar o código do e-mail',
                ],
                'unlocks' => 'Você passa a ver o relatório e as folhas pendentes.',
                'chain' => ['CPF', 'Código do e-mail', 'Portal'],
                'links' => [
                    ['label' => 'Abrir o portal do colaborador', 'href' => '/he/login', 'primary' => true],
                ],
            ],
            [
                'id' => 'c_ra',
                'title' => 'Preencher o relatório',
                'sub' => 'Atividades do período',
                'actor' => 'Colaborador',
                'lead' => 'Preencha o Relatório de Atividades dia a dia. Se o seu fluxo incluir horas extras do cliente, declare-as neste mesmo relatório.',
                'does' => [
                    'Abrir o relatório do período',
                    'Registrar ambientes e atividades',
                    'Declarar horas extras, quando couber',
                ],
                'unlocks' => 'O RH consegue conferir o ponto e tratar as horas extras, se houver.',
                'chain' => ['Relatório', 'Salvar'],
                'links' => [
                    ['label' => 'Abrir o portal do colaborador', 'href' => '/he/login', 'primary' => true],
                    ['label' => 'Abrir visão do RH: relatórios', 'href' => '/rh/relatorios-atividades', 'admin' => true],
                ],
            ],
            [
                'id' => 'c_folha',
                'title' => 'Assinar a folha',
                'sub' => 'Pelo link do e-mail',
                'actor' => 'Colaborador',
                'lead' => 'Abra o link do e-mail (ou a área de folhas no portal), confira o PDF e assine. Se algo estiver errado, registre divergência.',
                'does' => [
                    'Abrir o link recebido por e-mail',
                    'Conferir o PDF da folha',
                    'Assinar ou registrar divergência',
                ],
                'unlocks' => 'A assinatura da folha avança o fechamento do período.',
                'chain' => ['E-mail', 'PDF', 'Assinar'],
                'links' => [
                    ['label' => 'Abrir o portal do colaborador', 'href' => '/he/login', 'primary' => true],
                    ['label' => 'Abrir visão do RH: folhas', 'href' => '/rh/folha', 'admin' => true],
                ],
            ],
            [
                'id' => 'c_ra_sign',
                'title' => 'Assinar o relatório',
                'sub' => 'Confirmação final',
                'actor' => 'Colaborador',
                'lead' => 'Assine também o Relatório de Atividades, pelo portal ou pelo link enviado.',
                'does' => [
                    'Abrir o relatório para assinatura',
                    'Revisar e assinar',
                    'Guardar o comprovante, se precisar',
                ],
                'unlocks' => 'Com folha e relatório assinados, o RH pode fechar o dossiê.',
                'chain' => ['Relatório', 'Assinar', 'Pronto'],
                'links' => [
                    ['label' => 'Abrir o portal do colaborador', 'href' => '/he/login', 'primary' => true],
                    ['label' => 'Abrir visão do RH: relatórios', 'href' => '/rh/relatorios-atividades', 'admin' => true],
                ],
            ],
        ],
    ],
];

$payload = [
    'journeys' => $journeys,
    'defaultRole' => 'rh',
    'canRh' => $canRh,
];
?>

<div class="mapa-page" id="mapaSistema">
    <header class="mapa-head">
        <div>
            <h1>Entenda como fechar a medição</h1>
            <p>
                Escolha uma jornada e percorra as etapas. Em cada passo, veja quem atua, o que precisa estar pronto e onde executar a tarefa.
                O progresso abaixo acompanha apenas este guia.
            </p>
        </div>
        <div class="mapa-roles" role="tablist" aria-label="Papel da jornada">
            <button type="button" class="mapa-role is-active" data-role="rh" role="tab" aria-selected="true">Guia do RH</button>
            <button type="button" class="mapa-role" data-role="colab" role="tab" aria-selected="false">Guia do colaborador</button>
        </div>
    </header>

    <p class="mapa-guide-note" data-guide-note>
        Este guia explica o processo; não mostra o andamento dos seus períodos.
    </p>

    <section class="mapa-progress" aria-label="Progresso do guia">
        <div class="mapa-progress-meta">
            <span data-progress-label>Passo 1 de 7 do guia</span>
            <span data-progress-role>Guia do RH</span>
        </div>
        <div class="mapa-progress-track" aria-hidden="true">
            <div class="mapa-progress-fill" data-progress-fill></div>
        </div>
    </section>

    <div class="mapa-layout">
        <ol class="mapa-rail" data-rail aria-label="Etapas do guia"></ol>

        <article class="mapa-panel" aria-live="polite">
            <div class="mapa-panel-top">
                <p class="mapa-panel-kicker" data-panel-kicker>Passo 1 de 7 do guia</p>
                <h2 class="mapa-panel-title" data-panel-title>Preparar a base</h2>
                <p class="mapa-panel-who" data-panel-who>Quem age: RH</p>
            </div>
            <div class="mapa-panel-body">
                <p class="mapa-panel-lead" data-panel-lead></p>

                <div class="mapa-panel-block" data-fork-wrap hidden>
                    <h3>Escolha a situação</h3>
                    <div class="mapa-fork" data-panel-fork></div>
                </div>

                <div class="mapa-panel-block">
                    <h3>O que fazer neste passo</h3>
                    <ul data-panel-does></ul>
                </div>

                <div class="mapa-panel-block">
                    <h3>Caminho deste passo</h3>
                    <div class="mapa-chain" data-panel-chain></div>
                </div>

                <div class="mapa-panel-block">
                    <h3>Condição para avançar</h3>
                    <p class="mapa-panel-lead" style="font-size:.92rem" data-panel-unlocks></p>
                </div>
            </div>
            <div class="mapa-ctas" data-panel-ctas></div>
        </article>
    </div>

    <footer class="mapa-controls">
        <div class="mapa-controls-left">
            <button type="button" class="btn btn-secondary btn-sm" data-prev>Anterior</button>
            <button type="button" class="btn btn-primary btn-sm" data-next>Próximo</button>
            <span class="mapa-controls-meta" data-controls-meta></span>
        </div>
        <div class="mapa-controls-right">
            <button type="button" class="btn btn-ghost btn-sm" data-play>Reproduzir guia</button>
            <a class="btn btn-secondary btn-sm" href="/rh/medicao" data-footer-primary>Abrir a Medição</a>
        </div>
    </footer>
</div>

<script type="application/json" id="mapaSistemaData"><?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
