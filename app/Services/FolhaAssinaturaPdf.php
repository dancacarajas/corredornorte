<?php

declare(strict_types=1);

namespace App\Services;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;
use setasign\Fpdi\PdfParser\PdfParser;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;

final class FolhaAssinaturaPdf
{
    private const PAGE_WIDTH = 612.0;
    private const PAGE_HEIGHT = 792.0;
    private const MARGIN = 24.0;
    private const CARD_X = 45.0;
    private const CARD_Y = 635.0;
    private const CARD_WIDTH = 236.0;
    private const CARD_HEIGHT = 46.0;
    private const FOOTER_HEIGHT = 64.0;
    private const SUBJECT_FOLHA_V5 = 'Arcadis RH | folha de ponto retrato v5';

    private float $cardX = self::CARD_X;
    private float $cardY = self::CARD_Y;
    private float $footerY = self::PAGE_HEIGHT - 20 - self::FOOTER_HEIGHT;
    private float $footerHeight = self::FOOTER_HEIGHT;

    /**
     * @param array{nome:string,assinado_em:string,sha256:string,codigo:string,verificar_url:string} $auditoria
     * @param string $titulo Título do PDF (folha ou relatório usam o mesmo layout de selo/rodapé).
     * @param array{
     *   card_x?:float,
     *   card_y?:float,
     *   footer_y?:float,
     *   limpar?:array{y:float,h:float}
     * }|null $layout Posições opcionais (ex.: RA).
     */
    public function carimbar(
        string $original,
        string $saida,
        array $auditoria,
        string $titulo = 'Cartão de Ponto | Arcadis RH',
        ?array $layout = null
    ): void {
        $hash = is_file($original) ? hash_file('sha256', $original) : false;
        if ($hash === false || !hash_equals($hash, $auditoria['sha256'])) {
            throw new RuntimeException('O PDF original não corresponde ao hash do registro de assinatura.');
        }
        if (realpath($original) === realpath($saida)) {
            throw new RuntimeException('O PDF original e o PDF assinado devem ser arquivos diferentes.');
        }

        $url = trim($auditoria['verificar_url']);
        $partes = parse_url($url);
        if (!in_array(strtolower($partes['scheme'] ?? ''), ['http', 'https'], true)
            || empty($partes['host']) || isset($partes['user'])) {
            throw new RuntimeException('O link de validação do documento está indisponível.');
        }

        $folhaV5 = $layout === null && $this->usaFormularioV5($original);
        if ($folhaV5) {
            // A linha de assinatura do formulário está centrada em x=301 pt.
            $layout = ['card_x' => 301 - self::CARD_WIDTH / 2, 'card_y' => 654, 'footer_y' => 733];
        }
        $this->cardX = (float) ($layout['card_x'] ?? self::CARD_X);
        $this->cardY = (float) ($layout['card_y'] ?? self::CARD_Y);
        $this->footerY = (float) ($layout['footer_y'] ?? (self::PAGE_HEIGHT - 20 - self::FOOTER_HEIGHT));
        $this->footerHeight = $folhaV5 ? 54.0 : self::FOOTER_HEIGHT;

        $pdf = new FolhaAssinaturaCanvas('P', 'pt', 'Letter');
        $pdf->SetAutoPageBreak(false);
        if ($pdf->setSourceFile($original) !== 1) {
            throw new RuntimeException('O documento precisa ocupar uma página para assinar.');
        }
        $pagina = $pdf->importPage(1);
        $tamanho = $pdf->getTemplateSize($pagina);
        if (abs($tamanho['width'] - self::PAGE_WIDTH) > 1.0 || abs($tamanho['height'] - self::PAGE_HEIGHT) > 1.0) {
            throw new RuntimeException('Gere o documento no modelo em retrato Letter para assinar.');
        }

        $pdf->SetTitle($titulo, true);
        $pdf->SetAuthor('Arcadis RH');
        $pdf->AddPage();
        $pdf->useTemplate($pagina);
        $limpar = $layout['limpar'] ?? null;
        if (is_array($limpar)) {
            $pdf->SetFillColor(255, 255, 255);
            $pdf->Rect(
                self::MARGIN,
                (float) $limpar['y'],
                self::PAGE_WIDTH - 2 * self::MARGIN,
                (float) $limpar['h'],
                'F'
            );
        }
        $this->desenharSignatario($pdf, $auditoria, $url);
        $this->desenharValidacao($pdf, $auditoria, $url);

        $dir = dirname($saida);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta do PDF assinado.');
        }
        $pdf->Output('F', $saida);
        if (!is_file($saida) || filesize($saida) < 1000) {
            throw new RuntimeException('Falha ao gravar o PDF assinado.');
        }
    }

    private function usaFormularioV5(string $original): bool
    {
        // O PDF arquivado é também a fonte de verdade ao reconstruir vias históricas.
        $parser = new PdfParser(StreamReader::createByFile($original));
        $trailer = $parser->getCrossReference()->getTrailer();
        $info = PdfType::resolve(PdfDictionary::get($trailer, 'Info'), $parser);
        if (!$info instanceof PdfDictionary) {
            return false;
        }
        $subject = PdfType::resolve(PdfDictionary::get($info, 'Subject'), $parser);
        return $subject instanceof PdfString && PdfString::unescape($subject->value) === self::SUBJECT_FOLHA_V5;
    }

    private function desenharSignatario(FolhaAssinaturaCanvas $pdf, array $auditoria, string $url): void
    {
        $x = $this->cardX;
        $y = $this->cardY;
        $largura = self::CARD_WIDTH;
        $altura = self::CARD_HEIGHT;
        $badge = 55.0;
        $textoX = $x + 42;
        $textoLargura = $largura - 49;

        $this->desenharPainel($pdf, $x, $y, $largura, $altura, 4);
        $pdf->SetFillColor(255, 241, 230);
        $pdf->roundedRect($x + $largura - $badge - 0.5, $y + 0.5, $badge, 11.5, 3, 'f');
        $pdf->SetTextColor(130, 61, 13);
        $pdf->SetFont('Helvetica', 'B', 6.3);
        $rotulo = $this->txt('SIGNATÁRIO');
        $pdf->Text($x + $largura - ($badge + $pdf->GetStringWidth($rotulo)) / 2, $y + 8.1, $rotulo);
        $this->desenharIcone($pdf, $x + 9, $y + 11, 24);

        $dir = dirname(__DIR__) . '/Fonts';
        $fonte = 'Times';
        $estilo = 'I';
        if (is_readable($dir . '/Allura-Regular.php') && is_readable($dir . '/Allura-Regular.z')) {
            $pdf->AddFont('Allura', '', 'Allura-Regular.php', $dir);
            $fonte = 'Allura';
            $estilo = '';
        }
        $nome = $this->txt(trim($auditoria['nome']));
        $pdf->SetTextColor(24, 54, 92);
        $assinatura = $this->ajustarTexto($pdf, $nome, $fonte, $estilo, 18, 11, $textoLargura - $badge - 3);
        $pdf->Text($textoX, $y + 15.3, $assinatura);

        $pdf->SetTextColor(17, 17, 17);
        $pdf->SetFont('Helvetica', '', 7.2);
        $pdf->Text($textoX, $y + 23, $this->txt('Assinado eletronicamente por'));
        $nomeImpresso = $this->ajustarTexto($pdf, $nome, 'Helvetica', 'B', 8.3, 6.6, $textoLargura);
        $pdf->Text($textoX, $y + 30.4, $nomeImpresso);
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->Text($textoX, $y + 37.9, 'Data: ' . $this->formatarData($auditoria['assinado_em']));
        $pdf->SetTextColor(82, 91, 105);
        $pdf->SetFont('Helvetica', '', 5.4);
        $pdf->Text($textoX, $y + 43.5, '#' . $auditoria['codigo']);
        $pdf->Link($x, $y, $largura, $altura, $url);
    }

    private function desenharValidacao(FolhaAssinaturaCanvas $pdf, array $auditoria, string $url): void
    {
        $x = self::MARGIN;
        $altura = $this->footerHeight;
        $largura = self::PAGE_WIDTH - 2 * self::MARGIN;
        $y = $this->footerY;
        $qrSize = $altura - 8;
        $qrX = $x + $largura - $qrSize - 5;
        $qrY = $y + ($altura - $qrSize) / 2;
        $divisor = $x + 42;
        $textoX = $divisor + 7;

        $this->desenharPainel($pdf, $x, $y, $largura, $altura, 6);
        $this->desenharIcone($pdf, $x + 7, $y + ($altura - 28) / 2, 28);
        $pdf->Line($divisor, $y + 8, $divisor, $y + $altura - 8);

        $pdf->SetFont('Helvetica', 'B', 7.3);
        $pdf->SetTextColor(82, 91, 105);
        $rotulo = 'Validador';
        $rotuloX = $qrX - 8 - $pdf->GetStringWidth($rotulo);
        $centroY = $y + $altura / 2;
        $pdf->Text($rotuloX, $centroY + 2, $rotulo);

        $pdf->SetTextColor(17, 17, 17);
        $pdf->SetFont('Helvetica', '', 8.2);
        $pdf->Text($textoX, $centroY - 12, $this->txt('Assinado com Assinatura Eletrônica'));
        $this->desenharLinha($pdf, $textoX, $centroY, 'Hash SHA256 do original: ', $auditoria['sha256'], $rotuloX - 12);
        $this->desenharLinha($pdf, $textoX, $centroY + 12, 'Link de validação: ', $url, $rotuloX - 12, $url);

        $this->desenharQr($pdf, $url, $qrX, $qrY, $qrSize);
        $pdf->Link($qrX, $qrY, $qrSize, $qrSize, $url);
    }

    private function desenharPainel(FolhaAssinaturaCanvas $pdf, float $x, float $y, float $largura, float $altura, float $raio): void
    {
        // Retângulo opaco por baixo evita texto do formulário vazando sob o selo.
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x, $y, $largura, $altura, 'F');
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->SetLineWidth(0.65);
        $pdf->roundedRect($x, $y, $largura, $altura, $raio, 'B');
    }

    private function desenharIcone(FolhaAssinaturaCanvas $pdf, float $x, float $y, float $lado): void
    {
        $icone = dirname(__DIR__, 2) . '/public/assets/img/selo-arcadis.png';
        [$largura, $altura] = getimagesize($icone);
        $escala = $lado / max($largura, $altura);
        $largura *= $escala;
        $altura *= $escala;
        $pdf->Image($icone, $x + ($lado - $largura) / 2, $y + ($lado - $altura) / 2, $largura, $altura);
    }

    private function desenharQr(FolhaAssinaturaCanvas $pdf, string $url, float $x, float $y, float $lado): void
    {
        $qr = new QRCode(new QROptions([
            'eccLevel' => QRCode::ECC_M,
            'addQuietzone' => true,
            'quietzoneSize' => 4,
        ]));
        $qr->addByteSegment($url);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->fillQrMatrix($qr->getQRMatrix(), $x, $y, $lado);
    }

    private function desenharLinha(
        FolhaAssinaturaCanvas $pdf,
        float $x,
        float $y,
        string $rotulo,
        string $valor,
        float $limite,
        ?string $link = null
    ): void {
        $rotulo = $this->txt($rotulo);
        $pdf->SetFont('Helvetica', '', 8);
        $pdf->Text($x, $y, $rotulo);
        $valorX = $x + $pdf->GetStringWidth($rotulo);
        $visivel = $this->ajustarTexto($pdf, $this->txt($valor), 'Helvetica', 'B', 8, 6.5, $limite - $valorX);
        $pdf->Text($valorX, $y, $visivel);
        if ($link !== null) {
            $pdf->Link($x, $y - 8, $valorX - $x + $pdf->GetStringWidth($visivel), 10, $link);
        }
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

    private function formatarData(string $iso): string
    {
        try {
            return (new \DateTimeImmutable($iso))->format('d/m/Y H:i');
        } catch (\Exception) {
            return $iso;
        }
    }

    private function txt(string $valor): string
    {
        $convertido = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $valor);
        return $convertido !== false ? $convertido : $valor;
    }
}
