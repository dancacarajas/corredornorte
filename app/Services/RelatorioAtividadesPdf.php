<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use RuntimeException;
use setasign\Fpdi\Fpdi;

/** Preenche a página do PDF R06 fornecido, sem recriar sua grade ou seus textos fixos. */
final class RelatorioAtividadesPdf
{
    private const MODELO = '/storage/templates/relatorio_atividades_r06_base.pdf';
    private const MODELO_XLSX = '/storage/templates/relatorio_atividades_r06.xlsx';
    private const LINHA_INICIAL = 141.12;
    private const ALTURA_LINHA = 15.96;

    /**
     * @param array{relatorio:array<string,mixed>,dias:list<array<string,mixed>>} $dados
     * @return string Caminho absoluto do PDF gerado.
     */
    public function gerarArquivo(array $dados): string
    {
        $root = dirname(__DIR__, 2);
        $modelo = $root . self::MODELO;
        if (!is_file($modelo)) {
            throw new RuntimeException('Modelo PDF R06 não encontrado.');
        }
        $relatorio = $dados['relatorio'] ?? null;
        if (!is_array($relatorio) || (int) ($relatorio['id'] ?? 0) <= 0) {
            throw new RuntimeException('Dados do relatório inválidos.');
        }
        $inicio = $this->data((string) ($relatorio['data_inicio'] ?? ''));
        $fim = $this->data((string) ($relatorio['data_fim'] ?? ''));
        if ($fim < $inicio) {
            throw new RuntimeException('Período do relatório inválido.');
        }
        $totalDias = (int) $inicio->diff($fim)->days + 1;
        if ($totalDias > 31) {
            throw new RuntimeException('O modelo R06 comporta no máximo 31 dias por relatório.');
        }

        $porData = [];
        foreach ($dados['dias'] ?? [] as $dia) {
            if (is_array($dia) && isset($dia['data'])) {
                $porData[(string) $dia['data']] = $dia;
            }
        }
        $feriados = $this->feriadosDoModelo($root . self::MODELO_XLSX);
        $pdf = new Fpdi('P', 'pt', 'Letter');
        $pdf->SetAutoPageBreak(false);
        if ($pdf->setSourceFile($modelo) !== 1) {
            throw new RuntimeException('O modelo R06 deve ter uma página.');
        }
        $pagina = $pdf->importPage(1);
        $tamanho = $pdf->getTemplateSize($pagina);
        if (abs($tamanho['width'] - 612.0) > 0.1 || abs($tamanho['height'] - 792.0) > 0.1) {
            throw new RuntimeException('Dimensões do modelo PDF R06 inválidas.');
        }
        $pdf->SetTitle('Relatório de Atividades | Arcadis RH', true);
        $pdf->SetAuthor('Arcadis RH');
        $pdf->AddPage();
        $pdf->useTemplate($pagina);
        $pdf->SetTextColor(0, 0, 0);

        // A tonalidade cinza do R06 acompanha fins de semana e feriados.
        // O PDF fornecido contém um mês de exemplo; repõe somente o interior
        // das células diárias para que a cor corresponda ao período exportado.
        for ($indice = 0; $indice < 31; $indice++) {
            $dataCor = $indice < $totalDias
                ? $inicio->add(new DateInterval('P' . $indice . 'D')) : null;
            $diaCor = $dataCor === null ? [] : ($porData[$dataCor->format('Y-m-d')] ?? []);
            $escuro = $dataCor !== null && (
                (int) $dataCor->format('N') >= 6
                || isset($feriados[$dataCor->format('Y-m-d')])
                || (isset($diaCor['tipo_dia']) && (string) $diaCor['tipo_dia'] !== 'trabalho')
            );
            $this->fundoDaLinha($pdf, self::LINHA_INICIAL + $indice * self::ALTURA_LINHA, $escuro);
        }

        $meses = [1 => 'JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO',
            'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];
        $this->celula($pdf, (string) ($relatorio['nome'] ?? ''), 124, 75.3, 240, 8.7, 5.2);
        $this->celula($pdf, (string) (($relatorio['funcao'] ?? '') ?: ($relatorio['cargo'] ?? '')),
            383, 75.3, 149, 8.7, 5.2);
        $this->celula($pdf, (string) ($relatorio['os_codigo'] ?? ''), 101, 84.2, 22, 8.7, 5.0);
        $this->celula($pdf, (string) ($relatorio['os_nome'] ?? ''), 149, 84.2, 102, 8.7, 5.0);
        $this->celula($pdf, (string) ($relatorio['gestor_nome'] ?? ''), 293, 84.2, 116, 8.7, 5.0);
        $this->celula($pdf, (string) ($relatorio['fiscal_vale'] ?? ''), 442, 84.2, 90, 8.7, 5.0);
        $this->celula($pdf, $meses[(int) $fim->format('n')], 124, 93.1, 127, 8.7, 5.0);
        $this->celula($pdf, $fim->format('Y'), 293, 93.1, 71, 8.7, 5.0);
        $this->celula($pdf, (string) ($relatorio['contrato_codigo'] ?? ''), 393, 93.1, 139, 8.7, 5.0);

        $contadores = ['classificada' => 0, 'remoto' => 0, 'site' => 0, 'viagem' => 0];
        $nomesDias = [1 => 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira',
            'sexta-feira', 'sábado', 'domingo'];
        for ($indice = 0; $indice < $totalDias; $indice++) {
            $data = $inicio->add(new DateInterval('P' . $indice . 'D'));
            $registro = $porData[$data->format('Y-m-d')] ?? [];
            $y = self::LINHA_INICIAL + $indice * self::ALTURA_LINHA;
            $this->celula($pdf, $data->format('d/m/y'), 78.5, $y, 21.5, 15.7, 4.8);
            $this->celula($pdf, $nomesDias[(int) $data->format('N')], 100.6, $y, 23.0, 15.7, 3.9);
            $this->celula($pdf, (string) ($registro['descricao'] ?? ''), 124, $y, 127.5, 15.7, 5.0);
            $this->celula($pdf, (string) ($registro['area_atendimento'] ?? ''), 252.3, $y, 40.1, 15.7, 4.8);
            $this->celula($pdf, (string) ($registro['municipio'] ?? ''), 293, $y, 25, 15.7, 4.7);
            $this->celula($pdf, (string) ($registro['projeto_obra'] ?? ''), 318.7, $y, 45.5, 15.7, 4.7);
            $this->celula($pdf, (string) ($registro['centro_custo'] ?? ''), 365, $y, 27.3, 15.7, 4.7);
            if (!empty($registro['area_classificada'])) {
                $this->marca($pdf, 393.0, 16.1, $y);
                $contadores['classificada']++;
            }
            $ambiente = (string) ($registro['ambiente'] ?? '');
            if (isset($contadores[$ambiente]) && $ambiente !== 'classificada') {
                $colunas = ['remoto' => 409.5, 'site' => 426.3, 'viagem' => 443.1];
                $this->marca($pdf, $colunas[$ambiente], 16.4, $y);
                $contadores[$ambiente]++;
            }
            $observacao = (string) ($registro['observacoes'] ?? '');
            if ($observacao === '') {
                $observacao = $feriados[$data->format('Y-m-d')] ?? '';
            }
            $this->celula($pdf, $observacao, 460, $y, 72.5, 15.7, 4.5);
        }
        $this->celula($pdf, (string) $contadores['classificada'], 393, 132.1, 16.1, 8.5, 5.0, true);
        $this->celula($pdf, (string) $contadores['remoto'], 409.5, 132.1, 16.4, 8.5, 5.0, true);
        $this->celula($pdf, (string) $contadores['site'], 426.3, 132.1, 16.4, 8.5, 5.0, true);
        $this->celula($pdf, (string) $contadores['viagem'], 443.1, 132.1, 16.4, 8.5, 5.0, true);
        $this->celula($pdf, (string) ($relatorio['nome'] ?? ''), 264, 718.5, 84, 11, 5.5, true);

        $diretorio = $root . '/storage/uploads/relatorios';
        if (!is_dir($diretorio) && !mkdir($diretorio, 0775, true) && !is_dir($diretorio)) {
            throw new RuntimeException('Não foi possível criar a pasta dos relatórios.');
        }
        $arquivo = $diretorio . '/relatorio_' . (int) $relatorio['id'] . '.pdf';
        $pdf->Output('F', $arquivo);
        if (!is_file($arquivo) || filesize($arquivo) < 1000) {
            throw new RuntimeException('Falha ao gravar o PDF do relatório.');
        }
        return $arquivo;
    }

    private function data(string $valor): DateTimeImmutable
    {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new DateTimeZone('UTC'));
        if (!$data instanceof DateTimeImmutable || $data->format('Y-m-d') !== $valor) {
            throw new RuntimeException('Data do período inválida: ' . $valor);
        }
        return $data;
    }

