<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use Throwable;

/** Assinatura do relatório com o mesmo aceite, token e trilha de auditoria da folha. */
final class RelatorioAssinaturaService
{
    public const TEXTO_ACEITE = 'Declaro que conferi o relatório de atividades exibido neste portal e concordo em assiná-lo eletronicamente. O Arcadis RH registrará o acesso pelo link individual, o nome digitado, a data, o IP e o hash de integridade do documento.';
    private const METODO_ASSINATURA = 'Link individual do relatório, nome digitado e declaração de aceite';
    private const PASTA_PDF = 'storage/uploads/relatorios/';

    public function __construct(private PDO $db)
    {
    }

    public static function assinaturaConfigurada(): bool
    {
        // A folha usa a via PHP quando o selo PAdES não está disponível.
        return true;
    }

    /** @return array{portal_url:string,documento_id:int} */
    public function gerarLinkPortal(int $relatorioId, string $pdfPath, ?int $usuarioId = null): array
    {
        $pdfPath = $this->normalizarCaminho($pdfPath);
        $arquivo = $this->caminhoPdfOriginal($pdfPath);
        $hash = hash_file('sha256', $arquivo);
        if ($hash === false) {
            throw new RuntimeException('Não foi possível verificar o PDF do relatório.');
        }

        $copia = null;
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare(
                'SELECT r.id, r.status FROM ra_relatorios r WHERE r.id = ? LIMIT 1 FOR UPDATE'
            );
            $st->execute([$relatorioId]);
            $relatorio = $st->fetch(PDO::FETCH_ASSOC);
            if ($relatorio === false) {
                throw new RuntimeException('Relatório não encontrado.');
            }
            if (!in_array((string) $relatorio['status'], ['enviado', 'aprovado'], true)) {
                throw new RuntimeException('Envie o relatório antes de gerar o link de assinatura.');
            }

            $docs = $this->db->prepare(
                "SELECT * FROM ra_documentos WHERE relatorio_id = ? AND status <> 'invalidado' ORDER BY id DESC FOR UPDATE"
            );
            $docs->execute([$relatorioId]);
            foreach ($docs->fetchAll(PDO::FETCH_ASSOC) as $doc) {
                $arquivoRegistrado = $this->caminhoDestino((string) $doc['arquivo_path']);
                $hashRegistrado = is_file($arquivoRegistrado) ? hash_file('sha256', $arquivoRegistrado) : false;
                if (hash_equals((string) $doc['hash_sha256'], $hash)
                    && $hashRegistrado !== false && hash_equals($hash, $hashRegistrado)) {
                    $this->db->commit();
                    return [
                        'portal_url' => $this->baseUrl() . '/assinar-relatorio/' . $doc['portal_token'],
                        'documento_id' => (int) $doc['id'],
                    ];
                }
                if ($doc['status'] === 'enviado') {
                    $this->db->prepare("UPDATE ra_documentos SET status = 'invalidado' WHERE id = ?")
                        ->execute([(int) $doc['id']]);
                }
            }

            $token = bin2hex(random_bytes(24));
            $codigo = bin2hex(random_bytes(16));
            // Cada link conserva uma via imutável, mesmo se a exportação for refeita depois.
            $copia = self::PASTA_PDF . 'relatorio_' . $relatorioId . '_via_' . bin2hex(random_bytes(8)) . '.pdf';
            $destino = $this->caminhoDestino($copia);
            if (!copy($arquivo, $destino) || !hash_equals($hash, (string) hash_file('sha256', $destino))) {
                throw new RuntimeException('Não foi possível arquivar a via do relatório.');
            }
            $ins = $this->db->prepare(
                "INSERT INTO ra_documentos
                 (relatorio_id, codigo_verificacao, portal_token, arquivo_path, hash_sha256, status)
                 VALUES (?, ?, ?, ?, ?, 'enviado')"
            );
            $ins->execute([$relatorioId, $codigo, $token, $copia, $hash]);
            $documentoId = (int) $this->db->lastInsertId();
            $this->auditar($documentoId, 'link_assinatura', ['hash_sha256' => $hash], $usuarioId);
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($copia !== null) {
                $arquivoCopia = $this->caminhoDestino($copia);
                if (is_file($arquivoCopia)) {
                    unlink($arquivoCopia);
                }
            }
            throw $erro;
        }

