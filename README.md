# Arcadis RH Operacional

Sistema de RH operacional completo: **Efetivo**, **Gestão de Ponto** e **Folha de Ponto**.

## Stack

- PHP 8.1+
- MySQL 8
- PDO
- MVC leve em PHP puro
- PhpSpreadsheet (importação Excel)
- Sessões PHP (auth)

## Design System

Identidade Arcadis (laranja `#E06008`):

- `/ui/showcase`
- `public/assets/css/tokens.css`
- `public/assets/css/components.css`
- `public/assets/css/layout.css`

## Setup

```bash
composer install --ignore-platform-reqs
```

Configure `config/database.php` (padrão local: `root` / `root` em `127.0.0.1:3306`).

```bash
php scripts/install_db.php
php -S 127.0.0.1:8080 -t public
```

Login: `admin@arcadis.local` / `admin123`

## Verificação

```bash
php scripts/e2e_verify.php
php scripts/http_smoke.php
```

## Módulos

| Módulo | Rotas |
|--------|-------|
| Efetivo | `/rh/efetivo`, cadastros, colaboradores, transferência |
| Importação | `/rh/importacoes` (upload → prévia → confirmar) |
| Ponto | `/rh/ponto` (gerar espelho, ocorrências, fechamento) |
| Folha | `/rh/folha` (PDF, envio, controle) |

## Assinatura da folha de ponto

Na tela da folha, o RH gera um link individual. O colaborador abre o link, confere o PDF, digita o nome completo e confirma a declaração de aceite. O Arcadis registra o aceite e entrega o cartão de uma página com assinatura visível e rodapé com hash do original, link e QR de validação. A via assinada também fica disponível na tela do RH. Compartilhe o link somente com o colaborador correspondente, pois quem o possui pode acessar a folha.

### Ativação no servidor

Para uso local no Windows, execute `py -3 scripts/criar_certificado_selo.py --local` uma vez. O Arcadis lê o certificado do perfil da conta que executa o PHP. Use `http://127.0.0.1:8080` para abrir a aplicação local.

1. Execute `php scripts/migrate_assinatura_eletronica.php` no banco existente e instale as dependências PHP com `composer install`.
2. Defina `ARCADIS_APP_URL` com a URL HTTPS pública e use `public` como a raiz do site. Links e QR usam essa URL.
3. Em hospedagem com funções de shell desabilitadas, o PHP gera o cartão e registra o aceite e os hashes. Para aplicar o selo criptográfico PAdES em um servidor com `proc_open`, instale `requirements-assinatura.txt` no Python usado pelo PHP e configure `ARCADIS_SEAL_P12_PATH` e `ARCADIS_SEAL_P12_PASSWORD` no ambiente privado do servidor. Use `python scripts/gerar_folha_assinada.py --check` para conferir o certificado.

O portal atualiza a via pendente do modelo 3 quando o colaborador abre o link. O endereço continua válido e a via substituída fica no histórico. O RH também pode atualizar o modelo ao enviar ou gerar um link.

### Conferência e evidência

O verificador público compara o SHA-256 dos PDFs arquivados e permite conferir uma cópia no próprio navegador. Nos arquivos com PAdES, um leitor PDF pode verificar o selo criptográfico usando o certificado público institucional obtido por um canal confiável. Preserve os certificados públicos históricos quando houver rotação. A data e a hora registradas vêm do servidor Arcadis.

A auditoria registra o uso do link individual, o nome digitado e a declaração de aceite. A posse do link, por si só, não confirma de forma independente a identidade de quem o abriu. A força probatória depende da aceitação do método e da avaliação das evidências no caso concreto, conforme o [art. 10, § 2º, da MP 2.200-2/2001](https://www.planalto.gov.br/ccivil_03/mpv/antigas_2001/2200-2.htm).

## Docker (opcional)

Se a porta 3306 estiver livre:

```bash
docker compose up -d
```

O `docker-compose.yml` sobe MySQL 8.4 com schemas/seeds.