    /** @return array<string,string> */
    private function feriadosDoModelo(string $modelo): array
    {
        $livro = IOFactory::load($modelo);
        $lista = $livro->getSheet(0);
        $feriados = [];
        for ($linha = 5; $linha <= 42; $linha++) {
            $serial = $lista->getCell('Q' . $linha)->getValue();
            if (is_numeric($serial)) {
                $data = DataExcel::excelToDateTimeObject((float) $serial)->format('Y-m-d');
                $feriados[$data] = (string) ($lista->getCell('S' . $linha)->getValue() ?? '');
            }
        }
        $livro->disconnectWorksheets();
        return $feriados;
    }

    private function marca(Fpdi $pdf, float $x, float $largura, float $y): void
    {
        $this->celula($pdf, 'X', $x, $y, $largura, 15.7, 5.2, true);
    }

    private function fundoDaLinha(Fpdi $pdf, float $y, bool $escuro): void
    {
        // Limites das colunas F:R medidos no PDF original. O pequeno recuo
        // mantém visíveis todos os traços da grade do próprio modelo.
        $limites = [123.84, 252.12, 292.80, 318.48, 364.68, 392.88,
            409.44, 426.24, 443.04, 459.84, 533.16];
        foreach ($limites as $indice => $inicio) {
            if (!isset($limites[$indice + 1])) {
                break;
            }
            $tom = $escuro ? ($indice < 9 ? 191 : 255) : 242;
            $pdf->SetFillColor($tom, $tom, $tom);
            $pdf->Rect($inicio + 0.22, $y + 0.22,
                $limites[$indice + 1] - $inicio - 0.44, self::ALTURA_LINHA - 0.44, 'F');
        }
    }

