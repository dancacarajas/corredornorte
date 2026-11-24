<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

/** Preenche o formulário COR-JUR-FM-005_Rev.0 na geometria original. */
final class EntregaEquipamentoPdfRenderer
{
    public const DOC_CODE = 'COR-JUR-FM-005_Rev.0';
    public const DOC_REV_DATE = '18/09/2024';

    /**
     * @param array<string,mixed> $entrega
     * @param list<array<string,mixed>> $itens
     */
    public function render(array $entrega, array $itens, string $outPath): void
    {
        $dir = dirname($outPath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta do PDF.');
        }

        $pdf = new EntregaEquipamentoFormulario();
        $pdf->pagina(1);
        $this->identificarColaborador($pdf, $entrega);
        $pdf->pagina(2);
        $pdf->pagina(3);
        $local = trim((string) ($entrega['local_termo'] ?? '')) ?: 'Parauapebas-PA';
        $pdf->campo(49.65, 176.075, 496, $local . ', ' . $this->dataPorExtenso($entrega['data_termo'] ?? null) . '.', 11.009);
        $pdf->campo(49.65, 305.95, 496, (string) ($entrega['colab_nome'] ?? ''), 11.011, 'B');

        $entrega['gestor_cpf'] = $this->cpf((string) ($entrega['gestor_cpf'] ?? ''));
        EntregaEquipamentoAnexo::render($pdf, $entrega, $itens);
        EntregaEquipamentoDevolucao::render($pdf, $entrega);
        $pdf->numerar();
        $pdf->Output('F', $outPath);
    }

    private function identificarColaborador(EntregaEquipamentoFormulario $pdf, array $e): void
    {
        $pdf->campo(271.25, 203.875, 277, (string) ($e['colab_nome'] ?? ''), 11.011, 'B');
        $pdf->campo(80.57, 228.70, 218, $this->cpf((string) ($e['colab_cpf'] ?? '')), 9.986);
        $pdf->campo(327.18, 228.70, 221, (string) ($e['colab_rg'] ?? ''), 9.988);
        $pdf->campo(181.1, 246.45, 117, (string) ($e['colab_razao_social'] ?? ''));
        $pdf->campo(400.22, 246.45, 148, (string) ($e['colab_cnpj'] ?? ''));
        $pdf->campo(156.67, 274.85, 392, (string) ($e['colab_endereco'] ?? ''), 9.993);
        $pdf->campo(87.27, 297.30, 212, (string) ($e['colab_bairro'] ?? ''));
        $pdf->campo(355.22, 297.40, 193, (string) ($e['colab_municipio'] ?? ''), 11.017);
        $pdf->campo(150.68, 317.025, 148, (string) ($e['colab_telefone'] ?? ''), 10.125, '', 'L', 'Calibri');
        $pdf->campo(342.78, 317.025, 206, (string) ($e['colab_email'] ?? ''), 9.922, '', 'L', 'Calibri');
    }

    private function cpf(string $valor): string
    {
        return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/D', '$1.$2.$3-$4', $valor) ?? $valor;
    }

    private function dataPorExtenso(?string $valor): string
    {
        $data = $valor ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : null;
        $data = $data ?: new DateTimeImmutable('today');
        $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return $data->format('d') . ' de ' . $meses[(int) $data->format('n') - 1] . ' de ' . $data->format('Y');
    }
}
