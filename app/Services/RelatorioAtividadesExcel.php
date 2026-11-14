<?php

declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as DataExcel;
use RuntimeException;
use ZipArchive;

/**
 * Preenche uma cópia do arquivo R06 sem reconstruir sua planilha, estilos ou impressão.
 */
final class RelatorioAtividadesExcel
{
    private const PLANILHA = 'xl/worksheets/sheet2.xml';
    private const NS_PLANILHA = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private string $modelo;

    public function __construct(?string $modelo = null)
    {
        $this->modelo = $modelo ?? dirname(__DIR__, 2) . '/storage/templates/relatorio_atividades_r06.xlsx';
    }

    /**
     * @param array{relatorio:array<string,mixed>,dias:list<array<string,mixed>>} $dados
     * @return string Caminho do XLSX temporário; cabe ao chamador removê-lo após o download.
     */
    public function gerarArquivo(array $dados): string
    {
        if (!is_file($this->modelo)) {
            throw new RuntimeException('Modelo R06 do relatório de atividades não encontrado.');
        }

        $relatorio = $dados['relatorio'] ?? null;
        if (!is_array($relatorio)) {
            throw new RuntimeException('Dados do relatório inválidos.');
        }

        $inicio = $this->data((string) ($relatorio['data_inicio'] ?? ''));
        $fim = $this->data((string) ($relatorio['data_fim'] ?? ''));
        if ($fim < $inicio) {
            throw new RuntimeException('Período do relatório inválido.');
        }
        $quantidadeDias = (int) $inicio->diff($fim)->days + 1;
        if ($quantidadeDias > 31) {
            throw new RuntimeException('O modelo R06 comporta no máximo 31 dias por relatório.');
        }

        $porData = [];
        foreach ($dados['dias'] ?? [] as $dia) {
            if (is_array($dia) && isset($dia['data'])) {
                $porData[(string) $dia['data']] = $dia;
            }
        }
        $feriados = $this->feriadosDoModelo();

        $arquivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'arcadis_ra_' . bin2hex(random_bytes(12)) . '.xlsx';
        if (!copy($this->modelo, $arquivo)) {
            throw new RuntimeException('Não foi possível copiar o modelo R06.');
        }

        $zip = new ZipArchive();
        try {
            if ($zip->open($arquivo) !== true) {
                throw new RuntimeException('Não foi possível abrir a cópia do modelo R06.');
            }
            $xml = $zip->getFromName(self::PLANILHA);
            if (!is_string($xml)) {
                throw new RuntimeException('A aba Formulário não foi encontrada no modelo R06.');
            }
            $doc = $this->abrirXml($xml);
            $xpath = new DOMXPath($doc);
            $xpath->registerNamespace('s', self::NS_PLANILHA);

            $meses = [1 => 'JANEIRO', 'FEVEREIRO', 'MARÇO', 'ABRIL', 'MAIO', 'JUNHO',
                'JULHO', 'AGOSTO', 'SETEMBRO', 'OUTUBRO', 'NOVEMBRO', 'DEZEMBRO'];
            $cabecalho = [
                'F3' => (string) ($relatorio['nome'] ?? ''),
                'N3' => (string) (($relatorio['funcao'] ?? '') ?: ($relatorio['cargo'] ?? '')),
                'C4' => (string) ($relatorio['os_codigo'] ?? ''),
                'G4' => (string) ($relatorio['os_nome'] ?? ''),
                'K4' => (string) ($relatorio['gestor_nome'] ?? ''),
                'P4' => (string) ($relatorio['fiscal_vale'] ?? ''),
                'F5' => $meses[(int) $fim->format('n')],
                'K5' => (int) $fim->format('Y'),
                'N5' => (string) ($relatorio['contrato_codigo'] ?? ''),
            ];
            foreach ($cabecalho as $celula => $valor) {
                $this->definirCelula($doc, $xpath, $celula, $valor);
            }
            $this->definirCacheFormula($doc, $xpath, 'I47', (string) ($relatorio['nome'] ?? ''));

            // B10:R40 é o bloco de 31 dias do próprio formulário R06.
            $contadores = ['N' => 0, 'O' => 0, 'P' => 0, 'Q' => 0];
            for ($indice = 0; $indice < 31; $indice++) {
                $linha = 10 + $indice;
                $dia = $indice < $quantidadeDias
                    ? $inicio->add(new DateInterval('P' . $indice . 'D')) : null;
                $registro = $dia === null ? [] : ($porData[$dia->format('Y-m-d')] ?? []);
                $serial = $dia === null ? null : $this->serialExcel($dia);
                $this->definirCelula($doc, $xpath, 'B' . $linha, $serial);
                $this->definirCelula($doc, $xpath, 'C' . $linha, $serial);
                $this->definirCelula($doc, $xpath, 'D' . $linha,
                    $dia === null ? null : (int) $dia->format('N'));
                if ($dia === null) {
                    $this->definirCelula($doc, $xpath, 'E' . $linha, null);
                } else {
                    $marcacao = $feriados[$dia->format('Y-m-d')]['marcacao'] ?? '';
                    $this->definirCacheFormula($doc, $xpath, 'E' . $linha, $marcacao);
                }

                $valores = [
                    'F' => (string) ($registro['descricao'] ?? ''),
                    'J' => (string) ($registro['area_atendimento'] ?? ''),
                    'K' => (string) ($registro['municipio'] ?? ''),
                    'L' => (string) ($registro['projeto_obra'] ?? ''),
                    'M' => (string) ($registro['centro_custo'] ?? ''),
                    'N' => !empty($registro['area_classificada']) ? 'X' : '',
                    'O' => ($registro['ambiente'] ?? null) === 'remoto' ? 'X' : '',
                    'P' => ($registro['ambiente'] ?? null) === 'site' ? 'X' : '',
                    'Q' => ($registro['ambiente'] ?? null) === 'viagem' ? 'X' : '',
                ];
                foreach ($valores as $coluna => $valor) {
                    $this->definirCelula($doc, $xpath, $coluna . $linha, $valor);
                    if ($valor === 'X' && isset($contadores[$coluna])) {
                        $contadores[$coluna]++;
                    }
                }
                // Mantém a busca de feriados do modelo quando não há observação digitada.
                $observacao = (string) ($registro['observacoes'] ?? '');
                if ($dia === null || $observacao !== '') {
                    $this->definirCelula($doc, $xpath, 'R' . $linha, $observacao);
                } else {
                    $textoFeriado = $feriados[$dia->format('Y-m-d')]['descricao'] ?? '';
                    $this->definirCacheFormula($doc, $xpath, 'R' . $linha, $textoFeriado);
                }
            }
            foreach ($contadores as $coluna => $total) {
                $this->definirCacheFormula($doc, $xpath, $coluna . '9', $total);
            }

            $zip->addFromString(self::PLANILHA, $doc->saveXML());
            $this->removerCadeiaDeCalculo($zip);
            if (!$zip->close()) {
                throw new RuntimeException('Não foi possível finalizar o XLSX do relatório.');
            }
            return $arquivo;
        } catch (\Throwable $e) {
            $zip->close();
            @unlink($arquivo);
            throw $e;
        }
    }

