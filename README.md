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

Na tela da folha, o RH gera um link individual. O colaborador abre o link, confere o PDF, digita o nome completo e confirma a declaração de aceite. O Arcadis registra o aceite e entrega o cartão de uma página com assinatura visível, selo PAdES e rodapé com hash do original, link e QR de validação. A via assinada também fica disponível na tela do RH. Compartilhe o link somente com o colaborador correspondente, pois quem o possui pode acessar a folha. A assinatura e o selo são processados pelo Arcadis sem integração com a Autentique.

### Ativação no servidor

Para uso local no Windows, execute `py -3 scripts/criar_certificado_selo.py --local` uma vez. O Arcadis lê o certificado do perfil da conta que executa o PHP. Use `http://127.0.0.1:8080` para abrir a aplicação local.

1. Execute `php scripts/migrate_assinatura_eletronica.php` no banco existente. Instale as dependências com `python -m pip install -r requirements-assinatura.txt` no Python 3.11 ou superior usado pelo PHP (`py -3` no Windows, `python3` no Linux).
2. Forneça um certificado institucional PKCS#12. Para gerar um certificado próprio, execute `python scripts/criar_certificado_selo.py <diretorio-privado>` e guarde o `.p12` e sua senha no ambiente privado do servidor. O utilitário também exporta o `.pem` público e sua impressão digital.
3. Configure `ARCADIS_SEAL_P12_PATH` com o caminho absoluto do `.p12` e `ARCADIS_SEAL_P12_PASSWORD` com a senha no gerenciador de segredos ou no ambiente do serviço PHP. Confira a configuração com `python scripts/gerar_folha_assinada.py --check` usando o mesmo ambiente do servidor.
4. Defina `ARCADIS_APP_URL` com a origem HTTPS pública, reinicie o PHP e use `public` como a raiz do site. Links e QR usam essa origem. O certificado institucional deve estar configurado para concluir novas assinaturas.

Após atualizar o modelo da folha, execute `php scripts/atualizar_links_folha.php` para reemitir vias pendentes com link ativo. O endereço continua válido e a via substituída fica no histórico. Ao enviar ou gerar um link pelo RH, o sistema atualiza automaticamente uma via pendente no modelo anterior.

### Conferência e evidência

O verificador público compara o SHA-256 dos PDFs arquivados e permite conferir uma cópia no próprio navegador. Um leitor PDF também pode verificar o selo criptográfico usando o certificado público institucional obtido por um canal confiável. Preserve os certificados públicos históricos quando houver rotação. A data e a hora registradas vêm do servidor Arcadis.

A auditoria registra o uso do link individual, o nome digitado e a declaração de aceite. A posse do link, por si só, não confirma de forma independente a identidade de quem o abriu. O certificado institucional sela o PDF e sua auditoria. A força probatória depende da aceitação do método e da avaliação das evidências no caso concreto, conforme o [art. 10, § 2º, da MP 2.200-2/2001](https://www.planalto.gov.br/ccivil_03/mpv/antigas_2001/2200-2.htm).

## Docker (opcional)

Se a porta 3306 estiver livre:

```bash
docker compose up -d
```

O `docker-compose.yml` sobe MySQL 8.4 com schemas/seeds.
