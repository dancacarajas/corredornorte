<?php

declare(strict_types=1);

namespace App\Services;

use FPDF;
use RuntimeException;

/** PDF de Vale Refeição no mesmo visual da folha de ponto (logo, faixa laranja, tipografia). */
final class VrPdfRenderer
{
    /**
     * @param list<array<string,mixed>> $linhas
     * @param array{data_inicio:string,data_fim:string,emitido?:string} $meta
     */
    public function render(array $linhas, array $meta, string $outPath): void
    {
        $dir = dirname($outPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta do PDF.');
        }

        $pdf = new FPDF('P', 'mm', 'Letter');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Vale Refeição | Arcadis RH', true);
        $pdf->SetAuthor('Arcadis RH', true);
        $pdf->SetSubject('Relatório de Vale Refeição', true);

        $chunks = array_chunk($linhas !== [] ? $linhas : [[]], 28);
        $pages = count($chunks);
        foreach ($chunks as $i => $pageRows) {
            $this->drawPage($pdf, $pageRows, $meta, $i + 1, $pages, $i === $pages - 1, $linhas);
        }

        $pdf->Output('F', $outPath);
        if (!is_file($outPath) || filesize($outPath) === 0) {
            throw new RuntimeException('Falha ao gravar o PDF do Vale Refeição.');
        }
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array{data_inicio:string,data_fim:string,emitido?:string} $meta
     * @param list<array<string,mixed>> $all
     */
    private function drawPage(
        FPDF $pdf,
        array $rows,
        array $meta,
        int $page,
        int $pages,
        bool $last,
        array $all
    ): void {
        $pdf->AddPage();
        $left = 8.5;
        $right = 207.5;
        $width = $right - $left;

        $logo = dirname(__DIR__, 2) . '/public/assets/img/logo.png';
        if (is_file($logo)) {
            $pdf->Image($logo, $left, 8, 28);
        }

        $pdf->SetTextColor(34, 36, 39);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetXY($left, 10);
        $pdf->Cell($width, 8, $this->txt('Vale Refeição'), 0, 0, 'C');

        $ini = $this->br((string) ($meta['data_inicio'] ?? ''));
        $fim = $this->br((string) ($meta['data_fim'] ?? ''));
        $pdf->SetTextColor(198, 77, 19);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetXY($left, 18);
        $pdf->Cell($width, 5, $this->txt('DE ' . $ini . ' ATÉ ' . $fim), 0, 0, 'C');

        $pdf->SetTextColor(86, 91, 98);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY($right - 50, 10);
        $pdf->Cell(50, 4, $this->txt(sprintf('Página %02d de %02d', $page, $pages)), 0, 2, 'R');
        $pdf->Cell(50, 4, $this->txt('Emitido em ' . (string) ($meta['emitido'] ?? date('d/m/Y H:i'))), 0, 0, 'R');

        $pdf->SetDrawColor(200, 203, 208);
        $pdf->Line($left, 28, $right, 28);
        $pdf->SetDrawColor(233, 96, 27);
        $pdf->SetLineWidth(0.6);
        $pdf->Line($left, 28, $left + 12, 28);
        $pdf->SetLineWidth(0.2);

        $pdf->SetTextColor(86, 91, 98);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->SetXY($left, 31);
        $pdf->Cell($width, 5, $this->txt('VR = valor/dia × dias Remoto do Relatório de Atividades'), 0, 0, 'L');

        $y = 40;
        $cols = [
            ['Colaborador', 62],
            ['Matrícula', 24],
            ['Configuração', 40],
            ['Dias', 14],
            ['Valor/dia', 24],
            ['Total VR', 24],
        ];

        $pdf->SetFillColor(245, 246, 248);
        $pdf->SetTextColor(34, 36, 39);
        $pdf->SetFont('Helvetica', 'B', 8);
        $x = $left;
        foreach ($cols as [$label, $w]) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, 7, $this->txt($label), 1, 0, 'C', true);
            $x += $w;
        }
        $y += 7;

        $pdf->SetFont('Helvetica', '', 7.5);
        foreach ($rows as $row) {
            if ($row === []) {
                continue;
            }
            if ($y > 250) {
                break;
            }
            $vals = [
                [(string) ($row['nome'] ?? '—'), 62, 'L'],
                [(string) ($row['matricula'] ?? ''), 24, 'C'],
                [(string) ($row['config_nome'] ?? ''), 40, 'L'],
                [(string) ((int) ($row['dias_remoto'] ?? 0)), 14, 'C'],
                ['R$ ' . number_format((float) ($row['valor_dia'] ?? 0), 2, ',', '.'), 24, 'R'],
                ['R$ ' . number_format((float) ($row['valor_vr'] ?? 0), 2, ',', '.'), 24, 'R'],
            ];
            $x = $left;
            foreach ($vals as [$text, $w, $align]) {
                $pdf->SetXY($x, $y);
                $cut = function_exists('mb_strimwidth')
                    ? mb_strimwidth($text, 0, 42, '...', 'UTF-8')
                    : (strlen($text) > 42 ? substr($text, 0, 39) . '...' : $text);
                $pdf->Cell($w, 6.2, $this->txt($cut), 1, 0, $align);
                $x += $w;
            }
            $y += 6.2;
        }

        if ($last && $all !== []) {
            $soma = 0.0;
            $dias = 0;
            foreach ($all as $r) {
                $soma += (float) ($r['valor_vr'] ?? 0);
                $dias += (int) ($r['dias_remoto'] ?? 0);
            }
            $pdf->SetFont('Helvetica', 'B', 8);
            $pdf->SetFillColor(255, 244, 236);
            $pdf->SetXY($left, $y + 2);
            $pdf->Cell(140, 7, $this->txt('TOTAL (' . count($all) . ' colaboradores · ' . $dias . ' dias remoto)'), 1, 0, 'L', true);
            $pdf->Cell(48, 7, $this->txt('R$ ' . number_format($soma, 2, ',', '.')), 1, 0, 'R', true);
        }
    }

    private function br(string $ymd): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return $ymd !== '' ? $ymd : '—';
        }
        return date('d/m/Y', strtotime($ymd) ?: time());
    }

    private function txt(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);

        return $converted !== false ? $converted : $value;
    }
}
