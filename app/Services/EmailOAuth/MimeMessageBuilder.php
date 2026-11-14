<?php

declare(strict_types=1);

namespace App\Services\EmailOAuth;

/**
 * Monta mensagens RFC 2822 (texto, HTML multipart/alternative e anexo PDF).
 */
final class MimeMessageBuilder
{
    /**
     * @return array{subject_encoded:string,headers_mail:string,body_mail:string,raw:string}
     */
    public static function build(
        string $from,
        string $fromName,
        string $to,
        string $subject,
        string $textBody,
        ?string $attachmentPath = null,
        ?string $htmlBody = null,
        bool $includeMessageId = true
    ): array {
        $fromClean = trim(str_replace(["\r", "\n", "\0"], '', $from));
        $fromNameClean = trim(str_replace(["\r", "\n", "\0"], '', $fromName));
        $toClean = trim(str_replace(["\r", "\n", "\0"], '', $to));
        $subjectClean = str_replace(["\r", "\n", "\0"], ' ', $subject);
        $subjectEnc = '=?UTF-8?B?' . base64_encode($subjectClean) . '?=';
        $fromHeader = sprintf('"%s" <%s>', addslashes($fromNameClean), $fromClean);

        $hasHtml = $htmlBody !== null && trim($htmlBody) !== '';
        $hasAttachment = $attachmentPath !== null && $attachmentPath !== '' && is_file($attachmentPath);

        if ($hasHtml) {
            $altBoundary = 'alt_' . bin2hex(random_bytes(8));
            $contentTypeHeader = 'multipart/alternative; boundary="' . $altBoundary . '"';
            $contentPart = "--{$altBoundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $textBody . "\r\n"
                . "--{$altBoundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody . "\r\n"
                . "--{$altBoundary}--";
        } else {
            $contentTypeHeader = 'text/plain; charset=UTF-8';
            $contentPart = $textBody;
        }

        if ($hasAttachment) {
            $mixBoundary = 'mix_' . bin2hex(random_bytes(8));
            $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $attachmentPath)) ?: 'anexo.pdf';
            $fileData = chunk_split(base64_encode((string) file_get_contents((string) $attachmentPath)));
            $topContentType = 'multipart/mixed; boundary="' . $mixBoundary . '"';
            $bodyMail = "--{$mixBoundary}\r\n"
                . "Content-Type: {$contentTypeHeader}\r\n\r\n"
                . $contentPart . "\r\n"
                . "--{$mixBoundary}\r\n"
                . "Content-Type: application/pdf; name=\"{$filename}\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n"
                . $fileData . "\r\n--{$mixBoundary}--";
        } else {
            $topContentType = $contentTypeHeader;
            $bodyMail = $contentPart;
        }

        $mailHeaders = [
            "From: {$fromHeader}",
            'MIME-Version: 1.0',
            "Content-Type: {$topContentType}",
        ];

        $rawHeaders = [
            "From: {$fromHeader}",
            "To: {$toClean}",
            "Subject: {$subjectEnc}",
            'MIME-Version: 1.0',
        ];
        if ($includeMessageId) {
            $rawHeaders[] = 'Date: ' . date('r');
            $rawHeaders[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@arcadis.local>';
        }
        $rawHeaders[] = "Content-Type: {$topContentType}";

        return [
            'subject_encoded' => $subjectEnc,
            'headers_mail' => implode("\r\n", $mailHeaders),
            'body_mail' => $bodyMail,
            'raw' => implode("\r\n", $rawHeaders) . "\r\n\r\n" . $bodyMail,
        ];
    }
}
