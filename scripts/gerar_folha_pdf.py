#!/usr/bin/env python3
"""Emite o cartão de ponto Arcadis em Letter retrato, pronto para impressão."""
from __future__ import annotations

import json
import sys
from pathlib import Path

from PIL import Image
from reportlab.lib.colors import HexColor
from reportlab.lib.pagesizes import letter
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase.pdfmetrics import stringWidth
from reportlab.pdfgen import canvas

PAGE_W, PAGE_H = letter
LEFT, RIGHT = 24, PAGE_W - 24
WIDTH = RIGHT - LEFT
LAYOUT_VERSION = 3
SUBJECT = f"Arcadis RH | folha de ponto retrato v{LAYOUT_VERSION}"
LOGO = Path(__file__).resolve().parents[1] / "public" / "assets" / "img" / "logo.png"
SIGNATURE_LINE_Y = 109
EMPLOYEE_SIGNATURE_X = LEFT + 9
COMPANY_SIGNATURE_X = PAGE_W / 2 + 13
SIGNATURE_LINE_WIDTH = WIDTH / 2 - 22

INK = HexColor("#222427")
SECONDARY = HexColor("#565B62")
BRAND = HexColor("#C64D13")
ORANGE = HexColor("#E9601B")
LINE = HexColor("#C8CBD0")
HEADER = HexColor("#EEF0F2")
STRIPE = HexColor("#F8F9FA")
EXCEPTION = HexColor("#FFF3EA")
WHITE = HexColor("#FFFFFF")

COLUMNS = (
    (("Dia",), 53),
    (("Ent. 1",), 37),
    (("Saí. 1",), 37),
    (("Ent. 2",), 37),
    (("Saí. 2",), 37),
    (("Total", "normal"), 35),
    (("Total", "trabalhado"), 40),
    (("Adicional", "noturno"), 37),
    (("Horas", "previstas"), 41),
    (("Dia", "falta"), 28),
    (("Horas", "falta"), 35),
    (("Horas", "atraso"), 35),
    (("Desconto",), 38),
    (("Atestado",), 36),
    (("Horas", "extras"), 38),
)
MARCACOES = ("ent1", "sai1", "ent2", "sai2")
HORARIOS = ("entrada", "intervalo_inicio", "intervalo_fim", "saida")
ORIGENS = {"manual": "M", "relogio": "R", "importado": "I", "previsto": "P"}
DIAS_POR_PAGINA = 40


def text(
    pdf: canvas.Canvas,
    value: object,
    x: float,
    y: float,
    *,
    size: float = 8,
    font: str = "Helvetica",
    color=INK,
    align: str = "left",
    width: float | None = None,
    min_size: float = 6.2,
) -> None:
    label = " ".join(str(value if value is not None else "").split())
    if width is not None:
        natural = stringWidth(label, font, size)
        if natural > width:
            size = max(min_size, size * width / natural)
            if stringWidth(label, font, size) > width:
                while label and stringWidth(label + "…", font, size) > width:
                    label = label[:-1]
                label = label.rstrip() + "…"
    pdf.setFillColor(color)
    pdf.setFont(font, size)
    if align == "center":
        pdf.drawCentredString(x, y, label)
    elif align == "right":
        pdf.drawRightString(x, y, label)
    else:
        pdf.drawString(x, y, label)


def horas(minutes: int | None, *, show_zero: bool = False) -> str:
    if minutes is None or (not minutes and not show_zero):
        return ""
    minutes = int(minutes)
    sign = "-" if minutes < 0 else ""
    hours, remainder = divmod(abs(minutes), 60)
    return f"{sign}{hours:02d}:{remainder:02d}"


def hm(value: str | None) -> str:
    return str(value)[:5] if value else ""


def line(pdf: canvas.Canvas, x1: float, y1: float, x2: float, y2: float, color=LINE, weight: float = .4) -> None:
    pdf.setStrokeColor(color)
    pdf.setLineWidth(weight)
    pdf.line(x1, y1, x2, y2)


