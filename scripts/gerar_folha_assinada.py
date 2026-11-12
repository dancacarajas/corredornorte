#!/usr/bin/env python3
"""Aplica a assinatura, a faixa de validação e o selo PAdES à folha."""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import sys
from datetime import datetime, timezone
from io import BytesIO
from pathlib import Path
from tempfile import NamedTemporaryFile
from urllib.parse import urlsplit

from PIL import Image
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import signers
from pyhanko.sign.fields import MDPPerm, SigSeedSubFilter
from pypdf import PdfReader, PdfWriter
from reportlab.graphics import renderPDF
from reportlab.graphics.barcode.qr import QrCodeWidget
from reportlab.graphics.shapes import Drawing
from reportlab.lib.colors import HexColor
from reportlab.lib.pagesizes import letter
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase.pdfmetrics import registerFont, stringWidth
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas

from gerar_folha_pdf import EMPLOYEE_SIGNATURE_X, LOGO, SIGNATURE_LINE_WIDTH, SIGNATURE_LINE_Y, SUBJECT

BRAND_SOFT = HexColor("#FFF1E6")
BRAND_TEXT = HexColor("#823D0D")
INK = HexColor("#111111")
MUTED = HexColor("#525B69")
BORDER = HexColor("#E5E7EB")
SURFACE = HexColor("#FFFFFF")
SIGNATURE_INK = HexColor("#18365C")
MARGIN = 24
SIGNATURE_CARD_WIDTH = 236
SIGNATURE_CARD_HEIGHT = 46
SIGNATURE_FIELD = "SeloArcadis"
SIGNATURE_FONT = Path(__file__).resolve().parent / "assets" / "fonts" / "Allura-Regular.ttf"


def load_signer() -> signers.SimpleSigner:
    path = os.environ.get("ARCADIS_SEAL_P12_PATH", "")
    password = os.environ.get("ARCADIS_SEAL_P12_PASSWORD", "")
    if not path or not password or not Path(path).is_file():
        raise ValueError("Configure o certificado PKCS#12 e a senha do selo Arcadis.")

    signer = signers.SimpleSigner.load_pkcs12(path, passphrase=password.encode("utf-8"))
    if signer is None:
        raise ValueError("O certificado ou a senha do selo Arcadis não pôde ser validado.")

    certificate = signer.signing_cert
    validity = certificate["tbs_certificate"]["validity"]
    if not validity["not_before"].native <= datetime.now(timezone.utc) <= validity["not_after"].native:
        raise ValueError("O certificado do selo Arcadis está fora da validade.")
    key_usage = certificate.key_usage_value
    if key_usage is not None and not key_usage.native.intersection({"digital_signature", "non_repudiation"}):
        raise ValueError("O certificado do selo Arcadis não permite assinatura digital.")
    return signer


def value(audit: dict, name: str) -> str:
    return str(audit.get(name) or "").strip()


def signature_time(audit: dict) -> str:
    timestamp = value(audit, "assinado_em")
    try:
        return datetime.fromisoformat(timestamp).strftime("%d/%m/%Y %H:%M")
    except ValueError:
        return timestamp


def verification_url(audit: dict) -> str:
    url = value(audit, "verificar_url")
    parsed = urlsplit(url)
    if parsed.scheme not in {"http", "https"} or not parsed.hostname or parsed.username:
        return ""
    return url


def fit_seal_text(text: str, font: str, size: float, minimum: float, width: float) -> tuple[str, float]:
    measured = stringWidth(text, font, size)
    if measured <= width:
        return text, size
    size = max(minimum, size * width / measured)
    if stringWidth(text, font, size) <= width:
        return text, size
    while text and stringWidth(text + "...", font, size) > width:
        text = text[:-1]
    return text.rstrip() + "...", size


def draw_seal_icon(pdf: canvas.Canvas, x: float, y: float, size: float) -> None:
    with Image.open(LOGO) as source:
        logo = source.convert("RGBA")
        left, top, right, bottom = logo.getchannel("A").getbbox()
        symbol = logo.crop((left, top, left + round((right - left) * .18), bottom))
    pdf.drawImage(ImageReader(symbol), x, y, width=size, height=size, preserveAspectRatio=True, mask="auto")


