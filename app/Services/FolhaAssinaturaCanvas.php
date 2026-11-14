<?php

declare(strict_types=1);

namespace App\Services;

use chillerlan\QRCode\Data\QRMatrix;
use setasign\Fpdi\Fpdi;

/** Operações vetoriais usadas no cartão e na faixa de validação. */
final class FolhaAssinaturaCanvas extends Fpdi
{
    public function roundedRect(float $x, float $y, float $w, float $h, float $r, string $paint): void
    {
        $right = $x + $w;
        $bottom = $y + $h;
        $control = $r * 0.55228475;
        $this->_out(sprintf('q 1 0 0 -1 0 %.2F cm', $this->h));
        $this->_out(sprintf('%.2F %.2F m %.2F %.2F l', $x + $r, $y, $right - $r, $y));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $right - $r + $control, $y, $right, $y + $r - $control, $right, $y + $r));
        $this->_out(sprintf('%.2F %.2F l', $right, $bottom - $r));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $right, $bottom - $r + $control, $right - $r + $control, $bottom, $right - $r, $bottom));
        $this->_out(sprintf('%.2F %.2F l', $x + $r, $bottom));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $x + $r - $control, $bottom, $x, $bottom - $r + $control, $x, $bottom - $r));
        $this->_out(sprintf('%.2F %.2F l', $x, $y + $r));
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c h %s Q', $x, $y + $r - $control, $x + $r - $control, $y, $x + $r, $y, $paint));
    }

    public function fillQrMatrix(QRMatrix $matriz, float $x, float $y, float $lado): void
    {
        $total = $matriz->getSize();
        $modulo = $lado / $total;
        $this->_out(sprintf('q %.8F 0 0 %.8F %.3F %.3F cm', $modulo, -$modulo, $x, $this->h - $y));

        // Uma única operação de preenchimento mantém os módulos alinhados, sem contornos.
        $retangulos = [];
        for ($linha = 0; $linha < $total; $linha++) {
            for ($coluna = 0; $coluna < $total; $coluna++) {
                if ($matriz->check($coluna, $linha)) {
                    $retangulos[] = "$coluna $linha 1 1 re";
                }
            }
        }
        $this->_out(implode("\n", $retangulos) . "\nf Q");
    }
}
