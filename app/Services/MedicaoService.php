<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use RuntimeException;
use ZipArchive;

final class MedicaoService
{
    private PDO $db;
    private string $root;

    /** @var list<array{key:string,label:string,hint:string,acao:string}> */
    public const ETAPAS = [
        ['key' => 'gerado', 'label' => '1. Cartão gerado', 'hint' => 'Espelho do período criado', 'acao' => 'Gerar cartão de ponto'],
        ['key' => 'tratado', 'label' => '2. Ponto conferido', 'hint' => 'Conferir período com HE tratada', 'acao' => 'Conferir período'],
        ['key' => 'fechado', 'label' => '3. Ponto fechado', 'hint' => 'Fechar tratativa do espelho', 'acao' => 'Fechar período'],
        ['key' => 'pdf', 'label' => '4. PDF gerado', 'hint' => 'Emitir folha de ponto', 'acao' => 'Gerar PDF'],
        ['key' => 'link', 'label' => '5. Link de assinatura', 'hint' => 'Portal pronto para o colaborador', 'acao' => 'Gerar link'],
        ['key' => 'email', 'label' => '6. E-mail enviado', 'hint' => 'Enviar folha ao colaborador', 'acao' => 'Enviar e-mail'],
        ['key' => 'assinaturas', 'label' => '7. Assinaturas', 'hint' => 'Ponto e RA assinados pelo colaborador', 'acao' => 'Aguardar assinaturas'],
    ];

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
        $this->root = dirname(__DIR__, 2);
    }

    /**
     * @return list<array{id:int,data_inicio:string,data_fim:string,status:string,espelhos:int,label:string}>
     */
    public function listarPeriodosGerados(int $limit = 24): array
    {
        $rows = $this->db->query(
            "SELECT pp.id, pp.data_inicio, pp.data_fim, pp.status, COUNT(e.id) AS espelhos
             FROM ponto_periodos pp
             INNER JOIN ponto_espelhos e ON e.periodo_id = pp.id
             GROUP BY pp.id, pp.data_inicio, pp.data_fim, pp.status
             ORDER BY pp.data_inicio DESC, pp.data_fim DESC, pp.id DESC
             LIMIT " . max(1, $limit)
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $ini = (string) $row['data_inicio'];
            $fim = (string) $row['data_fim'];
            $out[] = [
                'id' => (int) $row['id'],
                'data_inicio' => $ini,
                'data_fim' => $fim,
                'status' => (string) $row['status'],
                'espelhos' => (int) $row['espelhos'],
                'label' => date('d/m/Y', strtotime($ini)) . ' a ' . date('d/m/Y', strtotime($fim)),
            ];
        }
        return $out;
    }

    /**
     * @return array{
     *   periodo:array{data_inicio:string,data_fim:string,label:string},
     *   resumo:array{total:int,concluidos:int,percentual:int,pronto_dossie:bool},
     *   colaboradores:list<array<string,mixed>>,
     *   etapas:list<array{key:string,label:string,hint:string}>
     * }
     */
    public function painel(string $dataInicio, string $dataFim): array
    {
        $rows = $this->carregarLinhas($dataInicio, $dataFim);
        $concluidos = 0;
        $somaPct = 0;
        $feitos = [
            'gerado' => 0,
            'tratado' => 0,
            'fechado' => 0,
            'pdf' => 0,
            'link' => 0,
            'email' => 0,
            'assinaturas' => 0,
        ];
        // Quem pode avançar em cada ação de lote (ordem da jornada).
        $elegiveis = [
            'conferir' => 0,
            'fechar' => 0,
            'pdf' => 0,
            'link' => 0,
            'email' => 0,
        ];

        foreach ($rows as $row) {
            $pct = (int) $row['percentual'];
            $somaPct += $pct;
            if ($pct >= 100) {
                $concluidos++;
            }
            foreach ($feitos as $key => $_) {
                if (!empty($row['etapas'][$key])) {
                    $feitos[$key]++;
                }
            }

            $et = $row['etapas'];
            $heBloqueia = !empty($row['he_pendente']);
            if (empty($et['tratado']) && !$heBloqueia) {
                $elegiveis['conferir']++;
            }
            if (!empty($et['tratado']) && empty($et['fechado']) && !$heBloqueia) {
                $elegiveis['fechar']++;
            }
            if (!empty($et['fechado']) && empty($et['pdf'])) {
                $elegiveis['pdf']++;
            }
            if (!empty($et['pdf']) && empty($et['link'])) {
                $elegiveis['link']++;
            }
            if (!empty($et['link']) && empty($et['email']) && !empty($row['email_corp'])) {
                $elegiveis['email']++;
            }
        }

        $total = count($rows);
        $pctGeral = $total > 0 ? (int) round($somaPct / $total) : 0;

        // Próxima ação do período = primeiro gargalo da jornada com alguém elegível.
        $ordemAcao = ['conferir', 'fechar', 'pdf', 'link', 'email'];
        $proximaAcao = null;
        foreach ($ordemAcao as $acao) {
            if ($elegiveis[$acao] > 0) {
                $proximaAcao = $acao;
                break;
            }
        }

        return [
            'periodo' => [
                'data_inicio' => $dataInicio,
                'data_fim' => $dataFim,
                'label' => date('d/m/Y', strtotime($dataInicio)) . ' a ' . date('d/m/Y', strtotime($dataFim)),
            ],
            'resumo' => [
                'total' => $total,
                'concluidos' => $concluidos,
                'percentual' => $pctGeral,
                'pronto_dossie' => $total > 0 && $concluidos === $total,
                'feitos' => $feitos,
                'elegiveis' => $elegiveis,
                'proxima_acao' => $proximaAcao,
            ],
            'colaboradores' => $rows,
            'etapas' => self::ETAPAS,
        ];
    }

    private function tableExists(string $table): bool
    {
        $st = $this->db->prepare(
            "SELECT 1 FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        $st->execute([$table]);
        return (bool) $st->fetchColumn();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function carregarLinhas(string $dataInicio, string $dataFim): array
    {
        $hasRaDocs = $this->tableExists('ra_documentos');
        $raSelect = $hasRaDocs
            ? "rad.id AS ra_doc_id, rad.status AS ra_doc_status, rad.arquivo_assinado_path AS ra_assinada_path"
            : "NULL AS ra_doc_id, NULL AS ra_doc_status, NULL AS ra_assinada_path";
        $raJoin = $hasRaDocs
            ? "LEFT JOIN ra_documentos rad ON rad.id = (
                SELECT rd.id FROM ra_documentos rd
                WHERE rd.relatorio_id = r.id AND rd.status <> 'invalidado'
                ORDER BY rd.id DESC LIMIT 1
             )"
            : "";

        $st = $this->db->prepare(
            "SELECT e.id AS espelho_id, e.status AS espelho_status, e.colaborador_id,
                    c.nome, c.matricula, c.email_corp, c.telefone, COALESCE(c.he_fluxo, 'off') AS he_fluxo,
                    r.id AS relatorio_id, r.status AS ra_status,
                    d.id AS documento_id, d.status AS folha_status, d.portal_token,
                    d.arquivo_path AS folha_path, d.arquivo_assinado_path AS folha_assinada_path,
                    {$raSelect}
             FROM ponto_espelhos e
             INNER JOIN ponto_periodos pp ON pp.id = e.periodo_id
             INNER JOIN rh_colaboradores c ON c.id = e.colaborador_id
             LEFT JOIN ra_relatorios r
                ON r.colaborador_id = e.colaborador_id AND r.periodo_id = e.periodo_id
             LEFT JOIN documentos d ON d.id = (
                SELECT d2.id FROM documentos d2
                WHERE d2.espelho_id = e.id AND d2.tipo = 'folha_ponto' AND d2.status <> 'invalidado'
                ORDER BY d2.id DESC LIMIT 1
             )
             {$raJoin}
             WHERE pp.data_inicio = :i AND pp.data_fim = :f
             ORDER BY c.nome, e.id"
        );
        $st->execute(['i' => $dataInicio, 'f' => $dataFim]);
        $raw = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $hePendentes = $this->mapHePendentesPorEspelho(array_column($raw, 'espelho_id'));
        $docIds = array_filter(array_column($raw, 'documento_id'));
        $emailsOk = $this->mapEmailsOkPorDocumento($docIds);
        $emailsUltimo = $this->mapEmailsUltimoPorDocumento($docIds);

        $out = [];
        foreach ($raw as $row) {
            $espelhoId = (int) $row['espelho_id'];
            $statusEsp = (string) $row['espelho_status'];
            $folhaStatus = (string) ($row['folha_status'] ?? '');
            $raDocStatus = (string) ($row['ra_doc_status'] ?? '');
            $docId = isset($row['documento_id']) ? (int) $row['documento_id'] : 0;
            $emailOk = !empty($emailsOk[$docId]) || $folhaStatus === 'assinado';
            $emailUltimo = $emailsUltimo[$docId] ?? null;

            // Fluxo real do ponto: em_aberto → em_tratamento → conferido → fechado → enviado → assinado
            // Etapa 2 só conta após "Conferir período" (ou status posterior).
            // em_tratamento NÃO é tratado/conferido.
            // E-mail só avança com status=enviado em emails_envios — falha permanece na etapa 6.
            $etapas = [
                'gerado' => true,
                'tratado' => in_array($statusEsp, ['conferido', 'fechado', 'enviado', 'assinado'], true)
                    && empty($hePendentes[$espelhoId]),
                'fechado' => in_array($statusEsp, ['fechado', 'enviado', 'assinado'], true),
                'pdf' => in_array($folhaStatus, ['gerado', 'enviado', 'assinado'], true),
                'link' => !empty($row['portal_token']) || $folhaStatus === 'assinado',
                'email' => $emailOk,
                'assinaturas' => $folhaStatus === 'assinado' && $raDocStatus === 'assinado',
            ];

            $feitos = count(array_filter($etapas));
            $totalEtapas = count(self::ETAPAS);
            $percentual = (int) round(($feitos / $totalEtapas) * 100);

            $token = trim((string) ($row['portal_token'] ?? ''));
            $portalUrl = $token !== '' ? ($this->baseUrl() . '/assinar/' . $token) : null;

            $out[] = [
                'espelho_id' => $espelhoId,
                'colaborador_id' => (int) $row['colaborador_id'],
                'nome' => (string) $row['nome'],
                'matricula' => (string) $row['matricula'],
                'email_corp' => $row['email_corp'] !== null ? (string) $row['email_corp'] : null,
                'telefone' => isset($row['telefone']) && $row['telefone'] !== null && trim((string) $row['telefone']) !== ''
                    ? trim((string) $row['telefone'])
                    : null,
                'espelho_status' => $statusEsp,
                'relatorio_id' => isset($row['relatorio_id']) ? (int) $row['relatorio_id'] : null,
                'documento_id' => $docId > 0 ? $docId : null,
                'folha_status' => $folhaStatus !== '' ? $folhaStatus : null,
                'ra_status' => $row['ra_status'] !== null ? (string) $row['ra_status'] : null,
                'ra_doc_status' => $raDocStatus !== '' ? $raDocStatus : null,
                'folha_assinada_path' => $row['folha_assinada_path'] !== null ? (string) $row['folha_assinada_path'] : null,
                'ra_assinada_path' => $row['ra_assinada_path'] !== null ? (string) $row['ra_assinada_path'] : null,
                'portal_token' => $token !== '' ? $token : null,
                'portal_url' => $portalUrl,
                'he_pendente' => !empty($hePendentes[$espelhoId]),
                'email_ok' => $emailOk,
                'email_ultimo_status' => is_array($emailUltimo) ? (string) ($emailUltimo['status'] ?? '') : null,
                'email_ultimo_erro' => is_array($emailUltimo) ? ($emailUltimo['erro'] ?? null) : null,
                'etapas' => $etapas,
                'percentual' => $percentual,
                'concluido' => $percentual >= 100,
            ];
        }
        return $out;
    }

    private function baseUrl(): string
    {
        return \App\Helpers\AppUrl::base($this->root);
    }

    /**
     * @param list<int|string> $espelhoIds
     * @return array<int,true>
     */
    private function mapHePendentesPorEspelho(array $espelhoIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $espelhoIds)));
        if ($ids === []) {
            return [];
        }
        $hasHe = $this->db->query(
            "SELECT 1 FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ra_dias' AND COLUMN_NAME = 'he_status' LIMIT 1"
        )->fetchColumn();
        if (!$hasHe) {
            return [];
        }
        $in = implode(',', $ids);
        $sql = "SELECT e.id AS espelho_id
                FROM ponto_espelhos e
                INNER JOIN ra_relatorios r ON r.colaborador_id = e.colaborador_id AND r.periodo_id = e.periodo_id
                INNER JOIN ra_dias d ON d.relatorio_id = r.id
                WHERE e.id IN ({$in}) AND d.he_status = 'declarada'
                GROUP BY e.id";
        $map = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['espelho_id']] = true;
        }
        return $map;
    }

    /**
     * @param list<int|string> $documentoIds
     * @return array<int,true>
     */
    private function mapEmailsOkPorDocumento(array $documentoIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $documentoIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $sql = "SELECT documento_id FROM emails_envios
                WHERE documento_id IN ({$in}) AND status = 'enviado'
                GROUP BY documento_id";
        $map = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['documento_id']] = true;
        }
        return $map;
    }

    /**
     * Última tentativa de e-mail por documento (sucesso ou falha).
     *
     * @param list<int|string> $documentoIds
     * @return array<int, array{status:string,erro:?string}>
     */
    private function mapEmailsUltimoPorDocumento(array $documentoIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $documentoIds)));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', $ids);
        $sql = "SELECT e.documento_id, e.status, e.erro_mensagem
                FROM emails_envios e
                INNER JOIN (
                    SELECT documento_id, MAX(id) AS max_id
                    FROM emails_envios
                    WHERE documento_id IN ({$in})
                    GROUP BY documento_id
                ) u ON u.max_id = e.id";
        $map = [];
        foreach ($this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $map[(int) $row['documento_id']] = [
                'status' => (string) ($row['status'] ?? ''),
                'erro' => isset($row['erro_mensagem']) && $row['erro_mensagem'] !== null
                    ? (string) $row['erro_mensagem']
                    : null,
            ];
        }
        return $map;
    }

    /**
     * Gera ZIP do dossiê (só se todos 100%).
     * @return array{path:string,filename:string}
     */
    public function gerarDossieZip(string $dataInicio, string $dataFim): array
    {
        $painel = $this->painel($dataInicio, $dataFim);
        if (!$painel['resumo']['pronto_dossie']) {
            throw new RuntimeException('O dossiê só fica disponível quando todos os colaboradores estiverem 100%.');
        }

        $tmpDir = $this->root . '/storage/tmp';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            throw new RuntimeException('Não foi possível criar pasta temporária.');
        }

        $slug = 'Medicao_' . $dataInicio . '_a_' . $dataFim . '_' . date('Ymd_His');
        $zipPath = $tmpDir . '/' . $slug . '.zip';
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível criar o arquivo ZIP.');
        }

        try {
            foreach ($painel['colaboradores'] as $colab) {
                $pasta = $this->nomePastaSeguro((string) $colab['nome']);
                $folha = $colab['folha_assinada_path'] ?? null;
                $ra = $colab['ra_assinada_path'] ?? null;
                if (!is_string($folha) || $folha === '' || !is_string($ra) || $ra === '') {
                    throw new RuntimeException('Arquivos assinados incompletos para ' . $colab['nome']);
                }
                $folhaAbs = $this->absPath($folha);
                $raAbs = $this->absPath($ra);
                if (!is_file($folhaAbs) || !is_file($raAbs)) {
                    throw new RuntimeException('PDF assinado não encontrado para ' . $colab['nome']);
                }
                $zip->addFile($folhaAbs, $pasta . '/Cartao_de_ponto_assinado.pdf');
                $zip->addFile($raAbs, $pasta . '/Relatorio_de_Atividades_assinado.pdf');
            }
        } finally {
            $zip->close();
        }

        return [
            'path' => $zipPath,
            'filename' => $slug . '.zip',
        ];
    }

    private function nomePastaSeguro(string $nome): string
    {
        $nome = trim(preg_replace('/[\\\\\/\:\*\?\"\<\>\|]+/', ' ', $nome) ?? $nome);
        $nome = preg_replace('/\s+/', ' ', $nome) ?? $nome;
        return $nome !== '' ? $nome : 'Colaborador';
    }

    private function absPath(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        if (str_starts_with($rel, $this->root)) {
            return $rel;
        }
        return $this->root . '/' . ltrim($rel, '/');
    }
}
