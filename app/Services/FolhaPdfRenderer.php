<?php

declare(strict_types=1);

namespace App\Services;

use FPDF;
use RuntimeException;

/**
 * Gera o cartão de ponto em PHP (sem exec/shell), compatível com Hostinger.
 */
final class FolhaPdfRenderer
{
    public const LAYOUT_VERSION = 4;
    // O selo começa a 224 mm do topo e precisa ficar separado da tabela.
    private const TABLE_BOTTOM_MM = 218.0;

    /**
     * @param array<string,mixed> $payload
     */
    public function render(array $payload, string $outPath): void
    {
        $dir = dirname($outPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta do PDF.');
        }

        $pdf = new FPDF('P', 'mm', 'Letter');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetTitle('Cartão de Ponto | Arcadis RH');
        $pdf->SetAuthor('Arcadis RH');
        $pdf->SetSubject('Arcadis RH | folha de ponto retrato v' . self::LAYOUT_VERSION);

        $dias = $payload['dias'] ?? [];
        if (!is_array($dias) || $dias === []) {
            $dias = [[]];
        }
        $chunks = array_chunk($dias, 40);
        $pages = count($chunks);
        foreach ($chunks as $index => $pageDays) {
            $last = ($index + 1) === $pages;
            $this->drawPage($pdf, $payload, $pageDays, $index + 1, $pages, $last);
        }

        $pdf->Output('F', $outPath);
        if (!is_file($outPath) || filesize($outPath) === 0) {
            throw new RuntimeException('Falha ao gravar o PDF da folha.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<array<string,mixed>> $days
     */
    private function drawPage(FPDF $pdf, array $payload, array $days, int $page, int $pages, bool $last): void
    {
        $pdf->AddPage();
        $left = 8.5;
        $right = 207.5;
        $width = $right - $left;
        $employee = is_array($payload['espelho'] ?? null) ? $payload['espelho'] : [];

        $logo = dirname(__DIR__, 2) . '/public/assets/img/logo.png';
        if (is_file($logo)) {
            $pdf->Image($logo, $left, 8, 28);
        }

        $pdf->SetTextColor(34, 36, 39);
        $pdf->SetFont('Helvetica', 'B', 16);
        $pdf->SetXY($left, 10);
        $pdf->Cell($width, 8, $this->txt('Cartão de Ponto'), 0, 0, 'C');

        $pdf->SetTextColor(198, 77, 19);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetXY($left, 18);
        $periodo = str_replace(' A ', ' ATÉ ', (string) ($payload['periodo'] ?? ''));
        $pdf->Cell($width, 5, $this->txt('DE ' . $periodo), 0, 0, 'C');

        $pdf->SetTextColor(86, 91, 98);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY($right - 50, 10);
        $pdf->Cell(50, 4, $this->txt(sprintf('Página %02d de %02d', $page, $pages)), 0, 2, 'R');
        $pdf->Cell(50, 4, $this->txt('Emitido em ' . (string) ($payload['emitido'] ?? '')), 0, 0, 'R');

        $pdf->SetDrawColor(200, 203, 208);
        $pdf->Line($left, 28, $right, 28);
        $pdf->SetDrawColor(233, 96, 27);
        $pdf->SetLineWidth(0.6);
        $pdf->Line($left, 28, $left + 12, 28);
        $pdf->SetLineWidth(0.2);

        $this->field($pdf, 'EMPRESA', (string) ($employee['empresa'] ?? '—'), $left, 32, 72);
        $this->field($pdf, 'MATRÍCULA', (string) ($employee['matricula'] ?? '—'), $left + 74, 32, 36);
        $this->field($pdf, 'FUNCIONÁRIO', (string) ($employee['nome'] ?? '—'), $left, 42, 72);
        $this->field($pdf, 'CPF', (string) ($employee['cpf'] ?? '—'), $left + 74, 42, 36);
        $this->field($pdf, 'CARGO', (string) ($employee['cargo'] ?? '—'), $left, 52, 72);
        $local = trim(((string) ($employee['municipio'] ?? '')) . '/' . ((string) ($employee['uf'] ?? '')), '/');
        $this->field($pdf, 'LOCAL', $local !== '' ? $local : '—', $left + 74, 52, 36);
        $lotacao = implode(' / ', [
            (string) ($employee['contrato_codigo'] ?? '—'),
            (string) ($employee['cc_codigo'] ?? '—'),
            (string) ($employee['os_codigo'] ?? '—'),
        ]);
        $this->field($pdf, 'CONTRATO / CC / OS', $lotacao, $left, 62, 110);

        $this->drawSchedule($pdf, is_array($employee['jornada_semanal'] ?? null) ? $employee['jornada_semanal'] : [], 124, 30);

        $bottom = $this->drawTable(
            $pdf,
            $days,
            $last && is_array($payload['totais'] ?? null) ? $payload['totais'] : null,
            $left,
            $right,
            78
        );

        $pdf->SetTextColor(86, 91, 98);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetXY($left, $bottom + 2);
        $pdf->Cell($width, 4, $this->txt('Verificação  ' . (string) ($payload['codigo'] ?? '')), 0, 0, 'R');

        if ($last) {
            // Mesmas coordenadas do cartão Python (pt → mm): linha em y≈109pt da base.
            $lineY = 279.4 - (109 * 25.4 / 72);
            $empX = 33 * 25.4 / 72;
            $empW = 260 * 25.4 / 72;
            $compX = (612 / 2 + 13) * 25.4 / 72;
            $compW = $empW;
            $pdf->SetDrawColor(34, 36, 39);
            $pdf->Line($empX, $lineY, $empX + $empW, $lineY);
            $pdf->Line($compX, $lineY, $compX + $compW, $lineY);
            $pdf->SetFont('Helvetica', 'B', 7);
            $pdf->SetTextColor(34, 36, 39);
            $pdf->SetXY($empX, $lineY + 1.5);
            $pdf->Cell($empW, 4, $this->txt((string) ($employee['nome'] ?? '')), 0, 0, 'C');
            $pdf->SetXY($compX, $lineY + 1.5);
            $pdf->Cell($compW, 4, $this->txt((string) ($employee['empresa'] ?? '')), 0, 0, 'C');
        }
    }

    private function field(FPDF $pdf, string $label, string $value, float $x, float $y, float $w): void
    {
        $pdf->SetTextColor(86, 91, 98);
        $pdf->SetFont('Helvetica', 'B', 6.5);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 3.5, $this->txt($label), 0, 2);
        $pdf->SetTextColor(34, 36, 39);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Cell($w, 4.5, $this->txt($value !== '' ? $value : '—'), 0, 0);
    }

    /** @param list<array<string,mixed>> $weekly */
    private function drawSchedule(FPDF $pdf, array $weekly, float $x, float $y): void
    {
        $w = 207.5 - $x;
        $rows = [];
        foreach ($weekly as $row) {
            if (isset($row['dia'])) {
                $rows[(string) $row['dia']] = $row;
            }
        }

        $pdf->SetFillColor(238, 240, 242);
        $pdf->Rect($x, $y, $w, 7, 'F');
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetTextColor(34, 36, 39);
        $pdf->SetXY($x, $y + 1.5);
        $pdf->Cell($w, 4, $this->txt('Horário de trabalho'), 0, 0, 'C');

        $headers = ['Dia', 'Ent.1', 'Saí.1', 'Ent.2', 'Saí.2'];
        $colW = [$w * 0.18, $w * 0.205, $w * 0.205, $w * 0.205, $w * 0.205];
        $yy = $y + 7;
        $pdf->SetFillColor(238, 240, 242);
        $pdf->Rect($x, $yy, $w, 5, 'F');
        $pdf->SetFont('Helvetica', 'B', 6.5);
        $cx = $x;
        foreach ($headers as $i => $h) {
            $pdf->SetXY($cx, $yy + 0.8);
            $pdf->Cell($colW[$i], 3.5, $this->txt($h), 0, 0, 'C');
            $cx += $colW[$i];
        }

        $dias = ['SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SAB', 'DOM'];
        $yy += 5;
        foreach ($dias as $i => $dia) {
            $row = $rows[$dia] ?? $rows[str_replace('A', 'Á', $dia)] ?? [];
            if ($i % 2 === 1) {
                $pdf->SetFillColor(248, 249, 250);
                $pdf->Rect($x, $yy, $w, 4.8, 'F');
            }
            $tipo = (string) ($row['tipo'] ?? '');
            $vals = [$dia, '—', '—', '—', '—'];
            if ($tipo === 'trabalho') {
                $vals = [
                    $dia,
                    $this->hm($row['entrada'] ?? null),
                    $this->hm($row['intervalo_inicio'] ?? null),
                    $this->hm($row['intervalo_fim'] ?? null),
                    $this->hm($row['saida'] ?? null),
                ];
            } elseif ($tipo === 'folga') {
                $vals = [$dia, 'Folga', '', '', ''];
            } elseif ($tipo === 'variavel') {
                $vals = [$dia, 'Variável', '', '', ''];
            }
            $cx = $x;
            $pdf->SetFont('Helvetica', $i === 0 ? 'B' : '', 6.5);
            $pdf->SetTextColor(34, 36, 39);
            foreach ($vals as $vi => $val) {
                $pdf->SetXY($cx, $yy + 0.7);
                $pdf->Cell($colW[$vi], 3.5, $this->txt((string) $val), 0, 0, 'C');
                $cx += $colW[$vi];
            }
            $yy += 4.8;
        }
        $pdf->SetDrawColor(200, 203, 208);
        $pdf->Rect($x, $y, $w, $yy - $y);
    }

    /**
     * @param list<array<string,mixed>> $days
     * @param array<string,mixed>|null $totals
     */
    private function drawTable(FPDF $pdf, array $days, ?array $totals, float $left, float $right, float $top): float
    {
        $cols = [
            ['Dia', 18],
            ['Ent.1', 13],
            ['Saí.1', 13],
            ['Ent.2', 13],
            ['Saí.2', 13],
            ['Normal', 12],
            ['Trab.', 12],
            ['ADN', 11],
            ['Prev.', 12],
            ['Falta', 9],
            ['H.Falta', 12],
            ['Atraso', 12],
            ['Desc.', 12],
            ['Atest.', 11],
            ['HE', 12],
        ];
        $width = $right - $left;
        $sumW = array_sum(array_column($cols, 1));
        $scale = $width / $sumW;
        foreach ($cols as $i => $col) {
            $cols[$i][1] = $col[1] * $scale;
        }

        $headH = 8;
        $hasTotals = $totals !== null;
        $extraTotalsHeight = $hasTotals ? 1.2 : 0.0;
        $rows = count($days) + (int) $hasTotals;
        $rowH = min(5.2, (self::TABLE_BOTTOM_MM - $top - $headH - $extraTotalsHeight) / max(1, $rows));
        $y = $top;

        $pdf->SetFillColor(238, 240, 242);
        $pdf->Rect($left, $y, $width, $headH, 'F');
        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->SetTextColor(34, 36, 39);
        $x = $left;
        foreach ($cols as [$label, $w]) {
            $pdf->SetXY($x, $y + 2.2);
            $pdf->Cell($w, 3.5, $this->txt($label), 0, 0, 'C');
            $x += $w;
        }
        $y += $headH;

        foreach ($days as $i => $day) {
            $vals = $this->dayValues($day);
            $bg = ((string) ($day['tipo_dia'] ?? '') === 'feriado')
                ? [255, 243, 234]
                : ($i % 2 ? [248, 249, 250] : [255, 255, 255]);
            $pdf->SetFillColor($bg[0], $bg[1], $bg[2]);
            $pdf->Rect($left, $y, $width, $rowH, 'F');
            $this->paintRow($pdf, $cols, $vals, $left, $y, $rowH, false);
            $y += $rowH;
        }

        if ($hasTotals) {
            $totalsHeight = $rowH + $extraTotalsHeight;
            $pdf->SetFillColor(238, 240, 242);
            $pdf->Rect($left, $y, $width, $totalsHeight, 'F');
            $this->paintRow($pdf, $cols, $this->totalValues($totals), $left, $y, $totalsHeight, true);
            $y += $totalsHeight;
        }

        $pdf->SetDrawColor(200, 203, 208);
        $pdf->Rect($left, $top, $width, $y - $top);
        $pdf->SetDrawColor(233, 96, 27);
        $pdf->SetLineWidth(0.45);
        $pdf->Line($left, $top, $right, $top);
        $pdf->SetLineWidth(0.2);

        return $y;
    }

    /**
     * @param list<array{0:string,1:float}> $cols
     * @param list<string> $vals
     */
    private function paintRow(FPDF $pdf, array $cols, array $vals, float $left, float $y, float $h, bool $bold): void
    {
        $pdf->SetFont('Helvetica', $bold ? 'B' : '', 6.2);
        $pdf->SetTextColor(34, 36, 39);
        $x = $left;
        foreach ($cols as $i => [$label, $w]) {
            $val = $vals[$i] ?? '';
            $pdf->SetXY($x, $y + max(0.6, ($h - 3.2) / 2));
            $pdf->Cell($w, 3.2, $this->txt($val), 0, 0, $i === 0 ? 'L' : 'C');
            $x += $w;
        }
    }

    /** @param array<string,mixed> $day @return list<string> */
    private function dayValues(array $day): array
    {
        $kind = (string) ($day['tipo_dia'] ?? '');
        $plannedOff = match ($kind) {
            'folga', 'folga_escala' => 'Folga',
            'feriado' => 'Feriado',
            default => null,
        };
        $hasPunch = false;
        foreach (['ent1', 'sai1', 'ent2', 'sai2'] as $k) {
            if (!empty($day[$k])) {
                $hasPunch = true;
                break;
            }
        }
        $punches = [
            $this->hm($day['ent1'] ?? null),
            $this->hm($day['sai1'] ?? null),
            $this->hm($day['ent2'] ?? null),
            $this->hm($day['sai2'] ?? null),
        ];
        if ($plannedOff !== null && !$hasPunch) {
            $punches = [$plannedOff, $plannedOff, $plannedOff, $plannedOff];
        } elseif (!empty($day['dia_falta']) && !$hasPunch) {
            $punches[0] = 'Falta';
        }

        $atestado = $this->horas(isset($day['atestado_min']) ? (int) $day['atestado_min'] : null)
            ?: (!empty($day['atestado']) ? 'Sim' : '');

        return [
            (string) ($day['data_fmt'] ?? ''),
            $punches[0],
            $punches[1],
            $punches[2],
            $punches[3],
            $this->horas((int) ($day['total_normal_min'] ?? 0), $hasPunch),
            $this->horas((int) ($day['total_trab_min'] ?? 0), $hasPunch),
            $this->horas((int) ($day['adn_min'] ?? 0), $hasPunch),
            $this->horas((int) ($day['horas_previstas_min'] ?? 0)),
            !empty($day['dia_falta']) ? '1' : '',
            $this->horas((int) ($day['horas_falta_min'] ?? 0)),
            $this->horas((int) ($day['atraso_bruto_min'] ?? 0)),
            $this->horas((int) ($day['desconto_min'] ?? 0)),
            $atestado,
            $this->horas((int) ($day['horas_extras_min'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $totals @return list<string> */
    private function totalValues(array $totals): array
    {
        return [
            'TOTAIS', '', '', '', '',
            $this->horas((int) ($totals['norm'] ?? 0), true),
            $this->horas((int) ($totals['trab'] ?? 0), true),
            $this->horas((int) ($totals['adn'] ?? 0), true),
            $this->horas((int) ($totals['prev'] ?? 0), true),
            (string) ($totals['dias_falta'] ?? ''),
            $this->horas((int) ($totals['falta'] ?? 0), true),
            $this->horas((int) ($totals['atraso'] ?? 0), true),
            $this->horas((int) ($totals['desconto'] ?? 0), true),
            $this->horas((int) ($totals['atestado'] ?? 0)),
            $this->horas((int) ($totals['he'] ?? 0), true),
        ];
    }

    private function horas(?int $minutes, bool $showZero = false): string
    {
        if ($minutes === null || ($minutes === 0 && !$showZero)) {
            return '';
        }
        $sign = $minutes < 0 ? '-' : '';
        $minutes = abs($minutes);
        return sprintf('%s%02d:%02d', $sign, intdiv($minutes, 60), $minutes % 60);
    }

    private function hm(mixed $value): string
    {
        $value = (string) ($value ?? '');
        return $value !== '' ? substr($value, 0, 5) : '';
    }

    private function txt(string $value): string
    {
        $converted = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value);
        return $converted !== false ? $converted : $value;
    }
}