def header(pdf: canvas.Canvas, data: dict, page: int, pages: int, logo: ImageReader) -> None:
    logo_w = 94
    image_w, image_h = logo.getSize()
    pdf.drawImage(logo, LEFT, 744, width=logo_w, height=logo_w * image_h / image_w, mask="auto")
    text(pdf, "Cartão de Ponto", PAGE_W / 2, 751, size=21, font="Helvetica-Bold", align="center")
    period = str(data.get("periodo", "")).replace(" A ", " ATÉ ")
    text(pdf, f"DE {period}", PAGE_W / 2, 731, size=10, font="Helvetica-Bold", color=BRAND, align="center")
    text(pdf, f"Página {page:02d} de {pages:02d}", RIGHT, 749, size=7.4, align="right", color=SECONDARY)
    text(pdf, f"Emitido em {data.get('emitido', '')}", RIGHT, 735, size=7.4, align="right", color=SECONDARY)
    line(pdf, LEFT, 720, RIGHT, 720)
    line(pdf, LEFT, 720, LEFT + 36, 720, ORANGE, 1.65)


def field(pdf: canvas.Canvas, label: str, value: object, x: float, y: float, width: float) -> None:
    text(pdf, label, x, y, size=6.8, font="Helvetica-Bold", color=SECONDARY)
    text(pdf, value or "—", x, y - 12, size=8.4, width=width)


def identification(pdf: canvas.Canvas, employee: dict) -> None:
    x2 = LEFT + 211
    field(pdf, "EMPRESA", employee.get("empresa"), LEFT, 702, 201)
    field(pdf, "MATRÍCULA", employee.get("matricula"), x2, 702, 99)
    field(pdf, "FUNCIONÁRIO", employee.get("nome"), LEFT, 676, 201)
    field(pdf, "CPF", employee.get("cpf"), x2, 676, 99)
    field(pdf, "CARGO", employee.get("cargo"), LEFT, 650, 201)
    local = " / ".join(filter(None, (employee.get("municipio"), employee.get("uf"))))
    field(pdf, "LOCAL", local, x2, 650, 99)
    lotacao = " / ".join(str(employee.get(key) or "—") for key in ("contrato_codigo", "cc_codigo", "os_codigo"))
    field(pdf, "CONTRATO / CENTRO DE CUSTO / ORDEM DE SERVIÇO", lotacao, LEFT, 624, 310)


def schedule(pdf: canvas.Canvas, weekly: list[dict]) -> None:
    x, top, width = 352, 707, RIGHT - 352
    title_h, heading_h, row_h = 15, 12, 11
    day_w = 34
    time_w = (width - day_w) / 4
    rows = {row["dia"]: row for row in weekly}
    bottom = top - title_h - heading_h - 7 * row_h

    pdf.setFillColor(HEADER)
    pdf.rect(x, top - title_h - heading_h, width, title_h + heading_h, fill=1, stroke=0)
    text(pdf, "Horário de trabalho", x + width / 2, top - 10.6, size=8.4, font="Helvetica-Bold", align="center")
    line(pdf, x, top - title_h, RIGHT, top - title_h)
    y = top - title_h - heading_h
    text(pdf, "Dia", x + day_w / 2, y + 3.6, size=7, font="Helvetica-Bold", align="center")
    for index, label in enumerate(("Ent. 1", "Saí. 1", "Ent. 2", "Saí. 2")):
        text(pdf, label, x + day_w + (index + .5) * time_w, y + 3.6, size=7, font="Helvetica-Bold", align="center")
    for index, day in enumerate(("SEG", "TER", "QUA", "QUI", "SEX", "SÁB", "DOM")):
        row = rows.get(day) or rows.get(day.replace("Á", "A")) or {}
        lower = y - row_h
        if index % 2:
            pdf.setFillColor(STRIPE)
            pdf.rect(x, lower, width, row_h, fill=1, stroke=0)
        line(pdf, x, y, RIGHT, y)
        text(pdf, day, x + day_w / 2, lower + 3.3, size=7, font="Helvetica-Bold", align="center")
        kind = row.get("tipo")
        if kind == "trabalho":
            for time_index, key in enumerate(HORARIOS):
                text(pdf, hm(row.get(key)) or "—", x + day_w + (time_index + .5) * time_w, lower + 3.3,
                     size=7.2, align="center")
        else:
            label = {"folga": "Folga", "variavel": "Variável"}.get(kind, "—")
            text(pdf, label, x + day_w + 2 * time_w, lower + 3.3, size=7.2, align="center", color=SECONDARY)
        if kind == "trabalho":
            for mark in range(1, 4):
                divider = x + day_w + mark * time_w
                line(pdf, divider, y, divider, lower)
        y = lower

    for mark in range(1, 4):
        divider = x + day_w + mark * time_w
        line(pdf, divider, top - title_h, divider, top - title_h - heading_h)
    line(pdf, x + day_w, top - title_h, x + day_w, bottom)
    pdf.setStrokeColor(LINE)
    pdf.setLineWidth(.5)
    pdf.rect(x, bottom, width, top - bottom, fill=0, stroke=1)


