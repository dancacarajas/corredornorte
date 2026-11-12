<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

final class AssinaturaEletronicaService
{
    public const TEXTO_ACEITE = 'Declaro que conferi a folha de ponto exibida neste portal e concordo em assiná-la eletronicamente. O Arcadis RH registrará o acesso pelo link individual, o nome digitado, a data, o IP e o hash de integridade do documento.';
    private const METODO_ASSINATURA = 'Link individual da folha, nome digitado e declaração de aceite';

    public function __construct(private PDO $db)
    {
    }

    public static function seloConfigurado(): bool
    {
        return self::configuracaoSelo() !== null;
    }

    /** @return array{ARCADIS_SEAL_P12_PATH: string, ARCADIS_SEAL_P12_PASSWORD: string}|null */
    private static function configuracaoSelo(): ?array
    {
        $path = getenv('ARCADIS_SEAL_P12_PATH');
        $password = getenv('ARCADIS_SEAL_P12_PASSWORD');
        if ($path === false && $password === false && PHP_OS_FAMILY === 'Windows') {
            $perfil = getenv('LOCALAPPDATA');
            if ($perfil === false || $perfil === '') {
                return null;
            }

            $diretorio = $perfil . '/ArcadisRH/assinatura';
            $path = $diretorio . '/arcadis-selo.p12';
            $arquivo = $diretorio . '/selo.local.json';
            if (!is_file($arquivo) || !is_readable($arquivo)) {
                return null;
            }
            $json = file_get_contents($arquivo);
            $password = $json === false ? null : (json_decode($json, true)['password'] ?? null);
        }

        if ($path === false || !is_file($path) || !is_readable($path) || !is_string($password) || $password === '') {
            return null;
        }
        return ['ARCADIS_SEAL_P12_PATH' => $path, 'ARCADIS_SEAL_P12_PASSWORD' => $password];
    }

    /** @return array{md5:string,sha1:string,sha256:string} */
    public function calcularHashes(string $filePath): array
    {
        $bin = is_file($filePath) ? file_get_contents($filePath) : false;
        if ($bin === false) {
            throw new RuntimeException('Não foi possível ler o arquivo do documento.');
        }

        return ['md5' => md5($bin), 'sha1' => sha1($bin), 'sha256' => hash('sha256', $bin)];
    }

    /** @return array{portal_url:string,documento_id:int} */
    public function gerarLinkPortal(int $documentoId, ?int $usuarioId = null, ?int $espelhoId = null): array
    {
        $documentoId = (new FolhaPontoService($this->db))->atualizarModeloPendente($documentoId, $usuarioId, $espelhoId);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM documentos WHERE id = ? FOR UPDATE');
            $stmt->execute([$documentoId]);
            $doc = $stmt->fetch();
            if (!$doc || ($espelhoId !== null && (int) $doc['espelho_id'] !== $espelhoId)) {
                throw new RuntimeException('Documento não encontrado nesta folha.');
            }
            if (!in_array($doc['status'], ['gerado', 'enviado'], true)) {
                throw new RuntimeException('Somente documentos pendentes podem receber link de assinatura.');
            }
            if ((int) $doc['layout_pdf'] !== FolhaPontoService::LAYOUT_PDF_ATUAL) {
                throw new RuntimeException('Gere a folha no modelo atual antes de compartilhar o link.');
            }

            $hashes = $this->calcularHashes($this->absPath($doc['arquivo_path']));
            if (!empty($doc['hash_sha256']) && !hash_equals($doc['hash_sha256'], $hashes['sha256'])) {
                throw new RuntimeException('O PDF foi alterado após a emissão. Gere uma nova versão.');
            }

            $token = $doc['portal_token'] ?: bin2hex(random_bytes(24));
            $this->db->prepare(
                "UPDATE documentos SET portal_token = ?, hash_md5 = ?, hash_sha1 = ?, hash_sha256 = ?, status = 'enviado' WHERE id = ?"
            )->execute([$token, $hashes['md5'], $hashes['sha1'], $hashes['sha256'], $documentoId]);
            if ($doc['periodo_inicio'] === null) {
                $this->db->prepare(
                    "UPDATE ponto_espelhos SET status = 'enviado' WHERE id = ? AND status IN ('fechado','conferido')"
                )->execute([(int) $doc['espelho_id']]);
            }
            $this->auditar($documentoId, 'link_assinatura', ['hash_sha256' => $hashes['sha256']], $usuarioId);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return ['portal_url' => $this->baseUrl() . '/assinar/' . $token, 'documento_id' => $documentoId];
    }

