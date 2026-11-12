#!/usr/bin/env python3
"""Deploy Arcadis/Corredor Norte para Hostinger via SFTP/SSH."""

from __future__ import annotations

import os
import stat
import sys
import time
from pathlib import Path

import paramiko

HOST = os.environ.get("HOSTINGER_SSH_HOST", "82.198.236.39")
PORT = int(os.environ.get("HOSTINGER_SSH_PORT", "65002"))
USER = os.environ.get("HOSTINGER_SSH_USER", "u482227589")
PASSWORD = os.environ.get("HOSTINGER_SSH_PASSWORD", "")
REMOTE_ROOT = os.environ.get(
    "HOSTINGER_REMOTE_ROOT",
    "/home/u482227589/domains/corredornorte.feston.net.br/public_html",
)
# Fallback explorado em runtime se o caminho padrão não existir.
FALLBACK_ROOTS = [
    "/home/u482227589/domains/feston.net.br/public_html/corredornorte",
    "/home/u482227589/public_html/corredornorte",
]

LOCAL_ROOT = Path(__file__).resolve().parents[1]

SKIP_DIR_NAMES = {
    ".git",
    ".cursor",
    "node_modules",
    "agent-transcripts",
    "__pycache__",
}
SKIP_FILE_SUFFIXES = {".pyc", ".pyo"}
SKIP_FILE_NAMES = {".env", "database.local.php", "app.local.php", "install.lock"}


def should_skip(path: Path, rel: Path) -> bool:
    parts = set(rel.parts)
    if parts & SKIP_DIR_NAMES:
        return True
    if path.name in SKIP_FILE_NAMES:
        return True
    if path.suffix in SKIP_FILE_SUFFIXES:
        return True
    # Não sobe dumps locais / saídas grandes desnecessárias
    if rel.parts and rel.parts[0] == "output" and path.name != ".gitkeep":
        return True
    if "storage" in rel.parts and path.name not in {".gitkeep"} and path.suffix not in {""}:
        # sobe só .gitkeep dentro de storage/*
        if path.is_file() and path.name != ".gitkeep":
            return True
    return False


def ensure_remote_dir(sftp: paramiko.SFTPClient, remote: str) -> None:
    parts = remote.strip("/").split("/")
    cur = ""
    for part in parts:
        cur += "/" + part
        try:
            sftp.stat(cur)
        except FileNotFoundError:
            sftp.mkdir(cur)


def upload_tree(sftp: paramiko.SFTPClient, local_root: Path, remote_root: str) -> tuple[int, int]:
    files = 0
    bytes_ = 0
    for path in local_root.rglob("*"):
        rel = path.relative_to(local_root)
        if should_skip(path, rel):
            continue
        remote_path = remote_root.rstrip("/") + "/" + rel.as_posix()
        if path.is_dir():
            ensure_remote_dir(sftp, remote_path)
            continue
        ensure_remote_dir(sftp, str(Path(remote_path).parent).replace("\\", "/"))
        sftp.put(str(path), remote_path)
        files += 1
        bytes_ += path.stat().st_size
        if files % 50 == 0:
            print(f"  ... {files} arquivos ({bytes_ / 1_000_000:.1f} MB)")
    return files, bytes_


def remote_exists(sftp: paramiko.SFTPClient, path: str) -> bool:
    try:
        sftp.stat(path)
        return True
    except FileNotFoundError:
        return False


def resolve_remote_root(sftp: paramiko.SFTPClient, ssh: paramiko.SSHClient) -> str:
    candidates = [REMOTE_ROOT, *FALLBACK_ROOTS]
    # Descobre pastas reais
    stdin, stdout, stderr = ssh.exec_command(
        "ls -d /home/u482227589/domains/*/public_html 2>/dev/null; "
        "ls -d /home/u482227589/public_html 2>/dev/null; "
        "ls -la /home/u482227589 2>/dev/null | head -n 40"
    )
    listing = stdout.read().decode("utf-8", errors="replace")
    print("Exploração remota:\n" + listing)

    for cand in candidates:
        parent = str(Path(cand).parent).replace("\\", "/")
        if remote_exists(sftp, cand) or remote_exists(sftp, parent) or remote_exists(sftp, "/".join(cand.split("/")[:5])):
            # cria se necessário
            ensure_remote_dir(sftp, cand)
            return cand

    # Preferência: public_html/corredornorte
    chosen = "/home/u482227589/public_html/corredornorte"
    ensure_remote_dir(sftp, chosen)
    return chosen


def run_remote(ssh: paramiko.SSHClient, cmd: str) -> str:
    print(f"$ {cmd}")
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    if out.strip():
        print(out.rstrip())
    if err.strip():
        print(err.rstrip(), file=sys.stderr)
    if code != 0:
        raise RuntimeError(f"Comando remoto falhou ({code}): {cmd}")
    return out


def main() -> int:
    if not PASSWORD:
        print("Defina HOSTINGER_SSH_PASSWORD", file=sys.stderr)
        return 1

    print(f"Conectando {USER}@{HOST}:{PORT} ...")
    ssh = paramiko.SSHClient()
    ssh.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    ssh.connect(HOST, port=PORT, username=USER, password=PASSWORD, timeout=30)
    sftp = ssh.open_sftp()

    try:
        remote_root = resolve_remote_root(sftp, ssh)
        print(f"Destino remoto: {remote_root}")

        # Garante estrutura
        for d in ("storage/uploads", "storage/logs", "storage/assinaturas", "storage/tmp", "output", "public/install"):
            ensure_remote_dir(sftp, remote_root.rstrip("/") + "/" + d)

        t0 = time.time()
        files, nbytes = upload_tree(sftp, LOCAL_ROOT, remote_root)
        print(f"Upload: {files} arquivos, {nbytes / 1_000_000:.2f} MB em {time.time() - t0:.1f}s")

        # Permissões básicas
        run_remote(
            ssh,
            f"chmod -R u+rwX,go+rX {remote_root}/public {remote_root}/app {remote_root}/config "
            f"{remote_root}/database {remote_root}/vendor 2>/dev/null; "
            f"chmod -R ug+rwX {remote_root}/storage {remote_root}/output 2>/dev/null; "
            f"find {remote_root}/storage -type d -exec chmod 775 {{}} \\; ; "
            f"php -v | head -n 1; "
            f"test -f {remote_root}/public/install/index.php && echo INSTALLER_OK; "
            f"test -f {remote_root}/vendor/autoload.php && echo VENDOR_OK; "
            f"ls -la {remote_root} | head -n 30",
        )

        # Se houver domains/corredornorte..., tenta apontar docroot via .htaccess (já no projeto)
        print("Deploy concluído.")
        print(f"Abra: https://corredornorte.feston.net.br/install/  (ou a URL do subdomínio)")
        return 0
    finally:
        sftp.close()
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
