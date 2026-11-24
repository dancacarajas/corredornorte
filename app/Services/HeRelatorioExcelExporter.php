<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/**
 * Exporta o Relatório de HE a partir da planilha matriz REV_03.
 * Layout/estilos/fórmulas do template são preservados; só os dados são preenchidos.
 * O arquivo original em Downloads nunca é alterado.
 */
final class HeRelatorioExcelExporter
{
    private const TEMPLATE = 'storage/templates/relatorio_he_matriz.xlsx';

    private const EFETIVO_DATA_START = 4;
    private const LANC_DATA_START = 5;
    private const HE_DATA_START = 4;
    private const PEND_DATA_START = 4;

    /** Colunas de input (azul) em Lançamentos — o restante é fórmula da matriz ou valor apurado. */
    private const LANC_INPUT_COLS = ['A', 'C', 'J', 'K', 'T'];

    /** Colunas preenchidas diretamente com os minutos apurados pelo motor de ponto e matrícula-chave. */
    private const LANC_APURACAO_COLS = ['L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'V'];

    /**
     * @param array{
     *   data_inicio:string,
     *   data_fim:string,
     *   mes_label:string,
     *   efetivo:list<array<string,mixed>>,
     *   lancamentos:list<array<string,mixed>>,
     *   consolidado:list<array<string,mixed>>,
     *   pendentes:array<string, list<array<string,mixed>>>
     * } $dados
     */
    public function gerarArquivo(array $dados): string
    {
        $template = $this->caminhoTemplate();
        $spreadsheet = IOFactory::load($template);

        $this->preencherEfetivo($spreadsheet->getSheetByName('Efetivo'), $dados['efetivo']);
        $maxLancRow = $this->preencherLancamentos($spreadsheet->getSheetByName('Lançamentos'), $dados['lancamentos']);
        $this->preencherHeMes($spreadsheet, $dados, $maxLancRow);
        $this->preencherPendentes($spreadsheet, $dados['pendentes']);

        $spreadsheet->setActiveSheetIndex(0);

        $dir = sys_get_temp_dir();
        $path = $dir . DIRECTORY_SEPARATOR . 'he_relatorio_' . bin2hex(random_bytes(6)) . '.xlsx';
        // Fórmulas da matriz são nativas do Excel; não pré-calcular no PHP.
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $spreadsheet->disconnectWorksheets();

        if (!is_file($path)) {
            throw new RuntimeException('Falha ao gravar o Excel do Relatório de HE.');
        }

        return $path;
    }

    private function caminhoTemplate(): string
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::TEMPLATE);
        if (!is_file($path)) {
            throw new RuntimeException('Template da planilha matriz não encontrado: ' . self::TEMPLATE);
        }

