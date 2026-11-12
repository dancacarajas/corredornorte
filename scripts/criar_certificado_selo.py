#!/usr/bin/env python3
"""Gera um certificado institucional local e exporta seu certificado público."""
from __future__ import annotations

import argparse
import getpass
import json
import os
import secrets
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from tempfile import TemporaryDirectory

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.hazmat.primitives.serialization import pkcs12
from cryptography.x509.oid import NameOID


def write_new(path: Path, contents: bytes, mode: int) -> None:
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, mode)
    with os.fdopen(descriptor, "wb") as stream:
        stream.write(contents)


def create_certificate(directory: Path, password: str) -> tuple[Path, Path, str]:
    certificate_file = directory / "arcadis-selo.pem"
    bundle_file = directory / "arcadis-selo.p12"
    if certificate_file.exists() or bundle_file.exists():
        raise FileExistsError("Escolha um diretório sem um certificado de selo existente.")

    private_key = rsa.generate_private_key(public_exponent=65537, key_size=3072)
    subject = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, "Arcadis RH - Selo institucional")])
    now = datetime.now(timezone.utc)
    certificate = (
        x509.CertificateBuilder()
        .subject_name(subject)
        .issuer_name(subject)
        .public_key(private_key.public_key())
        .serial_number(x509.random_serial_number())
        .not_valid_before(now - timedelta(minutes=1))
        .not_valid_after(now + timedelta(days=365))
        .add_extension(x509.BasicConstraints(ca=False, path_length=None), critical=True)
        .add_extension(
            x509.KeyUsage(
                digital_signature=True,
                content_commitment=True,
                key_encipherment=False,
                data_encipherment=False,
                key_agreement=False,
                key_cert_sign=False,
                crl_sign=False,
                encipher_only=False,
                decipher_only=False,
            ),
            critical=True,
        )
        .sign(private_key, hashes.SHA256())
    )
    bundle = pkcs12.serialize_key_and_certificates(
        b"Arcadis RH",
        private_key,
        certificate,
        None,
        serialization.BestAvailableEncryption(password.encode("utf-8")),
    )

    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    write_new(bundle_file, bundle, 0o600)
    write_new(certificate_file, certificate.public_bytes(serialization.Encoding.PEM), 0o644)
    return bundle_file, certificate_file, certificate.fingerprint(hashes.SHA256()).hex()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("diretorio", type=Path, nargs="?", help="Diretório privado acessível ao servidor PHP")
    parser.add_argument("--local", action="store_true", help="Configura o selo do Arcadis no perfil Windows atual")
    args = parser.parse_args()
    if args.local:
        perfil = os.environ.get("LOCALAPPDATA")
        if args.diretorio is not None or os.name != "nt" or not perfil:
            parser.error("Use --local sem diretório e em uma conta Windows com LOCALAPPDATA definido.")
        return configurar_selo_local(Path(perfil).resolve() / "ArcadisRH" / "assinatura")
    if args.diretorio is None:
        parser.error("Informe um diretório privado ou use --local no Windows.")
    directory = args.diretorio.resolve()
    public = Path(__file__).resolve().parents[1] / "public"
    if directory.is_relative_to(public):
        parser.error("Use um diretório fora da raiz pública.")

    password = os.environ.get("ARCADIS_SEAL_P12_PASSWORD")
    if not password:
        if not sys.stdin.isatty():
            parser.error("Configure ARCADIS_SEAL_P12_PASSWORD no ambiente.")
        password = getpass.getpass("Senha para proteger o arquivo PKCS#12: ")
        if password != getpass.getpass("Repita a senha: "):
            parser.error("As senhas diferem.")
    if not password:
        parser.error("Informe uma senha para o selo.")

    try:
        bundle, certificate, fingerprint = create_certificate(directory, password)
    except OSError as error:
        print(error, file=sys.stderr)
        return 1
    print(f"PKCS#12 privado: {bundle}")
    print(f"Certificado público: {certificate}")
    print(f"Impressão digital SHA-256: {fingerprint}")
    return 0


def configurar_selo_local(diretorio: Path) -> int:
    senha = secrets.token_urlsafe(36)
    try:
        if diretorio.exists():
            raise FileExistsError("Já existe uma configuração local. Preserve o certificado existente.")
        diretorio.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        with TemporaryDirectory(dir=diretorio.parent) as temporario:
            pasta = Path(temporario) / "assinatura"
            _, _, impressao_digital = create_certificate(pasta, senha)
            conteudo = json.dumps({"password": senha}).encode("utf-8")
            write_new(pasta / "selo.local.json", conteudo, 0o600)
            pasta.rename(diretorio)
    except OSError as error:
        print(error, file=sys.stderr)
        return 1
    print(f"Selo local configurado no perfil Windows. Impressão digital SHA-256: {impressao_digital}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
