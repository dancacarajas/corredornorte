<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

/** Emite a folha de ponto individual no formulário revisado da Arcadis. */
final class FolhaPdfRenderer
{
    public const LAYOUT_VERSION = 5;

    // Bordas impressas na planilha de referência (Letter, em pontos).
    private const COLUNAS = [18.24, 40.32, 81.384, 117.17, 157.01, 198.79, 233.14, 410.09, 465.79, 520.30, 583.20];
    private const LINHAS = [
        230.23, 242.47, 254.71, 266.95, 279.22, 291.46, 303.70, 315.94,
        328.18, 340.42, 352.66, 364.92, 377.16, 389.40, 401.64, 413.88,
        426.12, 438.38, 450.62, 462.86, 475.10, 487.34, 499.58, 511.82,
        524.09, 536.33, 548.57, 560.81, 573.05, 585.29, 597.55, 609.79,
    ];
    private const MESES = [1 => 'JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO',
        'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];

    /** @param array<string,mixed> $payload */
    public function render(array $payload, string $outPath): void
    {
        $dir = dirname($outPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta do PDF.');
        }
        // Também aceita o payload anterior, cujo período era enviado como texto.
        if (isset($payload['periodo_inicio'], $payload['periodo_fim'])) {
            $inicio = new DateTimeImmutable($payload['periodo_inicio']);
            $fim = new DateTimeImmutable($payload['periodo_fim']);
        } elseif (preg_match('~^(\d{2}/\d{2}/\d{4}) A (\d{2}/\d{2}/\d{4})$~i', $payload['periodo'] ?? '', $datas)) {
            $inicio = DateTimeImmutable::createFromFormat('!d/m/Y', $datas[1]);
            $fim = DateTimeImmutable::createFromFormat('!d/m/Y', $datas[2]);
        } else {
            throw new RuntimeException('O período da folha não foi informado.');
        }
        $dias = $this->diasDoPeriodo($payload['dias'] ?? [], $inicio, $fim);
        $pdf = new FolhaPontoFormulario();
        $pdf->SetTitle('Folha de Ponto Individual | Arcadis RH', true);
        $pdf->SetAuthor('Arcadis RH', true);
        $pdf->SetSubject('Arcadis RH | folha de ponto retrato v' . self::LAYOUT_VERSION, true);
        $pdf->AddPage();
        $this->grade($pdf, $dias);
        $this->cabecalho($pdf, $payload['espelho'], $fim);
        $this->totais($pdf, $payload['totais'] ?? []);
        $pdf->Output('F', $outPath);
    }

    private function diasDoPeriodo(array $registros, DateTimeImmutable $inicio, DateTimeImmutable $fim): array
    {
        $marcados = [];
        foreach ($registros as $registro) {
            $chave = $registro['data'] ?? substr((string) ($registro['data_fmt'] ?? ''), 0, 5);
            $marcados[$chave] = $registro;
        }
        $total = (int) $inicio->diff($fim)->format('%r%a') + 1;
        if ($total < 1 || $total > PeriodoPonto::MAX_DIAS_PDF) {
            throw new RuntimeException('O período da folha não cabe no formulário.');
        }
        $linhas = [];
        for ($data = $inicio; $data <= $fim; $data = $data->modify('+1 day')) {
            $dia = $marcados[$data->format('Y-m-d')] ?? $marcados[$data->format('d/m')] ?? [];
            $dia['rotulo_data'] = $data->format('d/m');
            $dia['observacao_pdf'] = $this->observacao($dia, (int) $data->format('N'));
            $linhas[] = $dia;
        }
        return $linhas;
    }

    private function observacao(array $dia, int $semana): string
    {
        $nome = trim((string) ($dia['feriado_nome'] ?? ''));
        $tipo = $dia['tipo_dia'] ?? '';
        $rotulo = $nome !== '' ? $nome : match (true) {
            $tipo === 'feriado' => 'FERIADO',
            $semana === 6 => 'SÁBADO',
            $semana === 7 => 'DOMINGO',
            in_array($tipo, ['folga', 'folga_escala'], true) => 'FOLGA',
            default => '',
        };
        $resumo = trim((string) ($dia['ocorrencia_resumo'] ?? ''));
        $nota = match (true) {
            !empty($dia['atestado']) => 'Atestado',
            !empty($dia['dia_falta']) => 'Falta',
            str_starts_with($resumo, 'Justificado') => $resumo,
            default => '',
        };
        return implode(' / ', array_filter([$rotulo, $nota]));
    }

    private function grade(FolhaPontoFormulario $pdf, array $dias): void
    {
        $linhas = self::LINHAS;
        $total = count($dias);
        $compacta = $total > 31;
        if ($compacta) {
            // Períodos especiais de 32–40 dias conservam cabeçalho, rodapé e uma página para assinar.
            $altura = (end($linhas) - $linhas[0]) / $total;
            $linhas = array_map(static fn(int $i): float => self::LINHAS[0] + $i * $altura, range(0, $total));
        }
        $modelo = $pdf->importPage(1);
        if ($compacta) {
            $pdf->useTemplate($modelo);
            $pdf->SetFillColor(255);
            $pdf->Rect(18.24, $linhas[0], 565.68, end($linhas) - $linhas[0] + 0.72, 'F');
        }
        $pdf->SetFillColor(192);
        foreach ($dias as $i => $dia) {
            if ($dia['observacao_pdf'] !== '') {
                $pdf->Rect(40.56, $linhas[$i] + 0.24, 370.01, $linhas[$i + 1] - $linhas[$i] + 0.24, 'F');
            }
        }
        if ($compacta) {
            $pdf->SetFillColor(0);
            foreach ($linhas as $y) {
                $pdf->Rect(18.24, $y, 565.68, 0.72, 'F');
            }
            foreach (self::COLUNAS as $x) {
                $pdf->Rect($x, $linhas[0], 0.72, end($linhas) - $linhas[0] + 0.72, 'F');
            }
        } else {
            $pdf->useTemplate($modelo);
        }
        foreach ($dias as $i => $dia) {
            $fator = min(1.0, ($linhas[$i + 1] - $linhas[$i]) / 12.24);
            $y = $linhas[$i] + 8.88 * $fator;
            $pdf->campo(20.88, $y, 18.9, $dia['rotulo_data'], false, 7.44 * $fator);
            foreach ([1 => 'ent1', 'sai1', 'ent2', 'sai2'] as $coluna => $campo) {
                $this->celula($pdf, $coluna, $y, substr((string) ($dia[$campo] ?? ''), 0, 5), $fator);
            }
            $pdf->campo(235.06, $y + 0.24, 173.1, $dia['observacao_pdf'], true, 8.4 * $fator);
            foreach ([7 => 'horas_extras_min', 'adn_min', 'horas_falta_min'] as $coluna => $campo) {
                $this->celula($pdf, $coluna, $y, $this->horas($dia[$campo] ?? 0), $fator);
            }
        }
    }

    private function celula(FolhaPontoFormulario $pdf, int $coluna, float $y, string $valor, float $fator = 1.0): void
    {
        $x = self::COLUNAS[$coluna] + 1.5;
        $largura = self::COLUNAS[$coluna + 1] - $x - 0.8;
        $pdf->campo($x, $y, $largura, $valor, false, 7.44 * $fator, true);
    }

    private function cabecalho(FolhaPontoFormulario $pdf, array $e, DateTimeImmutable $fim): void
    {
        $pdf->campo(19.92, 109.22, 318, mb_strtoupper((string) ($e['nome'] ?? '')), true);
        $pdf->campo(341.42, 109.22, 176, (string) ($e['matricula'] ?? ''), true);
        $pdf->campo(19.92, 131.33, 443, mb_strtoupper(trim((string) ($e['funcao'] ?? '')) ?: (string) ($e['cargo'] ?? '')), true);
        $pdf->campo(467.47, 131.33, 76, self::MESES[(int) $fim->format('n')], true);
        $pdf->campo(547.90, 131.33, 33, $fim->format('Y'), true);
        $pdf->duasLinhas(19.92, 153.41, 256.5, $this->horario($e));
        $contrato = trim((string) ($e['contrato_nome'] ?? '')) ?: (string) ($e['contrato_codigo'] ?? '');
        $pdf->duasLinhas(279.96, 153.41, 301.5, mb_strtoupper($contrato));
    }

    private function faixa(array $turno): string
    {
        $pontos = array_map(static fn(string $campo): string => substr((string) ($turno[$campo] ?? ''), 0, 5),
            ['entrada', 'intervalo_inicio', 'intervalo_fim', 'saida']);
        if ($pontos[0] === '' || $pontos[3] === '') {
            return '';
        }
        if ($pontos[1] === '' || $pontos[2] === '') {
            return $pontos[0] . ' AS ' . $pontos[3];
        }
        return $pontos[0] . ' AS ' . $pontos[1] . ' E DAS ' . $pontos[2] . ' AS ' . $pontos[3];
    }

    private function horario(array $e): string
    {
        $descricao = trim((string) ($e['jornada_descricao'] ?? ''));
        if (preg_match('/\d{1,2}:\d{2}.*\d{1,2}:\d{2}/', $descricao)) {
            return mb_strtoupper($descricao);
        }
        $rotulos = ['SEG' => 'SEGUNDA', 'TER' => 'TERÇA', 'QUA' => 'QUARTA', 'QUI' => 'QUINTA',
            'SEX' => 'SEXTA', 'SAB' => 'SÁBADO', 'DOM' => 'DOMINGO'];
        $semana = array_column($e['jornada_semanal'] ?? [], null, 'dia');
        $grupos = [];
        $anterior = '';
        foreach ($rotulos as $dia => $nome) {
            $turno = $semana[$dia] ?? [];
            $faixa = ($turno['tipo'] ?? '') === 'variavel' ? 'HORÁRIO VARIÁVEL' : $this->faixa($turno);
            if ($faixa !== '') {
                if ($faixa === $anterior) {
                    $grupos[array_key_last($grupos)]['fim'] = $nome;
                } else {
                    $grupos[] = ['inicio' => $nome, 'fim' => $nome, 'faixa' => $faixa];
                }
            }
            $anterior = $faixa;
        }
        if (in_array($e['escala_tipo'] ?? '', ['12x36', 'ciclo'], true)) {
            $turnos = array_values(array_unique(array_column($grupos, 'faixa')));
            $escala = ($e['escala_codigo'] ?? '') ?: $e['escala_tipo'];
            return mb_strtoupper(trim($escala . ' ' . implode(' / ', $turnos ?: [$this->faixa($e['jornada'] ?? [])])));
        }
        $partes = [];
        foreach ($grupos as $grupo) {
            $dias = $grupo['inicio'] === $grupo['fim'] ? $grupo['inicio'] : $grupo['inicio'] . ' A ' . $grupo['fim'];
            $partes[] = $dias . ' ' . $grupo['faixa'];
        }
        return $partes ? implode(' / ', $partes) : ($descricao ?: $this->faixa($e['jornada'] ?? []));
    }

    private function totais(FolhaPontoFormulario $pdf, array $totais): void
    {
        foreach ([6 => 'trab', 'he', 'adn', 'falta'] as $coluna => $campo) {
            $this->celula($pdf, $coluna, 618.67, $this->horas($totais[$campo] ?? 0));
        }
    }

    private function horas(mixed $minutos): string
    {
        $minutos = (int) $minutos;
        return $minutos > 0 ? sprintf('%02d:%02d', intdiv($minutos, 60), $minutos % 60) : '';
    }
}