def make_signature_mark(audit: dict, url: str) -> PdfReader:
    stream = BytesIO()
    pdf = canvas.Canvas(stream, pagesize=letter)
    width, height = SIGNATURE_CARD_WIDTH, SIGNATURE_CARD_HEIGHT
    x = EMPLOYEE_SIGNATURE_X + (SIGNATURE_LINE_WIDTH - width) / 2
    y = SIGNATURE_LINE_Y + 2

    pdf.setFillColor(SURFACE)
    pdf.setStrokeColor(BORDER)
    pdf.setLineWidth(.65)
    pdf.roundRect(x, y, width, height, 4, fill=1, stroke=1)
    badge_w = 55
    pdf.setFillColor(BRAND_SOFT)
    pdf.roundRect(x + width - badge_w - .5, y + height - 12, badge_w, 11.5, 3, fill=1, stroke=0)
    pdf.setFillColor(BRAND_TEXT)
    pdf.setFont("Helvetica-Bold", 6.3)
    pdf.drawCentredString(x + width - badge_w / 2, y + height - 8.1, "SIGNATÁRIO")

    if LOGO.is_file():
        draw_seal_icon(pdf, x + 9, y + 11, 24)
    text_x = x + 42
    text_width = width - (text_x - x) - 7
    name = value(audit, "nome")
    handwriting = "Times-Italic"
    if SIGNATURE_FONT.is_file():
        registerFont(TTFont("Allura", str(SIGNATURE_FONT)))
        handwriting = "Allura"
    signature, signature_size = fit_seal_text(name, handwriting, 18, 11, text_width - badge_w - 3)
    pdf.setFillColor(SIGNATURE_INK)
    pdf.setFont(handwriting, signature_size)
    pdf.drawString(text_x, y + 30.7, signature)

    pdf.setFillColor(INK)
    pdf.setFont("Helvetica", 7.2)
    pdf.drawString(text_x, y + 23.0, "Assinado eletronicamente por")
    printed_name, printed_size = fit_seal_text(name, "Helvetica-Bold", 8.3, 6.6, text_width)
    pdf.setFont("Helvetica-Bold", printed_size)
    pdf.drawString(text_x, y + 15.6, printed_name)
    pdf.setFont("Helvetica", 7.0)
    pdf.drawString(text_x, y + 8.1, f"Data: {signature_time(audit)}")
    pdf.setFillColor(MUTED)
    code, code_size = fit_seal_text("#" + value(audit, "codigo"), "Helvetica", 5.4, 5, text_width)
    pdf.setFont("Helvetica", code_size)
    pdf.drawString(text_x, y + 2.5, code)

    pdf.linkURL(url, (x, y, x + width, y + height), relative=0)
    draw_validation_footer(pdf, audit, url)
    pdf.save()
    return PdfReader(stream)