def day_values(day: dict) -> list[str]:
    kind = day.get("tipo_dia")
    planned_off = {"folga": "Folga", "folga_escala": "Folga", "feriado": "Feriado"}.get(kind)
    punches = []
    has_punch = any(day.get(key) for key in MARCACOES)
    origins = day.get("origens") or {}
    for key in MARCACOES:
        time = hm(day.get(key))
        mark = ORIGENS.get(origins.get(key, day.get("origem")))
        punches.append(f"{time} ({mark})" if time and mark else time)
    if planned_off and not has_punch:
        punches = [planned_off] * 4
    elif day.get("dia_falta") and not has_punch:
        punches[0] = "Falta"

    atestado = horas(day.get("atestado_min")) or ("Sim" if day.get("atestado") else "")
    return [
        str(day.get("data_fmt", "")), *punches,
        horas(day.get("total_normal_min"), show_zero=has_punch),
        horas(day.get("total_trab_min"), show_zero=has_punch),
        horas(day.get("adn_min"), show_zero=has_punch),
        horas(day.get("horas_previstas_min")),
        str(day.get("dia_falta") or ""),
        horas(day.get("horas_falta_min")),
        horas(day.get("atraso_bruto_min")),
        horas(day.get("desconto_min")),
        atestado,
        horas(day.get("horas_extras_min")),
    ]


def total_values(totals: dict) -> list[str]:
    return [
        "TOTAIS", "", "", "", "",
        horas(totals.get("norm"), show_zero=True),
        horas(totals.get("trab"), show_zero=True),
        horas(totals.get("adn"), show_zero=True),
        horas(totals.get("prev"), show_zero=True),
        str(totals.get("dias_falta", "")),
        horas(totals.get("falta"), show_zero=True),
        horas(totals.get("atraso"), show_zero=True),
        horas(totals.get("desconto"), show_zero=True),
        horas(totals.get("atestado")),
        horas(totals.get("he"), show_zero=True),
    ]