    public function assinarNoPortal(string $token, string $nome, bool $aceite, string $hashVisualizado): void
    {
        if (!$aceite) {
            throw new RuntimeException('Confirme o aceite para assinar a folha.');
        }
        $selo = self::configuracaoSelo();
        if ($selo === null) {
            throw new RuntimeException('O selo de assinatura está indisponível. Entre em contato com o RH.');
        }

        $assinadoPath = null;
        $this->db->beginTransaction();
        try {
            $doc = $this->buscarPorPortalToken($token, true);
            if ($doc === null || $doc['status'] !== 'enviado') {
                throw new RuntimeException('A folha não está disponível para assinatura.');
            }
            if ((int) $doc['layout_pdf'] !== FolhaPontoService::LAYOUT_PDF_ATUAL) {
                throw new RuntimeException('Peça ao RH para atualizar esta folha antes de assinar.');
            }
            if ($hashVisualizado === '' || !hash_equals((string) $doc['hash_sha256'], $hashVisualizado)) {
                throw new RuntimeException('Atualize a página e confira a via atual da folha antes de assinar.');
            }
            if ($doc['periodo_inicio'] !== null) {
                $espelhos = $this->db->prepare('SELECT e.status FROM documentos_espelhos v
                    JOIN ponto_espelhos e ON e.id = v.espelho_id WHERE v.documento_id = ? FOR UPDATE');
                $espelhos->execute([$doc['id']]);
                $estados = $espelhos->fetchAll(PDO::FETCH_COLUMN);
                if ($estados === [] || array_diff($estados, ['fechado', 'enviado', 'assinado']) !== []) {
                    throw new RuntimeException('O ponto do período foi reaberto. Solicite uma nova via ao RH.');
                }
            }
            $nomeNormalizado = static fn (string $valor): string => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $valor) ?? ''));
            if (mb_strlen($nome) > 160 || $nomeNormalizado($nome) === '' || $nomeNormalizado($nome) !== $nomeNormalizado($doc['nome'])) {
                throw new RuntimeException('Digite o nome completo que aparece na folha.');
            }

            $id = (int) $doc['id'];
            $original = $this->absPath($doc['arquivo_path']);
            $hashes = $this->calcularHashes($original);
            if (!hash_equals((string) $doc['hash_sha256'], $hashes['sha256'])) {
                throw new RuntimeException('O PDF foi alterado após a emissão. Solicite uma nova via ao RH.');
            }

