<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aplica no RA o mesmo selo de signatário e rodapé de validação do cartão de ponto,
 * posicionados na área livre do formulário R06 (abaixo das notas, acima/abaixo da linha).
 */
final class RelatorioAssinaturaPdf
{
    /**
     * Cartão do signatário: abaixo das notas (1)(2)(3), na faixa da assinatura.
     * Nunca sobrepõe o texto das notas — a faixa é apagada e o selo redesenhado.
     */
    private const CARD_X = 188.0;
    private const CARD_Y = 678.0;
    /** Rodapé colado sob o selo — sem faixa vazia entre assinatura e validação. */
    private const FOOTER_Y = 725.0;
    /** Apaga da linha após as notas até o início do rodapé (notas terminam ~664). */
    private const LIMPAR_Y = 668.0;
    private const LIMPAR_H = 57.0;

    /** @param array{nome:string,assinado_em:string,sha256:string,codigo:string,verificar_url:string} $auditoria */
    public function carimbar(string $original, string $saida, array $auditoria): void
    {
        (new FolhaAssinaturaPdf())->carimbar(
            $original,
            $saida,
            $auditoria,
            'Relatório de Atividades | Arcadis RH',
            [
                'card_x' => self::CARD_X,
                'card_y' => self::CARD_Y,
                'footer_y' => self::FOOTER_Y,
                'limpar' => ['y' => self::LIMPAR_Y, 'h' => self::LIMPAR_H],
            ]
        );
    }
}
