#!/usr/bin/env python3
"""Aplica ao relatório o mesmo selo PAdES institucional usado na folha de ponto."""
from __future__ import annotations

import os
import sys
from datetime import datetime, timezone
from pathlib import Path

from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import signers
from pyhanko.sign.fields import MDPPerm, SigSeedSubFilter


def main() -> int:
    if len(sys.argv) != 3:
        print("uso: selar_pdf_relatorio.py <original.pdf> <saida.pdf>", file=sys.stderr)
        return 2
    origem, destino = map(Path, sys.argv[1:])
    certificado = os.environ.get("ARCADIS_SEAL_P12_PATH", "")
    senha = os.environ.get("ARCADIS_SEAL_P12_PASSWORD", "")
    if not certificado or not senha or not Path(certificado).is_file():
        print("Certificado do selo indisponível.", file=sys.stderr)
        return 1
    signer = signers.SimpleSigner.load_pkcs12(certificado, passphrase=senha.encode("utf-8"))
    if signer is None:
        print("Certificado ou senha inválidos.", file=sys.stderr)
        return 1
    validade = signer.signing_cert["tbs_certificate"]["validity"]
    if not validade["not_before"].native <= datetime.now(timezone.utc) <= validade["not_after"].native:
        print("Certificado fora da validade.", file=sys.stderr)
        return 1
    metadata = signers.PdfSignatureMetadata(
        field_name="SeloArcadis",
        md_algorithm="sha256",
        reason="Selo institucional do relatório e do registro de assinatura eletrônica",
        subfilter=SigSeedSubFilter.PADES,
        certify=True,
        docmdp_permissions=MDPPerm.NO_CHANGES,
    )
    with origem.open("rb") as entrada, destino.open("wb") as saida:
        signers.sign_pdf(IncrementalPdfFileWriter(entrada), metadata, signer=signer, output=saida)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, ValueError, RuntimeError) as erro:
        print(erro, file=sys.stderr)
        raise SystemExit(1)