def table(pdf: canvas.Canvas, days: list[dict], totals: dict | None) -> float:
    top, heading_h, total_h = 593, 25, 17
    # O período pode atravessar competências sem ocupar a área da assinatura.
    row_h = 12.2 * min(1, 31 / max(1, len(days)))
    y = top - heading_h
    bottom = y - row_h * len(days) - (total_h if totals is not None else 0)
    pdf.setFillColor(HEADER)
    pdf.rect(LEFT, y, WIDTH, heading_h, fill=1, stroke=0)

    x = LEFT
    for labels, col_w in COLUMNS:
        for index, label in enumerate(labels):
            baseline = y + (10 if len(labels) == 1 else 15 - 8 * index)
            text(pdf, label, x + col_w / 2, baseline, size=6.9, font="Helvetica-Bold", align="center")
        x += col_w

    def row(values: list[str], y: float, height: float, background, bold: bool = False) -> None:
        pdf.setFillColor(background)
        pdf.rect(LEFT, y - height, WIDTH, height, fill=1, stroke=0)
        line(pdf, LEFT, y, RIGHT, y, LINE, .55 if bold else .38)
        x = LEFT
        for index, ((_, col_w), value) in enumerate(zip(COLUMNS, values)):
            text(pdf, value, x + (5 if index == 0 else col_w / 2), y - height / 2 - 2.4, size=7.05,
                 font="Helvetica-Bold" if bold else "Helvetica", align="left" if index == 0 else "center",
                 width=col_w - (8 if index == 0 else 4))
            x += col_w

    for index, day in enumerate(days):
        background = EXCEPTION if day.get("tipo_dia") == "feriado" else STRIPE if index % 2 else WHITE
        row(day_values(day), y, row_h, background)
        y -= row_h
    if totals is not None:
        row(total_values(totals), y, total_h, HEADER, bold=True)

    x = LEFT
    for _, col_w in COLUMNS:
        line(pdf, x, top, x, bottom)
        x += col_w
    line(pdf, RIGHT, top, RIGHT, bottom)
    line(pdf, LEFT, bottom, RIGHT, bottom)
    line(pdf, LEFT, top, RIGHT, top, ORANGE, 1.25)
    return bottom


def footer(pdf: canvas.Canvas, data: dict, bottom: float, last: bool) -> None:
    text(pdf, f"Verificação  {data.get('codigo', '')}", RIGHT, bottom - 12, size=7.1, color=SECONDARY, align="right")
    if last:
        employee = data["espelho"]
        for x, label in ((EMPLOYEE_SIGNATURE_X, employee.get("nome")), (COMPANY_SIGNATURE_X, employee.get("empresa"))):
            line(pdf, x, SIGNATURE_LINE_Y, x + SIGNATURE_LINE_WIDTH, SIGNATURE_LINE_Y, INK, .6)
            text(pdf, label, x + SIGNATURE_LINE_WIDTH / 2, SIGNATURE_LINE_Y - 13, size=7.8, font="Helvetica-Bold",
                 align="center", width=SIGNATURE_LINE_WIDTH)


def draw(pdf: canvas.Canvas, data: dict) -> None:
    with Image.open(LOGO) as source:
        logo_image = source.convert("RGBA")
        logo = ImageReader(logo_image.crop(logo_image.getchannel("A").getbbox()))
    days = data["dias"]
    pages = [days[start:start + DIAS_POR_PAGINA] for start in range(0, len(days), DIAS_POR_PAGINA)] or [[]]
    for index, page_days in enumerate(pages, start=1):
        last = index == len(pages)
        header(pdf, data, index, len(pages), logo)
        identification(pdf, data["espelho"])
        schedule(pdf, data["espelho"].get("jornada_semanal") or [])
        bottom = table(pdf, page_days, (data.get("totais") or {}) if last else None)
        footer(pdf, data, bottom, last)
        pdf.showPage()


def main() -> int:
    payload = json.loads(Path(sys.argv[1]).read_text(encoding="utf-8-sig"))
    out = Path(sys.argv[2])
    out.parent.mkdir(parents=True, exist_ok=True)
    pdf = canvas.Canvas(str(out), pagesize=letter, pageCompression=1)
    pdf.setTitle("Cartão de Ponto | Arcadis RH")
    pdf.setAuthor("Arcadis RH")
    pdf.setSubject(SUBJECT)
    draw(pdf, payload)
    pdf.save()
    print(out)
    print(f"layout-pdf={LAYOUT_VERSION}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