    private function data(string $valor): DateTimeImmutable
    {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new DateTimeZone('UTC'));
        if (!$data instanceof DateTimeImmutable || $data->format('Y-m-d') !== $valor) {
            throw new RuntimeException('Data do período inválida: ' . $valor);
        }
        return $data;
    }

    private function serialExcel(DateTimeImmutable $data): int
    {
        $base = new DateTimeImmutable('1899-12-30', new DateTimeZone('UTC'));
        return (int) $base->diff($data)->days;
    }

    /** @return array<string,array{marcacao:string,descricao:string}> */
    private function feriadosDoModelo(): array
    {
        $livro = IOFactory::load($this->modelo);
        $lista = $livro->getSheet(0);
        $feriados = [];
        for ($linha = 5; $linha <= 42; $linha++) {
            $serial = $lista->getCell('Q' . $linha)->getValue();
            if (!is_numeric($serial)) {
                continue;
            }
            $data = DataExcel::excelToDateTimeObject((float) $serial)->format('Y-m-d');
            $feriados[$data] = [
                'marcacao' => (string) ($lista->getCell('R' . $linha)->getValue() ?? ''),
                'descricao' => (string) ($lista->getCell('S' . $linha)->getValue() ?? ''),
            ];
        }
        $livro->disconnectWorksheets();
        return $feriados;
    }

    private function abrirXml(string $xml): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('XML inválido no modelo R06.');
        }
        return $doc;
    }

    private function definirCelula(
        DOMDocument $doc,
        DOMXPath $xpath,
        string $referencia,
        string|int|null $valor
    ): void {
        $no = $xpath->query('//s:sheetData/s:row/s:c[@r="' . $referencia . '"]')->item(0);
        if (!$no instanceof DOMElement) {
            throw new RuntimeException('Célula ' . $referencia . ' ausente no modelo R06.');
        }
        while ($no->firstChild !== null) {
            $no->removeChild($no->firstChild);
        }
        $no->removeAttribute('t');
        if ($valor === null || $valor === '') {
            return;
        }
        if (is_int($valor)) {
            $no->appendChild($doc->createElementNS(self::NS_PLANILHA, 'v', (string) $valor));
            return;
        }
        $no->setAttribute('t', 'inlineStr');
        $inline = $doc->createElementNS(self::NS_PLANILHA, 'is');
        $texto = $doc->createElementNS(self::NS_PLANILHA, 't');
        $texto->setAttribute('xml:space', 'preserve');
        $texto->appendChild($doc->createTextNode($valor));
        $inline->appendChild($texto);
        $no->appendChild($inline);
    }

    private function definirCacheFormula(
        DOMDocument $doc,
        DOMXPath $xpath,
        string $referencia,
        string|int $valor
    ): void {
        $no = $xpath->query('//s:sheetData/s:row/s:c[@r="' . $referencia . '"]')->item(0);
        if (!$no instanceof DOMElement) {
            throw new RuntimeException('Célula ' . $referencia . ' ausente no modelo R06.');
        }
        foreach (iterator_to_array($no->childNodes) as $filho) {
            if ($filho instanceof DOMElement && in_array($filho->localName, ['v', 'is'], true)) {
                $no->removeChild($filho);
            }
        }
        if (is_int($valor)) {
            $no->removeAttribute('t');
        } else {
            $no->setAttribute('t', 'str');
        }
        $valorNo = $doc->createElementNS(self::NS_PLANILHA, 'v');
        $valorNo->appendChild($doc->createTextNode((string) $valor));
        $no->appendChild($valorNo);
    }

    private function removerCadeiaDeCalculo(ZipArchive $zip): void
    {
        if ($zip->locateName('xl/calcChain.xml') === false) {
            return;
        }
        $zip->deleteName('xl/calcChain.xml');

        $rels = 'xl/_rels/workbook.xml.rels';
        $relsXml = $zip->getFromName($rels);
        if (is_string($relsXml)) {
            $doc = $this->abrirXml($relsXml);
            foreach (iterator_to_array($doc->getElementsByTagName('Relationship')) as $rel) {
                if (str_ends_with((string) $rel->getAttribute('Type'), '/calcChain')) {
                    $rel->parentNode?->removeChild($rel);
                }
            }
            $zip->addFromString($rels, $doc->saveXML());
        }

        $tipos = '[Content_Types].xml';
        $tiposXml = $zip->getFromName($tipos);
        if (is_string($tiposXml)) {
            $doc = $this->abrirXml($tiposXml);
            foreach (iterator_to_array($doc->getElementsByTagName('Override')) as $tipo) {
                if ($tipo->getAttribute('PartName') === '/xl/calcChain.xml') {
                    $tipo->parentNode?->removeChild($tipo);
                }
            }
            $zip->addFromString($tipos, $doc->saveXML());
        }
    }
}
