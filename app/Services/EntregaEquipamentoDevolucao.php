<?php

declare(strict_types=1);

namespace App\Services;

final class EntregaEquipamentoDevolucao
{
    /** @param array<string,mixed> $entrega */
    public static function render(EntregaEquipamentoFormulario $pdf, array $entrega): void
    {
        $pdf->pagina(5);

        $data = (string) ($entrega['devolucao_data'] ?? '');
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $data, $partes)
            && checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            $pdf->campo(292.55, 325.2, 25.56, $partes[3], 9.2, '', 'C', 'Calibri');
            $pdf->campo(320.67, 325.2, 25.57, $partes[2], 9.2, '', 'C', 'Calibri');
            $pdf->campo(348.79, 325.2, 61.36, $partes[1], 9.2, '', 'C', 'Calibri');
        }

        $condicao = $entrega['devolucao_condicao'] ?? '';
        if ($condicao === 'bom_estado') {
            $pdf->campo(60.2925, 350.775, 6.15, 'X', 9.21173, '', 'L', 'Calibri');
        } elseif ($condicao === 'com_defeito') {
            $pdf->campo(60.22, 374.8, 4.06, 'X', 9, '', 'C', 'Calibri');
        }
        $pdf->campo(328.4, 414.275, 223, (string) ($entrega['devolucao_nome'] ?? ''), 9, '', 'L', 'Calibri');

        $descricao = trim((string) ($entrega['devolucao_descricao'] ?? ''));
        if ($descricao === '') {
            return;
        }
        $linhas = $pdf->linhas($descricao, 492, 9, '', 'Calibri');
        $pdf->campo(57.225, 387, 492, array_shift($linhas), 9, '', 'L', 'Calibri');
        if ($linhas === []) {
            return;
        }

        // O traço de assinatura começa em y=399. A continuação fica fora da caixa.
        $pdf->campo(57.225, 483, 492, 'Descrição da devolução (continuação)', 10, 'B');
        $y = 499;
        foreach ($linhas as $linha) {
            if ($y > 764) {
                $pdf->AddPage();
                $pdf->campo(49.65, 66, 495, 'TERMO DE DEVOLUÇÃO (continuação)', 12, 'B');
                $pdf->campo(49.65, 84, 495, (string) ($entrega['codigo'] ?? ''), 10);
                $pdf->campo(49.65, 803.825, 170, EntregaEquipamentoPdfRenderer::DOC_CODE, 9.5, '', 'L', 'Calibri');
                $revisao = 'Data da última alteração: ' . EntregaEquipamentoPdfRenderer::DOC_REV_DATE;
                $pdf->campo(350, 803.825, 195, $revisao, 9.5, '', 'R', 'Calibri');
                $y = 112;
            }
            $pdf->campo(57.225, $y, 492, $linha, 9, '', 'L', 'Calibri');
            $y += 13;
        }
    }
}