    private function celula(
        Fpdi $pdf,
        string $texto,
        float $x,
        float $y,
        float $largura,
        float $altura,
        float $tamanho,
        bool $centralizar = false
    ): void {
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');
        if ($texto === '') {
            return;
        }
        $convertido = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
        if ($convertido === false) {
            throw new RuntimeException('Texto inválido no relatório.');
        }
        $margem = $centralizar ? 0.5 : 1.2;
        $disponivel = $largura - 2 * $margem;
        for ($fonte = $tamanho; $fonte >= 3.0; $fonte -= 0.2) {
            $pdf->SetFont('Arial', '', $fonte);
            $linhas = $this->quebrar($pdf, $convertido, $disponivel);
            $entrelinha = $fonte * 1.17;
            if (count($linhas) * $entrelinha <= $altura - 1.0) {
                $primeiroY = $y + ($altura - count($linhas) * $entrelinha) / 2 + $entrelinha * 0.75;
                foreach ($linhas as $indice => $linha) {
                    $textoX = $centralizar ? $x + ($largura - $pdf->GetStringWidth($linha)) / 2 : $x + $margem;
                    $pdf->Text($textoX, $primeiroY + $indice * $entrelinha, $linha);
                }
                return;
            }
        }
        throw new RuntimeException('Texto longo demais para o campo do modelo R06.');
    }

    /** @return list<string> */
    private function quebrar(Fpdi $pdf, string $texto, float $largura): array
    {
        $linhas = [''];
        foreach (explode(' ', $texto) as $palavra) {
            $ultima = count($linhas) - 1;
            $candidata = $linhas[$ultima] === '' ? $palavra : $linhas[$ultima] . ' ' . $palavra;
            if ($pdf->GetStringWidth($candidata) <= $largura) {
                $linhas[$ultima] = $candidata;
            } elseif ($pdf->GetStringWidth($palavra) <= $largura) {
                $linhas[] = $palavra;
            } else {
                if ($linhas[$ultima] !== '') {
                    $linhas[] = '';
                }
                foreach (str_split($palavra) as $caractere) {
                    $ultima = count($linhas) - 1;
                    if ($pdf->GetStringWidth($linhas[$ultima] . $caractere) > $largura) {
                        $linhas[] = $caractere;
                    } else {
                        $linhas[$ultima] .= $caractere;
                    }
                }
            }
        }
        return $linhas;
    }
}
