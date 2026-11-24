<?php

declare(strict_types=1);

namespace App\Services;

final class EntregaEquipamentoAnexo
{
    // Linhas de base e larguras em pontos do Anexo I, COR-JUR-FM-005_Rev.0.
    private const Y_EQUIPAMENTO = [183.375, 202.85, 220.60, 238.35];
    private const Y_QUANTIDADE = [183.275, 201.90, 219.65, 237.40];
    private const Y_PERIFERICO = [392.225, 408.25, 424.275, 440.30];
    private const LARGURA_DESCRICAO = 227.5;
    private const LARGURA_TOMBAMENTO = 144.0;
    private const LARGURA_PERIFERICO = 400.0;

    /**
     * @param array<string,mixed> $entrega
     * @param list<array<string,mixed>> $itens
     */
    public static function render(EntregaEquipamentoFormulario $pdf, array $entrega, array $itens): void
    {
        $equipamentos = [];
        $perifericos = [];
        foreach ($itens as $item) {
            $descricao = (string) ($item['descricao'] ?? '');
            $quantidade = (string) max(1, (int) ($item['quantidade'] ?? 1));
            if (($item['tipo'] ?? '') === 'periferico') {
                $linhas = $pdf->linhas($descricao, self::LARGURA_PERIFERICO, 9);
                foreach ($linhas as $i => $linha) {
                    $perifericos[] = [$i === 0 ? str_pad($quantidade, 2, '0', STR_PAD_LEFT) : '', $linha];
                }
                continue;
            }

            $descricoes = $pdf->linhas($descricao, self::LARGURA_DESCRICAO, 11);
            $tombamentos = $pdf->linhas((string) ($item['tombamento'] ?? ''), self::LARGURA_TOMBAMENTO, 11);
            $total = max(count($descricoes), count($tombamentos));
            for ($i = 0; $i < $total; $i++) {
                $equipamentos[] = [$i === 0 ? $quantidade : '', $descricoes[$i] ?? '', $tombamentos[$i] ?? ''];
            }
        }

        $linhasPorFolha = count(self::Y_EQUIPAMENTO);
        $totalLinhas = max(count($equipamentos), count($perifericos), 1);
        for ($inicio = 0; $inicio < $totalLinhas; $inicio += $linhasPorFolha) {
            $pdf->pagina(4);
            foreach (array_slice($equipamentos, $inicio, $linhasPorFolha) as $i => [$qtd, $descricao, $tombamento]) {
                $pdf->campo(67.25, self::Y_QUANTIDADE[$i], 57, $qtd, 10, '', 'C');
                $pdf->campo(147, self::Y_EQUIPAMENTO[$i], self::LARGURA_DESCRICAO, $descricao, 11, '', 'C');
                $pdf->campo(398.35, self::Y_EQUIPAMENTO[$i], self::LARGURA_TOMBAMENTO, $tombamento, 11, '', 'C');
            }
            foreach (array_slice($perifericos, $inicio, $linhasPorFolha) as $i => [$qtd, $descricao]) {
                $pdf->campo(67.25, self::Y_PERIFERICO[$i], 57, $qtd, 9, '', 'C');
                $pdf->campo(133.05, self::Y_PERIFERICO[$i], self::LARGURA_PERIFERICO, $descricao, 9);
            }
            $pdf->campo(214.5, 575.425, 135, (string) ($entrega['gestor_nome'] ?? ''));
            $pdf->campo(455.5, 575.425, 78, (string) ($entrega['gestor_cpf'] ?? ''));
            $pdf->campo(214.5, 589.425, 135, (string) ($entrega['projeto_nome'] ?? ''), 6);
            $pdf->campo(455.5, 593.175, 78, (string) ($entrega['centro_custo'] ?? ''));
        }
    }
}
