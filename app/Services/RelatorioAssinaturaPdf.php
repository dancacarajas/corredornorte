<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Reusa o carimbo de nome/data/código da folha na área de assinatura do formulário. */
final class RelatorioAssinaturaPdf
{
    /** Área em branco imediatamente acima da linha "Colaborador Arcadis" no PDF R06 Letter. */
    private const SIGN_X = 231.0;
    private const SIGN_Y = 671.0;
    private const SIGN_WIDTH = 134.0;
    private const SIGN_HEIGHT = 39.0;

    /** @param array{nome:string,assinado_em:string,sha256:string,codigo:string,verificar_url:string} $auditoria */
    public function carimbar(string $original, string $saida, array $auditoria): void
    {
        $hash = is_file($original) ? hash_file('sha256', $original) : false;
        if ($hash === false || !hash_equals($hash, $auditoria['sha256'])) {
            throw new RuntimeException('O PDF original não corresponde ao hash do registro de assinatura.');
        }
        if ($original === $saida) {
            throw new RuntimeException('O PDF original e o assinado devem ser arquivos diferentes.');
        }
        $url = trim($auditoria['verificar_url']);
        $partes = parse_url($url);
        if (!in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true)
            || empty($partes['host']) || isset($partes['user'])) {
            throw new RuntimeException('O link de validação do documento está indisponível.');
        }

        $pdf = new FolhaAssinaturaCanvas('P', 'pt', 'Letter');
        $pdf->SetAutoPageBreak(false);
        if ($pdf->setSourceFile($original) !== 1) {
            throw new RuntimeException('O relatório precisa ocupar uma página do modelo para assinatura.');
        }
        $pagina = $pdf->importPage(1);
        $tamanho = $pdf->getTemplateSize($pagina);
        $largura = (float) $tamanho['width'];
        $altura = (float) $tamanho['height'];
        if (abs($largura - 612.0) > 1 || abs($altura - 792.0) > 1) {
            throw new RuntimeException('Gere o relatório no modelo R06 em retrato antes de assinar.');
        }

        $pdf->SetTitle('Relatório de Atividades | Arcadis RH', true);
        $pdf->SetAuthor('Arcadis RH');
        $pdf->AddPage('P', [$largura, $altura]);
        $pdf->useTemplate($pagina);
        $this->desenharAssinatura($pdf, $auditoria, $url, $largura, $altura);

        $dir = dirname($saida);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta do PDF assinado.');
        }
        $pdf->Output('F', $saida);
        if (!is_file($saida) || filesize($saida) < 1000) {
            throw new RuntimeException('Falha ao gravar o PDF assinado.');
        }
    }

    private function desenharAssinatura(
        FolhaAssinaturaCanvas $pdf,
        array $auditoria,
        string $url,
        float $largura,
        float $altura
    ): void {
        $x = self::SIGN_X;
        $y = self::SIGN_Y;
        $w = self::SIGN_WIDTH;
        $nome = $this->txt(trim((string) $auditoria['nome']));
        $pastaFonte = dirname(__DIR__) . '/Fonts';
        $fonte = 'Times';
        $estilo = 'I';
        if (is_readable($pastaFonte . '/Allura-Regular.php') && is_readable($pastaFonte . '/Allura-Regular.z')) {
            $pdf->AddFont('Allura', '', 'Allura-Regular.php', $pastaFonte);
            $fonte = 'Allura';
            $estilo = '';
        }

        $pdf->SetTextColor(24, 54, 92);
        $assinatura = $this->ajustarTexto($pdf, $nome, $fonte, $estilo, 15, 9, $w);
        $pdf->Text($x, $y + 13, $assinatura);
        $pdf->SetTextColor(17, 17, 17);
        $pdf->SetFont('Helvetica', '', 5.1);
        $pdf->Text($x, $y + 20, $this->txt('Assinado eletronicamente por'));
        $nomeImpresso = $this->ajustarTexto($pdf, $nome, 'Helvetica', 'B', 6, 4.8, $w);
        $pdf->Text($x, $y + 27, $nomeImpresso);
        $data = (new \DateTimeImmutable((string) $auditoria['assinado_em']))->format('d/m/Y H:i');
        $pdf->SetFont('Helvetica', '', 5.0);
        $pdf->Text($x, $y + 33, 'Data: ' . $data);
        $pdf->SetTextColor(82, 91, 105);
        $pdf->SetFont('Helvetica', '', 4.5);
        $pdf->Text($x, $y + 38, '#' . $auditoria['codigo']);
        $pdf->Link($x, $y, $w, self::SIGN_HEIGHT, $url);
    }

    private function ajustarTexto(
        FolhaAssinaturaCanvas $pdf,
        string $texto,
        string $fonte,
        string $estilo,
        float $tamanho,
        float $minimo,
        float $largura
    ): string {
        $pdf->SetFont($fonte, $estilo, $tamanho);
        $medido = $pdf->GetStringWidth($texto);
        if ($medido <= $largura) {
            return $texto;
        }
        $pdf->SetFontSize(max($minimo, $tamanho * $largura / $medido));
        if ($pdf->GetStringWidth($texto) <= $largura) {
            return $texto;
        }
        while ($texto !== '' && $pdf->GetStringWidth($texto . '...') > $largura) {
            $texto = substr($texto, 0, -1);
        }
        return rtrim($texto) . '...';
    }

    private function txt(string $valor): string
    {
        $convertido = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $valor);
        return $convertido !== false ? $convertido : $valor;
    }
}
