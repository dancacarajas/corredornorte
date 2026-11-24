<?php

declare(strict_types=1);

namespace App\Services;

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\Type\PdfType;

/** Preenche as células do formulário de ponto usando suas fontes incorporadas e coordenadas em pontos. */
final class FolhaPontoFormulario extends Fpdi
{
    public function __construct()
    {
        parent::__construct('P', 'pt', 'Letter');
        $this->SetAutoPageBreak(false);
        $this->SetMargins(0, 0, 0);
        $this->setSourceFile(dirname(__DIR__, 2) . '/storage/templates/folha_ponto_revisada_base.pdf');
        $leitor = $this->getPdfReader($this->currentReaderId);
        $parser = $leitor->getParser();
        $recursos = PdfType::resolve($leitor->getPage(1)->getAttribute('Resources'), $parser);
        $fontes = PdfType::resolve($recursos->value['Font'], $parser);
        foreach (['F1', 'F2', 'CamposRegular', 'CamposNegrito'] as $nome) {
            $fonte = PdfType::resolve($fontes->value[$nome], $parser);
            $larguras = [];
            $inicio = $fonte->value['FirstChar']->value;
            foreach (PdfType::resolve($fonte->value['Widths'], $parser)->value as $i => $largura) {
                $larguras[chr($inicio + $i)] = $largura->value;
            }
            $this->fonts[$nome] = [
                'i' => count($this->fonts) + 1, 'type' => 'FonteDaFolha', 'name' => $nome,
                'subsetted' => false, 'cw' => $larguras, 'objeto' => $fonte,
            ];
        }
    }

    /** Tipo de fonte do FPDF que reutiliza os recursos TrueType do PDF fornecido. */
    protected function _putfontedafolha(array $fonte): void
    {
        $this->_newobj();
        $this->writePdfType($fonte['objeto']);
        $this->_put('endobj');
    }

    private function tipografia(string $texto, bool $negrito, float $tamanho): array
    {
        $bytes = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
        if ($bytes === false) {
            throw new \RuntimeException('Não foi possível codificar um campo da folha.');
        }
        $letras = str_split($bytes);
        $fonte = $this->fonts[$negrito ? 'F2' : 'F1'];
        foreach ($letras as $letra) {
            if (empty($fonte['cw'][$letra])) {
                $fonte = $this->fonts[$negrito ? 'CamposNegrito' : 'CamposRegular'];
                break;
            }
        }
        // O Excel posiciona os glifos na grade de impressão de 300 dpi (0,24 pt).
        $largura = 0.0;
        $operadores = [];
        foreach ($letras as $letra) {
            $natural = $fonte['cw'][$letra] ?? 0;
            $avanco = round($natural * $tamanho / 1000 / 0.24) * 0.24;
            $operadores[] = sprintf('<%02X> %.2F', ord($letra), $natural - $avanco * 1000 / $tamanho);
            $largura += $avanco;
        }
        return [$largura, $fonte['i'], implode(' ', $operadores)];
    }

    public function campo(float $x, float $y, float $largura, string $valor, bool $negrito = false, float $pt = 7.44, bool $centralizar = false): void
    {
        $valor = trim($valor);
        if ($valor === '') {
            return;
        }
        [$medida, $fonte, $texto] = $this->tipografia($valor, $negrito, $pt);
        $escala = min(1.0, $largura / max(0.01, $medida));
        if ($centralizar) {
            $x += ($largura - $escala * $medida) / 2;
        }
        $this->_out(sprintf('q 0 g BT /F%d %.3F Tf %.4F Tz %.3F %.3F Td [%s] TJ ET Q',
            $fonte, $pt, $escala * 100, $x, $this->h - $y, $texto));
    }

    public function duasLinhas(float $x, float $y, float $largura, string $valor): void
    {
        preg_match_all('/ *[^ ]+/u', trim(preg_replace('/[^\S ]+/u', ' ', $valor) ?? $valor), $partes);
        $palavras = $partes[0];
        $linha = '';
        foreach ($palavras as $i => $palavra) {
            $proxima = $linha . $palavra;
            if ($linha !== '' && $this->tipografia($proxima, true, 7.44)[0] > $largura) {
                $this->campo($x, $y, $largura, $linha, true);
                $this->campo($x, $y + 10.08, $largura, implode('', array_slice($palavras, $i)), true);
                return;
            }
            $linha = $proxima;
        }
        $this->campo($x, $y, $largura, $linha, true);
    }
}
