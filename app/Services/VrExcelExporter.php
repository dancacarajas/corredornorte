<?php

declare(strict_types=1);

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/** Excel de Vale Refeição no mesmo padrão visual das exportações Arcadis (cabeçalho laranja). */
final class VrExcelExporter
{
    /**
     * @param list<array<string,mixed>> $linhas
     * @param array{data_inicio:string,data_fim:string} $meta
     */
    public function gerarArquivo(array $linhas, array $meta): string
    {
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->setTitle('Vale Refeicao');

        $ini = $this->br((string) ($meta['data_inicio'] ?? ''));
        $fim = $this->br((string) ($meta['data_fim'] ?? ''));

        $ws->setCellValue('A1', 'Arcadis RH — Vale Refeição');
        $ws->setCellValue('A2', 'Período: ' . $ini . ' até ' . $fim);
        $ws->setCellValue('A3', 'Emitido em ' . date('d/m/Y H:i'));
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('C45C26');
        $ws->getStyle('A2')->getFont()->setBold(true);

        $headers = [
            'A5' => 'Colaborador',
            'B5' => 'Matrícula',
            'C5' => 'Status RA',
            'D5' => 'Configuração VR',
            'E5' => 'Alocação',
            'F5' => 'Dias Remoto',
            'G5' => 'Valor/dia (R$)',
            'H5' => 'Total VR (R$)',
        ];
        foreach ($headers as $cell => $label) {
            $ws->setCellValue($cell, $label);
        }
        $ws->getStyle('A5:H5')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'C45C26'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']],
            ],
        ]);

        $r = 6;
        $soma = 0.0;
        foreach ($linhas as $row) {
            $total = (float) ($row['valor_vr'] ?? 0);
            $soma += $total;
            $ws->setCellValue('A' . $r, (string) ($row['nome'] ?? ''));
            $ws->setCellValueExplicit('B' . $r, (string) ($row['matricula'] ?? ''), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $ws->setCellValue('C' . $r, (string) ($row['status'] ?? ''));
            $ws->setCellValue('D' . $r, (string) ($row['config_nome'] ?? ''));
            $ws->setCellValue('E' . $r, (string) ($row['config_alocacao'] ?? ''));
            $ws->setCellValue('F' . $r, (int) ($row['dias_remoto'] ?? 0));
            $ws->setCellValue('G' . $r, (float) ($row['valor_dia'] ?? 0));
            $ws->setCellValue('H' . $r, $total);
            $r++;
        }

        $lastData = max(5, $r - 1);
        if ($r > 6) {
            $ws->getStyle('A6:H' . $lastData)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']],
                ],
            ]);
            $ws->getStyle('G6:H' . $lastData)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $ws->setCellValue('A' . $r, 'TOTAL');
        $ws->setCellValue('H' . $r, $soma);
        $ws->getStyle('A' . $r . ':H' . $r)->getFont()->setBold(true);
        $ws->getStyle('A' . $r . ':H' . $r)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF4EC');
        $ws->getStyle('H' . $r)->getNumberFormat()->setFormatCode('#,##0.00');

        foreach (range('A', 'H') as $col) {
            $ws->getColumnDimension($col)->setAutoSize(true);
        }
        $ws->freezePane('A6');
        $ws->setAutoFilter('A5:H' . $lastData);

        $dir = sys_get_temp_dir();
        $path = $dir . DIRECTORY_SEPARATOR . 'vr_' . bin2hex(random_bytes(6)) . '.xlsx';
        (new Xlsx($sheet))->save($path);
        if (!is_file($path)) {
            throw new RuntimeException('Falha ao gravar o Excel do Vale Refeição.');
        }

        return $path;
    }

    private function br(string $ymd): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return $ymd !== '' ? $ymd : '—';
        }

        return date('d/m/Y', strtotime($ymd) ?: time());
    }
}
