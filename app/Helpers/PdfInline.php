<?php

declare(strict_types=1);

namespace App\Helpers;

use setasign\Fpdi\Fpdi;
use Throwable;

/** Entrega PDF inline com título UTF-8 correto (evita "CartÃ£o" na aba do browser). */
final class PdfInline
{
    public static function stream(string $arquivo, string $titulo, string $filenameAscii): void
    {
        if (!is_file($arquivo)) {
            http_response_code(404);
            echo 'Arquivo ausente';
            return;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $filenameAscii . '"');
        header('Cache-Control: private, no-store');

        try {
            $pdf = new Fpdi();
            $pdf->SetAutoPageBreak(false);
            $pages = $pdf->setSourceFile($arquivo);
            if ($pages < 1) {
                throw new \RuntimeException('PDF sem páginas.');
            }
            $pdf->SetTitle($titulo, true);
            $pdf->SetAuthor('Arcadis RH');
            for ($i = 1; $i <= $pages; $i++) {
                $tpl = $pdf->importPage($i);
                $size = $pdf->getTemplateSize($tpl);
                $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orient, [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
            $pdf->Output('I', $filenameAscii);
            return;
        } catch (Throwable) {
            // Fallback: arquivo original (pode manter título antigo).
            readfile($arquivo);
        }
    }
}
