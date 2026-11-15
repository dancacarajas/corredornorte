#!/usr/bin/env python3
"""Deploy Corredor Norte para Hostinger via tar + SFTP (rápido)."""

from __future__ import annotations

import io
import os
import sys
import tarfile
import time
from pathlib import Path

import paramiko

HOST = os.environ.get("HOSTINGER_SSH_HOST", "")
PORT = int(os.environ.get("HOSTINGER_SSH_PORT", "65002"))
USER = os.environ.get("HOSTINGER_SSH_USER", "")
PASSWORD = os.environ.get("HOSTINGER_SSH_PASSWORD", "")
REMOTE_ROOT = os.environ.get("HOSTINGER_REMOTE_ROOT", "")
DEPLOY_URL = os.environ.get("HOSTINGER_DEPLOY_URL", "")

LOCAL_ROOT = Path(__file__).resolve().parents[1]

SKIP_DIR_NAMES = {
    ".git",
    ".cursor",
    "node_modules",
    "agent-transcripts",
    "__pycache__",
}
SKIP_FILE_NAMES = {".env", "database.local.php", "app.local.php", "install.lock", "app.key"}
DEV_SCRIPT_EXACT = {"deploy_hostinger.py", "install_db.php", "e2e_verify.php"}
DEV_SCRIPT_PREFIXES = (
    "_tmp_",
    "http_smoke",
    "seed_",
    "bench_",
    "verify_",
    "check_",
    "regen_",
    "recalc_",
)


def should_skip(path: Path, rel: Path) -> bool:
    if set(rel.parts) & SKIP_DIR_NAMES:
        return True
    if path.name in SKIP_FILE_NAMES:
        return True
    if path.suffix in {".pyc", ".pyo"}:
        return True
    if rel.parts and rel.parts[0] == "scripts" and path.is_file():
        if path.name in DEV_SCRIPT_EXACT or any(
            path.name.startswith(p) for p in DEV_SCRIPT_PREFIXES
        ):
            return True
    if rel.parts and rel.parts[0] == "output" and path.name != ".gitkeep":
        return True
    if len(rel.parts) >= 2 and rel.parts[0] == "storage":
        volatile = {"uploads", "logs", "tmp", "assinaturas"}
        if rel.parts[1] in volatile and path.is_file() and path.name != ".gitkeep":
            return True
    return False


def build_tar_bytes() -> tuple[bytes, int]:
    buf = io.BytesIO()
    count = 0
    with tarfile.open(fileobj=buf, mode="w:gz") as tar:
        for path in LOCAL_ROOT.rglob("*"):
            rel = path.relative_to(LOCAL_ROOT)
            if should_skip(path, rel):
                continue
            if path.is_dir():
                info = tarfile.TarInfo(name=rel.as_posix() + "/")
                info.type = tarfile.DIRTYPE
                info.mode = 0o755
                tar.addfile(info)
                continue
            if not path.is_file():
                continue
            tar.add(path, arcname=rel.as_posix(), recursive=False)
            count += 1
    data = buf.getvalue()
    return data, count


def run_remote(ssh: paramiko.SSHClient, cmd: str) -> str:
    print(f"$ {cmd}", flush=True)
    stdin, stdout, stderr = ssh.exec_command(cmd)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    code = stdout.channel.recv_exit_status()
    if out.strip():
        print(out.rstrip(), flush=True)
    if err.strip():
        print(err.rstrip(), file=sys.stderr, flush=True)
    if code != 0:
        raise RuntimeError(f"Comando remoto falhou ({code}): {cmd}")
    return out