            $assinadoEm = date('Y-m-d H:i:s');
            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $dispositivo = mb_strcut(mb_scrub((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'UTF-8'), 0, 255, 'UTF-8');
            $audit = [
                'nome' => $doc['nome'],
                'assinado_em' => date('c', strtotime($assinadoEm)),
                'sha256' => $hashes['sha256'],
                'codigo' => $doc['codigo_verificacao'],
                'verificar_url' => $this->baseUrl() . '/verificar/' . $doc['codigo_verificacao'],
            ];

            $assinadoPath = sprintf('storage/uploads/folhas/folha_%d_assinado_%s.pdf', $id, bin2hex(random_bytes(8)));
            $this->renderCartaoAssinado($original, $this->absPath($assinadoPath), $audit, $selo);
            $assinadoHash = hash_file('sha256', $this->absPath($assinadoPath));
            if ($assinadoHash === false) {
                throw new RuntimeException('Não foi possível verificar o PDF assinado.');
            }

            $this->db->prepare(
                "UPDATE documentos SET status = 'assinado', arquivo_assinado_path = ?, arquivo_assinado_sha256 = ?,
                 assinatura_nome = ?, assinatura_ip = ?, assinatura_user_agent = ?,
                 assinatura_em = ?, assinatura_aceite = 1, assinatura_texto_aceite = ?
                 WHERE id = ?"
            )->execute([$assinadoPath, $assinadoHash, $doc['nome'], $ip, $dispositivo,
                $assinadoEm, self::TEXTO_ACEITE, $id]);
            if ($doc['periodo_inicio'] === null) {
                $this->db->prepare("UPDATE ponto_espelhos SET status = 'assinado' WHERE id = ?")
                    ->execute([(int) $doc['espelho_id']]);
            }
            $this->auditar($id, 'assinatura_eletronica', [
                'hash_sha256' => $hashes['sha256'],
                'arquivo_assinado_sha256' => $assinadoHash,
                'metodo_assinatura' => self::METODO_ASSINATURA,
                'assinado_em' => $assinadoEm,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($assinadoPath !== null && is_file($this->absPath($assinadoPath))) {
                unlink($this->absPath($assinadoPath));
            }
            throw $e;
        }
    }

    public function buscarPorPortalToken(string $token, bool $lock = false): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32,64}\z/i', $token)) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT d.*, c.nome, c.matricula, pc.ano, pc.mes
             FROM documentos d
             INNER JOIN ponto_espelhos e ON e.id = d.espelho_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             INNER JOIN ponto_competencias pc ON pc.id = e.competencia_id
             WHERE d.portal_token = ? AND d.status <> 'invalidado'
             LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    private function auditar(int $id, string $acao, array $dados, ?int $usuario = null): void
    {
        $this->db->prepare(
            "INSERT INTO rh_auditoria (entidade, entidade_id, acao, depois_json, usuario_id)
             VALUES ('documentos', ?, ?, ?, ?)"
        )->execute([$id, $acao, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $usuario]);
    }

    private function renderCartaoAssinado(string $original, string $saida, array $auditoria, array $selo): void
    {
        $root = dirname(__DIR__, 2);
        $arquivo = tempnam($root . '/storage', 'assinatura_');
        if ($arquivo === false) {
            throw new RuntimeException('Não foi possível preparar a auditoria da assinatura.');
        }
        try {
            $json = json_encode($auditoria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($arquivo, $json) === false) {
                throw new RuntimeException('Não foi possível preparar a auditoria da assinatura.');
            }
            $programas = PHP_OS_FAMILY === 'Windows' ? [['py', '-3'], ['python']] : [['python3'], ['python']];
            $ambiente = array_replace(getenv(), $selo);
            foreach ($programas as $programa) {
                $cmd = [...$programa, $root . '/scripts/gerar_folha_assinada.py', $original, $saida, $arquivo];
                $processo = @proc_open($cmd, [
                    0 => ['pipe', 'r'],
                    1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
                    2 => ['pipe', 'w'],
                ], $pipes, $root, $ambiente);
                if (!is_resource($processo)) {
                    continue;
                }
                fclose($pipes[0]);
                $diagnostico = trim(stream_get_contents($pipes[2]));
                fclose($pipes[2]);
                if (proc_close($processo) === 0 && is_file($saida) && filesize($saida) > 1000) {
                    return;
                }
                if ($diagnostico !== '') {
                    error_log('Arcadis RH: falha ao executar a geração do PDF selado.');
                }
            }
        } finally {
            unlink($arquivo);
        }
        throw new RuntimeException('Não foi possível selar o PDF. Entre em contato com o RH.');
    }

    private function absPath(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . ltrim($rel, '/');
    }

    private function baseUrl(): string
    {
        $app = require dirname(__DIR__, 2) . '/config/app.php';
        if ($app['url'] !== '') {
            return rtrim($app['url'], '/');
        }
        return 'http://127.0.0.1:' . (int) ($_SERVER['SERVER_PORT'] ?? 8080);
    }
}