        return [
            'portal_url' => $this->baseUrl() . '/assinar-relatorio/' . $token,
            'documento_id' => $documentoId,
        ];
    }

    public function buscarPorRelatorio(int $relatorioId): ?array
    {
        $st = $this->db->prepare(
            "SELECT * FROM ra_documentos WHERE relatorio_id = ? AND status <> 'invalidado' ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$relatorioId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function buscarPorId(int $documentoId): ?array
    {
        $st = $this->db->prepare('SELECT * FROM ra_documentos WHERE id = ? LIMIT 1');
        $st->execute([$documentoId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function buscarPorToken(string $token, bool $lock = false): ?array
    {
        if (!preg_match('/\A[a-f0-9]{48}\z/i', $token)) {
            return null;
        }
        $st = $this->db->prepare(
            "SELECT d.*, r.status AS relatorio_status, r.colaborador_id,
                    c.nome, c.matricula, pp.data_inicio, pp.data_fim
             FROM ra_documentos d
             INNER JOIN ra_relatorios r ON r.id = d.relatorio_id
             INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
             INNER JOIN ponto_periodos pp ON pp.id = r.periodo_id
             WHERE d.portal_token = ? AND d.status <> 'invalidado'
               AND (d.status = 'assinado' OR r.status IN ('enviado','aprovado'))
             LIMIT 1" . ($lock ? ' FOR UPDATE' : '')
        );
        $st->execute([$token]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function buscarPorCodigo(string $codigo): ?array
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/i', $codigo)) {
            return null;
        }
        $st = $this->db->prepare(
            'SELECT d.*, c.nome, c.matricula, pp.data_inicio, pp.data_fim
             FROM ra_documentos d
             INNER JOIN ra_relatorios r ON r.id = d.relatorio_id
             INNER JOIN rh_colaboradores c ON c.id = r.colaborador_id
             INNER JOIN ponto_periodos pp ON pp.id = r.periodo_id
             WHERE d.codigo_verificacao = ? LIMIT 1'
        );
        $st->execute([$codigo]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function assinarNoPortal(string $token, string $nome, bool $aceite, string $hashVisualizado): void
    {
        if (!$aceite) {
            throw new RuntimeException('Confirme o aceite para assinar o relatório.');
        }
        $assinadoPath = null;
        $this->db->beginTransaction();
        try {
            $doc = $this->buscarPorToken($token, true);
            if ($doc === null || $doc['status'] !== 'enviado') {
                throw new RuntimeException('O relatório não está disponível para assinatura.');
            }
            if (!in_array((string) $doc['relatorio_status'], ['enviado', 'aprovado'], true)) {
                throw new RuntimeException('O relatório foi reaberto. Solicite uma nova via ao RH.');
            }
            if ($hashVisualizado === '' || !hash_equals((string) $doc['hash_sha256'], $hashVisualizado)) {
                throw new RuntimeException('Atualize a página e confira a via atual do relatório antes de assinar.');
            }
            if (mb_strlen($nome) > 160 || AssinaturaEletronicaService::normalizarNome($nome) === ''
                || AssinaturaEletronicaService::normalizarNome($nome) !== AssinaturaEletronicaService::normalizarNome((string) $doc['nome'])) {
                throw new RuntimeException('Digite o nome completo que aparece no relatório.');
            }
            $original = $this->caminhoPdfOriginal((string) $doc['arquivo_path']);
            $hash = hash_file('sha256', $original);
            if ($hash === false || !hash_equals((string) $doc['hash_sha256'], $hash)) {
                throw new RuntimeException('O PDF foi alterado após a emissão. Solicite uma nova via ao RH.');
            }

            $id = (int) $doc['id'];
            $assinadoEm = date('Y-m-d H:i:s');
            $audit = [
                'nome' => (string) $doc['nome'],
                'assinado_em' => date('c', strtotime($assinadoEm)),
                'sha256' => $hash,
                'codigo' => (string) $doc['codigo_verificacao'],
                'verificar_url' => $this->baseUrl() . '/verificar-relatorio/' . $doc['codigo_verificacao'],
            ];
            $assinadoPath = self::PASTA_PDF . 'relatorio_' . $id . '_assinado_' . bin2hex(random_bytes(8)) . '.pdf';
            $saida = $this->caminhoDestino($assinadoPath);
            (new RelatorioAssinaturaPdf())->carimbar($original, $saida, $audit);
            $this->selarSeDisponivel($saida);
            $hashAssinado = hash_file('sha256', $saida);
            if ($hashAssinado === false) {
                throw new RuntimeException('Não foi possível verificar o PDF assinado.');
            }

            $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
            $dispositivo = mb_strcut(mb_scrub((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'UTF-8'), 0, 255, 'UTF-8');
            $this->db->prepare(
                "UPDATE ra_documentos SET status = 'assinado', arquivo_assinado_path = ?,
                 arquivo_assinado_sha256 = ?, assinatura_nome = ?, assinatura_ip = ?,
                 assinatura_user_agent = ?, assinatura_em = ?, assinatura_aceite = 1,
                 assinatura_texto_aceite = ? WHERE id = ?"
            )->execute([$assinadoPath, $hashAssinado, $doc['nome'], $ip, $dispositivo,
                $assinadoEm, self::TEXTO_ACEITE, $id]);
            $this->auditar($id, 'assinatura_eletronica', [
                'hash_sha256' => $hash,
                'arquivo_assinado_sha256' => $hashAssinado,
                'metodo_assinatura' => self::METODO_ASSINATURA,
                'assinado_em' => $assinadoEm,
                'colaborador_id' => (int) ($doc['colaborador_id'] ?? 0) ?: null,
                'colaborador_nome' => (string) ($doc['nome'] ?? ''),
                'ator_contexto' => 'portal_he',
            ]);
            $this->db->commit();
        } catch (Throwable $erro) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($assinadoPath !== null) {
                $arquivo = $this->caminhoDestino($assinadoPath);
                if (is_file($arquivo)) {
                    unlink($arquivo);
                }
            }
            throw $erro;
        }
    }

    /** @return array{original:bool,assinado:bool} */
    public function verificarIntegridade(array $doc): array
    {
        $original = $this->caminhoPdfOriginal((string) $doc['arquivo_path']);
        $hashOriginal = hash_file('sha256', $original);
        $assinado = (string) ($doc['arquivo_assinado_path'] ?? '');
        $arquivoAssinado = $assinado !== '' ? $this->caminhoDestino($assinado) : '';
        $hashAssinado = $arquivoAssinado !== '' && is_file($arquivoAssinado)
            ? hash_file('sha256', $arquivoAssinado) : false;
        return [
            'original' => $hashOriginal !== false && hash_equals((string) $doc['hash_sha256'], $hashOriginal),
            'assinado' => $hashAssinado !== false && hash_equals((string) ($doc['arquivo_assinado_sha256'] ?? ''), $hashAssinado),
        ];
    }

    public function caminhoArquivo(array $doc, bool $assinado): string
    {
        $relativo = $assinado ? (string) ($doc['arquivo_assinado_path'] ?? '') : (string) $doc['arquivo_path'];
        if ($relativo === '') {
            throw new RuntimeException('PDF indisponível.');
        }
        return $assinado ? $this->caminhoDestino($relativo) : $this->caminhoPdfOriginal($relativo);
    }

    private function caminhoPdfOriginal(string $relativo): string
    {
        $arquivo = $this->caminhoDestino($relativo);
        if (!is_file($arquivo) || strtolower(pathinfo($arquivo, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException('Gere o PDF preenchido do relatório antes de criar o link.');
        }
        $stream = fopen($arquivo, 'rb');
        $cabecalho = $stream !== false ? fread($stream, 4) : false;
        if ($stream !== false) {
            fclose($stream);
        }
        if ($cabecalho !== '%PDF') {
            throw new RuntimeException('O arquivo do relatório não é um PDF válido.');
        }
        return $arquivo;
    }

    private function caminhoDestino(string $relativo): string
    {
        if (!str_starts_with(str_replace('\\', '/', $relativo), self::PASTA_PDF)
            || str_contains($relativo, '..') || str_contains($relativo, '\\')) {
            throw new RuntimeException('Caminho do PDF inválido.');
        }
        return dirname(__DIR__, 2) . '/' . $relativo;
    }

    private function normalizarCaminho(string $caminho): string
    {
        $normal = str_replace('\\', '/', $caminho);
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
        if (str_starts_with($normal, $root)) {
            $normal = substr($normal, strlen($root));
        }
        $this->caminhoDestino($normal);
        return $normal;
    }

    private function auditar(int $id, string $acao, array $dados, ?int $usuario = null): void
    {
        // rh_auditoria.usuario_id referencia usuarios(id). Portal HE usa colaborador_id — não misturar.
        $usuarioId = $this->usuarioSistemaId($usuario);
        if ($usuario !== null && $usuarioId === null) {
            $dados['ator_colaborador_id'] = $usuario;
            $dados['ator_contexto'] = 'portal_he';
        }

        $this->db->prepare(
            "INSERT INTO rh_auditoria (entidade, entidade_id, acao, depois_json, usuario_id)
             VALUES ('ra_documentos', ?, ?, ?, ?)"
        )->execute([$id, $acao, json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $usuarioId]);
    }

    private function usuarioSistemaId(?int $usuario): ?int
    {
        if ($usuario === null || $usuario <= 0) {
            return null;
        }
        $st = $this->db->prepare('SELECT id FROM usuarios WHERE id = ? LIMIT 1');
        $st->execute([$usuario]);
        $id = $st->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function selarSeDisponivel(string $pdf): void
    {
        $selo = self::configuracaoSelo();
        if ($selo === null || !function_exists('proc_open')) {
            return;
        }
        $root = dirname(__DIR__, 2);
        $saida = $pdf . '.pades.pdf';
        $programas = PHP_OS_FAMILY === 'Windows' ? [['py', '-3'], ['python']] : [['python3'], ['python']];
        foreach ($programas as $programa) {
            $cmd = [...$programa, $root . '/scripts/selar_pdf_relatorio.py', $pdf, $saida];
            $processo = @proc_open($cmd, [
                0 => ['pipe', 'r'],
                1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'a'],
                2 => ['pipe', 'w'],
            ], $pipes, $root, array_replace(getenv() ?: [], $selo));
            if (!is_resource($processo)) {
                continue;
            }
            fclose($pipes[0]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            if (proc_close($processo) === 0 && is_file($saida)) {
                if (!rename($saida, $pdf)) {
                    unlink($saida);
                    throw new RuntimeException('Não foi possível gravar o PDF selado.');
                }
                return;
            }
            if (is_file($saida)) {
                unlink($saida);
            }
        }
        error_log('Arcadis RH: selo PAdES do relatório indisponível, usando carimbo PHP.');
    }

    /** @return array{ARCADIS_SEAL_P12_PATH:string,ARCADIS_SEAL_P12_PASSWORD:string}|null */
    private static function configuracaoSelo(): ?array
    {
        $candidatos = [];
        $envPath = getenv('ARCADIS_SEAL_P12_PATH');
        $envPass = getenv('ARCADIS_SEAL_P12_PASSWORD');
        if (is_string($envPath) && $envPath !== '' && is_string($envPass) && $envPass !== '') {
            $candidatos[] = [$envPath, $envPass];
        }
        $root = dirname(__DIR__, 2) . '/storage/assinaturas';
        $candidatos[] = [$root . '/arcadis-selo.p12', self::lerSenhaSelo($root . '/selo.local.json')];
        if (PHP_OS_FAMILY === 'Windows') {
            $perfil = getenv('LOCALAPPDATA');
            if (is_string($perfil) && $perfil !== '') {
                $dir = $perfil . '/ArcadisRH/assinatura';
                $candidatos[] = [$dir . '/arcadis-selo.p12', self::lerSenhaSelo($dir . '/selo.local.json')];
            }
        }
        foreach ($candidatos as [$path, $password]) {
            if (is_string($path) && is_file($path) && is_readable($path)
                && is_string($password) && $password !== '') {
                return [
                    'ARCADIS_SEAL_P12_PATH' => $path,
                    'ARCADIS_SEAL_P12_PASSWORD' => $password,
                ];
            }
        }
        return null;
    }

    private static function lerSenhaSelo(string $arquivo): ?string
    {
        if (!is_file($arquivo) || !is_readable($arquivo)) {
            return null;
        }
        $json = file_get_contents($arquivo);
        if ($json === false) {
            return null;
        }
        $senha = json_decode($json, true)['password'] ?? null;
        return is_string($senha) && $senha !== '' ? $senha : null;
    }

    private function baseUrl(): string
    {
        return \App\Helpers\AppUrl::base(dirname(__DIR__, 2));
    }
}