def main() -> int:
    missing = [
        name
        for name, val in (
            ("HOSTINGER_SSH_HOST", HOST),
            ("HOSTINGER_SSH_USER", USER),
            ("HOSTINGER_SSH_PASSWORD", PASSWORD),
            ("HOSTINGER_REMOTE_ROOT", REMOTE_ROOT),
        )
        if not val
    ]
    if missing:
        print(f"Defina as variáveis de ambiente: {', '.join(missing)}", file=sys.stderr)
        return 1

    print("Empacotando projeto...", flush=True)
    t0 = time.time()
    data, count = build_tar_bytes()
    print(
        f"Pacote pronto: {count} arquivos, {len(data) / 1_000_000:.2f} MB "
        f"em {time.time() - t0:.1f}s",
        flush=True,
    )

    print(f"Conectando {USER}@{HOST}:{PORT} ...", flush=True)
    ssh = paramiko.SSHClient()
    ssh.load_system_host_keys()
    if os.environ.get("HOSTINGER_SSH_STRICT", "1") == "0":
        ssh.set_missing_host_key_policy(paramiko.WarningPolicy())
    else:
        ssh.set_missing_host_key_policy(paramiko.RejectPolicy())
    ssh.connect(
        HOST,
        port=PORT,
        username=USER,
        password=PASSWORD,
        timeout=30,
        banner_timeout=30,
        auth_timeout=30,
    )
    sftp = ssh.open_sftp()

    try:
        remote_root = REMOTE_ROOT.rstrip("/")
        remote_tar = f"/home/{USER}/tmp_corredornorte_deploy.tar.gz"
        run_remote(ssh, f"mkdir -p {remote_root} $(dirname {remote_tar})")

        print(f"Enviando pacote para {remote_tar} ...", flush=True)
        t1 = time.time()
        with sftp.file(remote_tar, "wb") as rf:
            rf.write(data)
        print(f"Upload OK em {time.time() - t1:.1f}s", flush=True)

        run_remote(
            ssh,
            f"rm -rf {remote_root}.bak && "
            f"if [ -d {remote_root} ] && [ \"$(ls -A {remote_root} 2>/dev/null)\" ]; then "
            f"  mv {remote_root} {remote_root}.bak; "
            f"fi; "
            f"mkdir -p {remote_root} && "
            f"tar -xzf {remote_tar} -C {remote_root} && "
            f"rm -f {remote_tar} && "
            f"mkdir -p {remote_root}/storage/uploads {remote_root}/storage/uploads/avatars "
            f"{remote_root}/storage/logs {remote_root}/storage/assinaturas "
            f"{remote_root}/storage/tmp {remote_root}/output && "
            f"chmod -R u+rwX,go+rX {remote_root}/public {remote_root}/app {remote_root}/config "
            f"{remote_root}/database {remote_root}/vendor && "
            f"chmod -R ug+rwX {remote_root}/storage {remote_root}/output && "
            f"test -f {remote_root}/public/install/index.php && echo INSTALLER_OK && "
            f"test -f {remote_root}/vendor/autoload.php && echo VENDOR_OK && "
            f"php -v | head -n 1 && "
            f"ls -la {remote_root} | head -n 25",
        )

        # Garante rewrite para /public se o document root for a pasta do projeto
        htaccess = (
            "RewriteEngine On\n"
            "RewriteRule ^$ public/ [L]\n"
            "RewriteRule (.*) public/$1 [L]\n"
        )
        with sftp.file(f"{remote_root}/.htaccess", "w") as hf:
            hf.write(htaccess)

        # Restaura configs locais e diretórios voláteis de storage da instalação anterior
        bak = f"{remote_root}.bak"
        run_remote(
            ssh,
            f"mkdir -p {remote_root}/config {remote_root}/storage && "
            f"if [ -f {bak}/config/database.local.php ]; then cp -a {bak}/config/database.local.php {remote_root}/config/; fi; "
            f"if [ -f {bak}/config/app.local.php ]; then cp -a {bak}/config/app.local.php {remote_root}/config/; fi; "
            f"if [ -f {bak}/storage/install.lock ]; then cp -a {bak}/storage/install.lock {remote_root}/storage/; fi; "
            f"if [ -f {bak}/storage/app.key ]; then cp -a {bak}/storage/app.key {remote_root}/storage/; fi; "
            f"if [ -f {bak}/storage/cron.secret ]; then cp -a {bak}/storage/cron.secret {remote_root}/storage/; fi; "
            f"for d in uploads assinaturas logs; do "
            f"  if [ -d {bak}/storage/$d ]; then cp -a {bak}/storage/$d/. {remote_root}/storage/$d/; fi; "
            f"done; "
            f"echo CONFIG_RESTORED",
        )

        print("Deploy concluído.", flush=True)
        if DEPLOY_URL:
            print(f"URL da aplicação: {DEPLOY_URL}", flush=True)
        return 0
    finally:
        sftp.close()
        ssh.close()


if __name__ == "__main__":
    raise SystemExit(main())