        return $path;
    }

    /** @param list<array<string,mixed>> $rows */
    private function preencherEfetivo(?Worksheet $ws, array $rows): void
    {
        if ($ws === null) {
            throw new RuntimeException('Aba Efetivo ausente no template.');
        }

        $lastExisting = max(self::EFETIVO_DATA_START, (int) $ws->getHighestDataRow());
        for ($r = self::EFETIVO_DATA_START; $r <= $lastExisting; $r++) {
            for ($c = 1; $c <= 17; $c++) {
                $ws->setCellValue([$c, $r], null);
            }
        }

        $r = self::EFETIVO_DATA_START;
        foreach ($rows as $row) {
            $ws->setCellValueExplicit('A' . $r, (string) $row['matricula'], DataType::TYPE_STRING);
            $ws->setCellValue('B' . $r, (string) $row['nome']);
            $ws->setCellValue('C' . $r, (string) $row['situacao']);
            $ws->setCellValue('D' . $r, (string) $row['funcao']);
            $ws->setCellValue('E' . $r, (string) $row['cidade']);
            $ws->setCellValue('F' . $r, (string) $row['uf']);
            $ws->setCellValueExplicit('G' . $r, (string) $row['horario_codigo'], DataType::TYPE_STRING);
            $ws->setCellValue('H' . $r, (string) $row['horario_texto']);
            $ws->setCellValue('I' . $r, (int) $row['jornada_mensal']);
            $ws->setCellValue('J' . $r, (float) $row['horas_seg_qui']);
            $ws->setCellValue('K' . $r, (float) $row['horas_sexta']);
            $ws->setCellValue('L' . $r, (float) $row['intervalo_h']);
            $ws->setCellValue('M' . $r, (string) $row['secao']);
            $ws->setCellValue('N' . $r, (int) $row['percentual_he']);
            $adm = $this->excelDate((string) $row['admissao']);
            if ($adm !== null) {
                $ws->setCellValue('O' . $r, $adm);
                $ws->getStyle('O' . $r)->getNumberFormat()->setFormatCode('DD/MM/YYYY');
            }
            $dem = $this->excelDate((string) ($row['demissao'] ?? ''));
            if ($dem !== null) {
                $ws->setCellValue('P' . $r, $dem);
                $ws->getStyle('P' . $r)->getNumberFormat()->setFormatCode('DD/MM/YYYY');
            }
            $ws->setCellValue('Q' . $r, (string) $row['turno']);
            $r++;
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function preencherLancamentos(?Worksheet $ws, array $rows): int
    {
        if ($ws === null) {
            throw new RuntimeException('Aba Lançamentos ausente no template.');
        }

        $ws->setCellValue('V4', 'Matrícula');

        $colsLimpar = array_merge(self::LANC_INPUT_COLS, self::LANC_APURACAO_COLS);
        $lastExisting = max(self::LANC_DATA_START, (int) $ws->getHighestDataRow());
        for ($r = self::LANC_DATA_START; $r <= $lastExisting; $r++) {
            foreach ($colsLimpar as $col) {
                $ws->setCellValue($col . $r, null);
            }
        }

        $r = self::LANC_DATA_START;
        foreach ($rows as $row) {
            $this->garantirFormulasLancamento($ws, $r);

            $data = $this->excelDate((string) $row['data']);
            if ($data !== null) {
                $ws->setCellValue('A' . $r, $data);
                $ws->getStyle('A' . $r)->getNumberFormat()->setFormatCode('DD/MM/YYYY');
            }

            $ws->setCellValue('C' . $r, (string) $row['nome']);
            $ws->setCellValueExplicit('V' . $r, $this->chaveColaborador($row), DataType::TYPE_STRING);

            $ent = $this->excelTime((string) ($row['entrada'] ?? ''));
            if ($ent !== null) {
                $ws->setCellValue('J' . $r, $ent);
                $ws->getStyle('J' . $r)->getNumberFormat()->setFormatCode('h:mm');
            }

            $sai = $this->excelTime((string) ($row['saida'] ?? ''));
            if ($sai !== null) {
                $ws->setCellValue('K' . $r, $sai);
                $ws->getStyle('K' . $r)->getNumberFormat()->setFormatCode('h:mm');
            }

            // Grava diretamente os valores apurados pelo motor de ponto (preserva almoço real,
            // folga_escala e separação exata de HE diurna/noturna em minutos/1440).
            $this->setDuracaoMinutos($ws, 'L' . $r, (int) ($row['trab_min'] ?? $row['total_trab_min'] ?? 0), true);
            $this->setDuracaoMinutos($ws, 'M' . $r, (int) ($row['adn_min'] ?? 0), true);
            $this->setDuracaoMinutos($ws, 'N' . $r, (int) ($row['he_n50'] ?? 0), true);
            $this->setDuracaoMinutos($ws, 'O' . $r, (int) ($row['he_n60'] ?? 0), true);
            $this->setDuracaoMinutos($ws, 'P' . $r, (int) ($row['he_n100'] ?? 0), true);
            $this->setDuracaoMinutos($ws, 'Q' . $r, (int) ($row['he_d50'] ?? 0), true);
            $this->setDuracaoMinutos($ws, 'R' . $r, (int) ($row['he_d60'] ?? 0), true);
            $this->setDuracaoMinutos($ws, 'S' . $r, (int) ($row['he_d100'] ?? 0), true);

            $feriado = strtoupper(trim((string) ($row['feriado_sn'] ?? 'NÃO')));
            $ws->setCellValue('T' . $r, $feriado === 'SIM' ? 'SIM' : 'NÃO');

            $r++;
        }

        return max(self::LANC_DATA_START, $r - 1);
    }

    /**
     * Se precisar de linhas além do template, copia fórmulas da linha anterior.
     */
    private function garantirFormulasLancamento(Worksheet $ws, int $row): void
    {
        $probe = $ws->getCell('B' . $row)->getValue();
        if (is_string($probe) && str_starts_with($probe, '=')) {
            return;
        }
        if ($row <= self::LANC_DATA_START) {
            return;
        }

        $pular = array_merge(self::LANC_INPUT_COLS, self::LANC_APURACAO_COLS);
        $src = $row - 1;
        for ($c = 1; $c <= 21; $c++) {
            $col = Coordinate::stringFromColumnIndex($c);
            if (in_array($col, $pular, true)) {
                continue;
            }
            $val = $ws->getCell($col . $src)->getValue();
            if (!is_string($val) || !str_starts_with($val, '=')) {
                continue;
            }
            $ws->setCellValue($col . $row, $this->deslocarFormulaLinha($val, $src, $row));
            $ws->duplicateStyle($ws->getStyle($col . $src), $col . $row);
        }
    }

    /**
     * @param array{
     *   mes_label:string,
     *   consolidado:list<array<string,mixed>>
     * } $dados
     */
    private function preencherHeMes(Spreadsheet $book, array $dados, int $maxLancRow): void
    {
        $ws = $book->getSheetByName('HE_OUTUBRO');
        if ($ws === null) {
            // fallback: primeira aba HE_*
            foreach ($book->getWorksheetIterator() as $sheet) {
                if (str_starts_with($sheet->getTitle(), 'HE_') && !str_contains($sheet->getTitle(), 'pendente')) {
                    $ws = $sheet;
                    break;
                }
            }
        }
        if ($ws === null) {
            throw new RuntimeException('Aba HE do mês ausente no template.');
        }

        $mesNome = $this->mesNome((string) ($dados['mes_label'] ?? ''));
        $novoTitulo = $this->safeTitle('HE_' . $mesNome);
        if ($ws->getTitle() !== $novoTitulo) {
            $ws->setTitle($novoTitulo);
        }
        $ws->setCellValue('A1', 'HORAS EXTRAS E ADICIONAL NOTURNO - ' . $mesNome);

        $lastExisting = max(self::HE_DATA_START, (int) $ws->getHighestDataRow());
        for ($r = self::HE_DATA_START; $r <= $lastExisting; $r++) {
            for ($c = 1; $c <= 11; $c++) {
                $ws->setCellValue([$c, $r], null);
            }
        }

        $r = self::HE_DATA_START;
        foreach ($dados['consolidado'] as $row) {
            $ws->setCellValue('A' . $r, (string) $row['nome']);
            $ws->setCellValueExplicit('B' . $r, $this->chaveColaborador($row), DataType::TYPE_STRING);
            $this->garantirFormulasHe($ws, $r, $maxLancRow);
            $ws->getStyle('D' . $r . ':K' . $r)->getNumberFormat()->setFormatCode('[h]:mm');
            $r++;
        }
    }

    private function garantirFormulasHe(Worksheet $ws, int $row, int $maxLancRow): void
    {
        $fimLanc = max(self::LANC_DATA_START, $maxLancRow);
        $formulas = [
            'C' => '=COUNTIFS(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$A$5:$A$' . $fimLanc . ',"<>")',
            'D' => '=SUMIF(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$L$5:$L$' . $fimLanc . ')',
            'E' => '=SUMIF(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$M$5:$M$' . $fimLanc . ')',
            'F' => '=SUMIF(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$Q$5:$Q$' . $fimLanc . ')',
            'G' => '=SUMIF(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$R$5:$R$' . $fimLanc . ')',
            'H' => '=SUMIF(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$S$5:$S$' . $fimLanc . ')',
            'I' => '=SUMIF(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$N$5:$N$' . $fimLanc . ')',
            'J' => '=SUMIF(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$O$5:$O$' . $fimLanc . ')',
            'K' => '=SUMIF(Lançamentos!$V$5:$V$' . $fimLanc . ',B{r},Lançamentos!$P$5:$P$' . $fimLanc . ')',
        ];
        foreach ($formulas as $col => $tpl) {
            $ws->setCellValue($col . $row, str_replace('{r}', (string) $row, $tpl));
            if ($row > self::HE_DATA_START) {
                $ws->duplicateStyle($ws->getStyle($col . self::HE_DATA_START), $col . $row);
            }
        }
        if ($row > self::HE_DATA_START) {
            $ws->duplicateStyle($ws->getStyle('A' . self::HE_DATA_START), 'A' . $row);
            $ws->duplicateStyle($ws->getStyle('B' . self::HE_DATA_START), 'B' . $row);
        }
    }

    /** @param array<string, list<array<string,mixed>>> $grupos */
    private function preencherPendentes(Spreadsheet $book, array $grupos): void
    {
        $pendentes = [];
        foreach ($book->getAllSheets() as $sheet) {
            if (str_starts_with($sheet->getTitle(), 'HE_pendente_')) {
                $pendentes[] = $sheet;
            }
        }
        if ($pendentes === []) {
            return;
        }

        $modelo = $pendentes[0];
        $this->limparPendentesDados($modelo);
        // Remove abas-extra do template (mantém só o modelo limpo).
        for ($i = 1, $n = count($pendentes); $i < $n; $i++) {
            $book->removeSheetByIndex($book->getIndex($pendentes[$i]));
        }

        if ($grupos === []) {
            $modelo->setTitle($this->safeTitle('HE_pendente'));
            $modelo->setCellValue('A1', 'HORAS PENDENTE');

            return;
        }

        // Clona o modelo vazio antes de preencher (título temporário único).
        $abas = [$modelo];
        $total = count($grupos);
        for ($i = 1; $i < $total; $i++) {
            $clone = clone $modelo;
            $clone->setTitle($this->safeTitle('HE_pendente__tmp_' . $i));
            $book->addSheet($clone);
            $abas[] = $clone;
        }

        $idx = 0;
        $titulosUsados = [];
        foreach ($book->getAllSheets() as $s) {
            if (!in_array($s, $abas, true)) {
                $titulosUsados[mb_strtoupper($s->getTitle())] = true;
            }
        }
        foreach ($grupos as $comp => $rows) {
            $ws = $abas[$idx++];
            $mesAno = $this->competenciaMesNome((string) $comp);
            $tituloBase = $this->safeTitle('HE_pendente_' . $mesAno);
            $tituloFinal = $tituloBase;
            $suf = 2;
            while (isset($titulosUsados[mb_strtoupper($tituloFinal)])) {
                $sufStr = '_' . $suf++;
                $tituloFinal = $this->safeTitle(mb_substr($tituloBase, 0, 31 - strlen($sufStr)) . $sufStr);
            }
            $titulosUsados[mb_strtoupper($tituloFinal)] = true;

            $ws->setTitle($tituloFinal);
            $ws->setCellValue('A1', 'HORAS PENDENTE - ' . str_replace('_', '/', $mesAno));

            $r = self::PEND_DATA_START;
            foreach ($rows as $row) {
                $ws->setCellValue('A' . $r, (string) $row['nome']);
                $ws->setCellValueExplicit('B' . $r, (string) $row['matricula'], DataType::TYPE_STRING);
                $this->setDuracaoMinutos($ws, 'C' . $r, $this->extrairMinutos($row, 'adn_min', 'adn_h'));
                $this->setDuracaoMinutos($ws, 'D' . $r, $this->extrairMinutos($row, 'he_diurno_50_min', 'he_d50_h'));
                $this->setDuracaoMinutos($ws, 'E' . $r, $this->extrairMinutos($row, 'he_diurno_60_min', 'he_d60_h'));
                $this->setDuracaoMinutos($ws, 'F' . $r, $this->extrairMinutos($row, 'he_diurno_100_min', 'he_d100_h'));
                $this->setDuracaoMinutos($ws, 'G' . $r, $this->extrairMinutos($row, 'he_noturno_50_min', 'he_n50_h'));
                $this->setDuracaoMinutos($ws, 'H' . $r, $this->extrairMinutos($row, 'he_noturno_60_min', 'he_n60_h'));
                $this->setDuracaoMinutos($ws, 'I' . $r, $this->extrairMinutos($row, 'he_noturno_100_min', 'he_n100_h'));
                $r++;
            }
        }
    }

    private function limparPendentesDados(Worksheet $ws): void
    {
        $last = max(self::PEND_DATA_START, (int) $ws->getHighestDataRow());
        for ($r = self::PEND_DATA_START; $r <= max($last, 25); $r++) {
            for ($c = 1; $c <= 9; $c++) {
                $ws->setCellValue([$c, $r], null);
            }
        }
    }

    /** @param array<string,mixed> $row */
    private function chaveColaborador(array $row): string
    {
        $mat = trim((string) ($row['matricula'] ?? ''));
        if ($mat !== '') {
            return $mat;
        }
        $cid = (int) ($row['colaborador_id'] ?? 0);
        if ($cid > 0) {
            return 'ID:' . $cid;
        }
        return (string) ($row['nome'] ?? '');
    }

    /** @param array<string,mixed> $row */
    private function extrairMinutos(array $row, string $campoMin, string $campoHoras): int
    {
        if (array_key_exists($campoMin, $row) && $row[$campoMin] !== null && $row[$campoMin] !== '') {
            return max(0, (int) $row[$campoMin]);
        }
        $horas = (float) ($row[$campoHoras] ?? 0);
        return $horas > 0 ? (int) round($horas * 60) : 0;
    }

    private function setDuracaoMinutos(Worksheet $ws, string $coord, int $minutos, bool $escreverZero = false): void
    {
        if ($minutos <= 0) {
            if ($escreverZero) {
                $ws->setCellValue($coord, 0.0);
                $ws->getStyle($coord)->getNumberFormat()->setFormatCode('[h]:mm');
            }
            return;
        }
        // Fração exata de dia Excel (1 dia = 1440 minutos), eliminando drift de arredondamento.
        $ws->setCellValue($coord, $minutos / 1440.0);
        $ws->getStyle($coord)->getNumberFormat()->setFormatCode('[h]:mm');
    }

    /**
     * Desloca apenas referências relativas de linha, preservando literais entre aspas
     * (ex.: "HE50", "HE60", "HE100") e referências absolutas ($A$50).
     */
    private function deslocarFormulaLinha(string $formula, int $de, int $para): string
    {
        return (string) preg_replace_callback(
            '/"(?:""|[^"])*"|(?<![A-Za-z0-9_$!])(\$?[A-Z]{1,3})' . $de . '(?![0-9A-Za-z_])/',
            static function (array $m) use ($para): string {
                if (!isset($m[1]) || $m[1] === '') {
                    return $m[0];
                }
                return $m[1] . $para;
            },
            $formula
        );
    }

    private function excelDate(string $ymd): ?float
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }
        try {
            return (float) ExcelDate::PHPToExcel(new DateTimeImmutable($ymd));
        } catch (\Throwable) {
            return null;
        }
    }

    private function excelTime(string $hm): ?float
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $hm, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $min = (int) $m[2];
        if ($h > 23 || $min > 59) {
            return null;
        }

        return ($h * 60 + $min) / (24 * 60);
    }

    private function mesNome(string $mesLabel): string
    {
        if (preg_match('/^(\d{2})\/(\d{4})$/', $mesLabel, $m)) {
            return $this->mesPorNumero($m[1]);
        }

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $mesLabel) ?: 'MES');
    }

    private function competenciaMesNome(string $comp): string
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $comp, $m)) {
            return $this->mesPorNumero($m[2]) . '_' . $m[1];
        }

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $comp) ?: 'PEND');
    }

    private function mesPorNumero(string $mm): string
    {
        $nomes = [
            '01' => 'JANEIRO', '02' => 'FEVEREIRO', '03' => 'MARCO', '04' => 'ABRIL',
            '05' => 'MAIO', '06' => 'JUNHO', '07' => 'JULHO', '08' => 'AGOSTO',
            '09' => 'SETEMBRO', '10' => 'OUTUBRO', '11' => 'NOVEMBRO', '12' => 'DEZEMBRO',
        ];

        return $nomes[$mm] ?? $mm;
    }

    private function safeTitle(string $title): string
    {
        $t = preg_replace('/[\\\\\\/*?:\\[\\]]/', '_', $title) ?? 'Sheet';

        return mb_substr($t, 0, 31);
    }
}