def draw_validation_footer(pdf: canvas.Canvas, audit: dict, url: str) -> None:
    x, y = MARGIN, 20
    width = letter[0] - 2 * MARGIN
    height = 44
    pdf.setFillColor(SURFACE)
    pdf.setStrokeColor(BORDER)
    pdf.setLineWidth(.65)
    pdf.roundRect(x, y, width, height, 6, fill=1, stroke=1)
    if LOGO.is_file():
        draw_seal_icon(pdf, x + 7, y + 8, 28)

    divider = x + 42
    pdf.line(divider, y + 5, divider, y + height - 5)
    text_x = divider + 7
    qr_size = 35
    qr_x = x + width - qr_size - 5
    pdf.setFillColor(INK)
    pdf.setFont("Helvetica", 8.2)
    pdf.drawString(text_x, y + 31, "Assinado com Assinatura Eletrônica")
    for label, content, baseline in (
        ("Hash SHA256 do original: ", value(audit, "sha256"), y + 20),
        ("Link de validação: ", url, y + 9),
    ):
        pdf.setFont("Helvetica", 8)
        pdf.drawString(text_x, baseline, label)
        content_x = text_x + stringWidth(label, "Helvetica", 8)
        visible, size = fit_seal_text(content, "Helvetica-Bold", 8, 6.5, qr_x - content_x - 14)
        pdf.setFont("Helvetica-Bold", size)
        pdf.drawString(content_x, baseline, visible)
        if content == url:
            pdf.linkURL(url, (text_x, baseline - 2, content_x + stringWidth(visible, "Helvetica-Bold", size), baseline + 8),
                        relative=0)

    pdf.saveState()
    pdf.translate(qr_x - 5, y + height - 6)
    pdf.rotate(-90)
    pdf.setFont("Helvetica", 6.8)
    pdf.drawString(0, 0, "Validador")
    pdf.restoreState()
    qr = QrCodeWidget(url, barWidth=qr_size, barHeight=qr_size)
    drawing = Drawing(qr_size, qr_size)
    drawing.add(qr)
    qr_y = y + (height - qr_size) / 2
    renderPDF.draw(drawing, pdf, qr_x, qr_y)
    pdf.linkURL(url, (qr_x, qr_y, qr_x + qr_size, qr_y + qr_size), relative=0)


def build(original: Path, out: Path, audit: dict) -> Path:
    signer = load_signer()
    if original.resolve() == out.resolve():
        raise ValueError("O PDF de origem e o destino devem ser arquivos diferentes.")
    original_bytes = original.read_bytes()
    digest = hashlib.sha256(original_bytes).hexdigest()
    if not hmac.compare_digest(digest, value(audit, "sha256")):
        raise ValueError("O PDF original não corresponde ao hash do registro de assinatura.")

    url = verification_url(audit)
    if not url:
        raise ValueError("O link de validação do documento está indisponível.")
    reader = PdfReader(BytesIO(original_bytes))
    if len(reader.pages) != 1:
        raise ValueError("Gere o cartão de ponto em uma página para assinar.")
    page = reader.pages[0]
    standard_box = (0, 0, *letter)
    has_signature_line = (
        reader.metadata is not None
        and reader.metadata.subject in (
            SUBJECT,
            "Arcadis RH | folha de ponto retrato v2",
            "Arcadis RH | folha de ponto retrato v1",
        )
        and page.rotation == 0
        and tuple(page.mediabox) == tuple(page.cropbox) == standard_box
    )
    if not has_signature_line:
        raise ValueError("Gere o cartão de ponto no modelo em retrato para assinar.")
    writer = PdfWriter()
    page_with_signature = writer.add_page(page)
    mark = make_signature_mark(audit, url)
    page_with_signature.merge_page(mark.pages[0])

    unsigned = BytesIO()
    writer.write(unsigned)
    unsigned.seek(0)
    metadata = signers.PdfSignatureMetadata(
        field_name=SIGNATURE_FIELD,
        md_algorithm="sha256",
        reason="Selo institucional da folha e do registro de assinatura eletrônica",
        subfilter=SigSeedSubFilter.PADES,
        certify=True,
        docmdp_permissions=MDPPerm.NO_CHANGES,
    )
    out.parent.mkdir(parents=True, exist_ok=True)
    temporary = None
    try:
        with NamedTemporaryFile(dir=out.parent, prefix=".selo-", suffix=".pdf", delete=False) as stream:
            temporary = Path(stream.name)
            signers.sign_pdf(IncrementalPdfFileWriter(unsigned), metadata, signer=signer, output=stream)
        temporary.replace(out)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)
    return out


def main() -> int:
    try:
        if sys.argv[1:] == ["--check"]:
            load_signer()
            print("Certificado do selo disponível.")
            return 0
        if len(sys.argv) != 4:
            print("uso: gerar_folha_assinada.py <original.pdf> <saida.pdf> <audit.json>", file=sys.stderr)
            return 2
        original, out, evidence = map(Path, sys.argv[1:])
        audit = json.loads(evidence.read_text(encoding="utf-8-sig"))
        print(build(original, out, audit))
        return 0
    except (OSError, ValueError, RuntimeError) as error:
        print(error, file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
