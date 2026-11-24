<?php

declare(strict_types=1);

namespace App\Services;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\Type\PdfType;

/** Coordenadas em pontos, com linhas de base medidas a partir do topo do formulário. */
final class EntregaEquipamentoFormulario extends Fpdi
{
    public function __construct()
    {
        parent::__construct('P', 'pt', [595.30, 841.90]);
        $this->SetAutoPageBreak(false);
        $this->SetMargins(0, 0, 0);
        $this->SetTitle('Termo de Compromisso e Responsabilidade', true);
        $this->SetAuthor('Arcadis Logos S.A.', true);
        $this->SetFont('Helvetica', '', 10);
        $this->SetFont('Helvetica', 'B', 10);
        $this->setSourceFile(dirname(__DIR__, 2) . '/storage/templates/termo_patrimonio_base.pdf');
        $this->carregarFontesDoModelo();
    }

    private function carregarFontesDoModelo(): void
    {
        $leitor = $this->getPdfReader($this->currentReaderId);
        $parser = $leitor->getParser();
        $recursos = PdfType::resolve($leitor->getPage(1)->getAttribute('Resources'), $parser);
        $fontes = PdfType::resolve($recursos->value['Font'], $parser);
        foreach ($fontes->value as $referencia) {
            $fonte = PdfType::resolve($referencia, $parser);
            $nome = $fonte->value['BaseFont']->value;
            $cid = PdfType::resolve($fonte->value['DescendantFonts']->value[0], $parser);
            // O modelo usa Identity-H com CIDs Unicode. W contém pares (CID inicial, larguras).
            $lista = $cid->value['W']->value;
            $larguras = [];
            for ($i = 0; $i < count($lista); $i += 2) {
                $inicio = $lista[$i]->value;
                foreach ($lista[$i + 1]->value as $offset => $largura) {
                    $larguras[$inicio + $offset] = $largura->value;
                }
            }
            $this->fonts[$nome] = [
                'i' => count($this->fonts) + 1, 'type' => 'FonteTermo', 'name' => $nome,
                'subsetted' => false, 'cw' => $larguras, 'objeto' => $fonte,
            ];
        }
    }

    /** Extensão de tipos de fonte do FPDF. O FPDI compartilha os glifos com a página importada. */
    protected function _putfontetermo(array $fonte): void
    {
        $this->_newobj();
        $this->writePdfType($fonte['objeto']);
        $this->_put('endobj');
    }

    public function pagina(int $original): void
    {
        $this->AddPage();
        $this->useTemplate($this->importPage($original));
    }

    /** @return array{0:int,1:string,2:float} recurso PDF, texto hexadecimal e largura em pontos */
    private function medir(string $valor, float $tamanho, string $estilo, string $familia): array
    {
        if ($valor === '') {
            return [0, '', 0.0];
        }
        $nome = $familia . ($estilo === 'B' ? '-Bold' : '');
        $fonte = $this->fonts[$nome] ?? null;
        $unidades = 0;
        foreach (mb_str_split($valor) as $letra) {
            $cid = mb_ord($letra);
            if (!isset($fonte['cw'][$cid])) {
                // Os subconjuntos incorporados não contêm todos os caracteres dos cadastros.
                $fallback = $this->fonts['helvetica' . $estilo];
                $texto = iconv('UTF-8', 'Windows-1252//TRANSLIT', $valor);
                if ($texto === false) {
                    throw new \RuntimeException('Não foi possível codificar o campo do termo.');
                }
                $largura = 0;
                foreach (str_split($texto) as $byte) {
                    $largura += $fallback['cw'][$byte];
                }
                return [$fallback['i'], bin2hex($texto), $largura * $tamanho / 1000];
            }
            $unidades += $fonte['cw'][$cid];
        }
        return [$fonte['i'], bin2hex(mb_convert_encoding($valor, 'UTF-16BE', 'UTF-8')), $unidades * $tamanho / 1000];
    }

    public function largura(string $valor, float $tamanho = 10.0, string $estilo = '', string $familia = 'Arial'): float
    {
        return $this->medir($valor, $tamanho, $estilo, $familia)[2];
    }

    public function campo(
        float $x, float $baseline, float $largura, string $valor, float $tamanho = 10.0,
        string $estilo = '', string $align = 'L', string $familia = 'Arial'
    ): void {
        $valor = trim(preg_replace('/[^\S ]+/u', ' ', $valor) ?? $valor);
        if ($valor === '') {
            return;
        }
        [$fonte, $hex, $medida] = $this->medir($valor, $tamanho, $estilo, $familia);
        if ($medida > $largura) {
            $tamanho *= $largura / $medida;
            $medida = $largura;
        }
        if ($align === 'C') {
            $x += ($largura - $medida) / 2;
        } elseif ($align === 'R') {
            $x += $largura - $medida;
        }
        $this->_out(sprintf('q BT /F%d %.5F Tf %.4F %.4F Td <%s> Tj ET Q',
            $fonte, $tamanho, $x, $this->h - $baseline, $hex));
    }

    /** @return list<string> */
    public function linhas(string $texto, float $largura, float $tamanho, string $estilo = '', string $familia = 'Arial'): array
    {
        $linhas = [];
        foreach (preg_split('/\R/u', trim($texto)) ?: [''] as $trecho) {
            // Limita o exame do texto à linha atual, mesmo em observações de várias páginas.
            $amostraMaxima = max(16, (int) ceil($largura / ($tamanho * 0.1)));
            while (true) {
                $amostra = mb_substr($trecho, 0, $amostraMaxima);
                if ($amostra === $trecho && $this->largura($amostra, $tamanho, $estilo, $familia) <= $largura) {
                    break;
                }
                $min = 1;
                $max = mb_strlen($amostra);
                while ($min < $max) {
                    $meio = intdiv($min + $max + 1, 2);
                    if ($this->largura(mb_substr($amostra, 0, $meio), $tamanho, $estilo, $familia) <= $largura) {
                        $min = $meio;
                    } else {
                        $max = $meio - 1;
                    }
                }
                $corte = $min;
                if (mb_substr($trecho, $corte, 1) !== ' ') {
                    $espaco = mb_strrpos(mb_substr($trecho, 0, $corte), ' ');
                    if ($espaco !== false && $espaco > 0) {
                        $corte = $espaco;
                    }
                }
                $linhas[] = rtrim(mb_substr($trecho, 0, $corte));
                $trecho = ltrim(mb_substr($trecho, $corte));
            }
            $linhas[] = $trecho;
        }
        return $linhas;
    }

    public function numerar(): void
    {
        $total = $this->PageNo();
        for ($pagina = 1; $pagina <= $total; $pagina++) {
            $this->page = $pagina;
            $sufixo = '/' . $total;
            $x = 545.32 - $this->largura($sufixo, 11.26015, '', 'Calibri');
            $this->campo($x - 5.575 * strlen((string) $pagina), 791.35, 30, (string) $pagina, 11, '', 'L', 'Calibri');
            $this->campo($x, 791.35, 40, $sufixo, 11.26015, '', 'L', 'Calibri');
        }
    }
}
