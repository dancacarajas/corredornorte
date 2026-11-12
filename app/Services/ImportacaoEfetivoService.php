<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

/**
 * Fluxo: leitura → validação → comparação → prévia (sem gravar até confirmar).
 */
final class ImportacaoEfetivoService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    /**
     * @return array{
     *   importacao_id:int,
     *   totais:array{registros:int,iguais:int,atualizar:int,criar:int,erros:int},
     *   linhas:list<array<string,mixed>>
     * }
     */
    public function processarUpload(string $absolutePath, string $originalName, ?int $usuarioId = null): array
    {
        if (!is_file($absolutePath)) {
            throw new RuntimeException('Arquivo de importação não encontrado.');
        }

        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if ($rows === []) {
            throw new RuntimeException('Planilha vazia.');
        }

        $headerRow = array_shift($rows);
        $map = $this->mapHeaders(is_array($headerRow) ? $headerRow : []);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO rh_importacoes
                (arquivo_nome, arquivo_path, usuario_id, status, total_registros)
                VALUES (:nome, :path, :usuario_id, :status, 0)'
            );
            $stmt->execute([
                'nome' => $originalName,
                'path' => $absolutePath,
                'usuario_id' => $usuarioId,
                'status' => 'validado',
            ]);
            $importacaoId = (int) $this->db->lastInsertId();

            $totais = ['registros' => 0, 'iguais' => 0, 'atualizar' => 0, 'criar' => 0, 'erros' => 0];
            $linhaNum = 1;
            $preview = [];

            foreach ($rows as $row) {
                $linhaNum++;
                if (!is_array($row) || $this->rowEmpty($row)) {
                    continue;
                }

                $totais['registros']++;
                $parsed = $this->parseRow($row, $map);
                $resultado = $this->classificar($parsed);

                $acao = $resultado['acao'];
                $totais[$acao === 'erro' ? 'erros' : ($acao === 'igual' ? 'iguais' : ($acao === 'atualizar' ? 'atualizar' : 'criar'))]++;

                $ins = $this->db->prepare(
                    'INSERT INTO rh_importacoes_linhas
                    (importacao_id, linha, chave, acao, diff_json, colaborador_id)
                    VALUES (:importacao_id, :linha, :chave, :acao, :diff_json, :colaborador_id)'
                );
                $ins->execute([
                    'importacao_id' => $importacaoId,
                    'linha' => $linhaNum,
                    'chave' => $resultado['chave'],
                    'acao' => $acao,
                    'diff_json' => json_encode($resultado['diff'], JSON_UNESCAPED_UNICODE),
                    'colaborador_id' => $resultado['colaborador_id'],
                ]);

                if ($acao === 'erro') {
                    $err = $this->db->prepare(
                        'INSERT INTO rh_importacoes_erros (importacao_id, linha, campo, mensagem)
                         VALUES (:importacao_id, :linha, :campo, :mensagem)'
                    );
                    foreach ($resultado['erros'] as $campo => $mensagem) {
                        $err->execute([
                            'importacao_id' => $importacaoId,
                            'linha' => $linhaNum,
                            'campo' => $campo,
                            'mensagem' => $mensagem,
                        ]);
                    }
                }

                $preview[] = [
                    'linha' => $linhaNum,
                    'chave' => $resultado['chave'],
                    'acao' => $acao,
                    'diff' => $resultado['diff'],
                    'nome' => $parsed['nome'] ?? '',
                ];
            }

            $upd = $this->db->prepare(
                'UPDATE rh_importacoes SET
                    total_registros = :registros,
                    total_iguais = :iguais,
                    total_atualizar = :atualizar,
                    total_criar = :criar,
                    total_erros = :erros
                 WHERE id = :id'
            );
            $upd->execute([
                'registros' => $totais['registros'],
                'iguais' => $totais['iguais'],
                'atualizar' => $totais['atualizar'],
                'criar' => $totais['criar'],
                'erros' => $totais['erros'],
                'id' => $importacaoId,
            ]);

            $this->db->commit();

            return [
                'importacao_id' => $importacaoId,
                'totais' => $totais,
                'linhas' => $preview,
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<int|string,mixed> $header
     * @return array<string,string>
     */
    private function mapHeaders(array $header): array
    {
        $aliases = ModeloEfetivoService::aliasesPorCampo();
        $map = [];
        foreach ($header as $col => $label) {
            $norm = mb_strtolower(trim((string) $label));
            foreach ($aliases as $field => $names) {
                if (in_array($norm, $names, true)) {
                    $map[$field] = (string) $col;
                }
            }
        }

        if (!isset($map['matricula']) && !isset($map['cpf'])) {
            throw new RuntimeException('Planilha precisa de coluna Matrícula ou CPF.');
        }

        return $map;
    }

    /**
     * @param array<int|string,mixed> $row
     * @param array<string,string> $map
     * @return array<string,string>
     */
    private function parseRow(array $row, array $map): array
    {
        $out = [];
        foreach ($map as $field => $col) {
            $out[$field] = trim((string) ($row[$col] ?? ''));
        }
        if (isset($out['cpf'])) {
            $out['cpf'] = preg_replace('/\D+/', '', $out['cpf']) ?: '';
        }
        if (isset($out['telefone'])) {
            $out['telefone'] = trim($out['telefone']);
        }
        if (!empty($out['data_admissao'])) {
            $out['data_admissao'] = $this->normalizarData($out['data_admissao']);
        }
        if (!empty($out['status'])) {
            $out['status'] = mb_strtolower($out['status']);
        }

        return $out;
    }

    /**
     * @param array<string,string> $parsed
     * @return array{acao:string,chave:string,diff:array<string,mixed>,colaborador_id:?int,erros:array<string,string>}
     */
    private function classificar(array $parsed): array
    {
        $erros = [];
        if (($parsed['nome'] ?? '') === '') {
            $erros['nome'] = 'Nome obrigatório';
        }

        $chave = $parsed['matricula'] ?? ($parsed['cpf'] ?? '');
        if ($chave === '') {
            $erros['chave'] = 'Matrícula/CPF ausente';
        }
        if (!empty($parsed['cpf']) && strlen($parsed['cpf']) !== 11) {
            $erros['cpf'] = 'CPF inválido';
        }

        if ($erros !== []) {
            return [
                'acao' => 'erro',
                'chave' => $chave !== '' ? $chave : 'sem-chave',
                'diff' => [],
                'colaborador_id' => null,
                'erros' => $erros,
            ];
        }

        $existing = null;
        if (!empty($parsed['matricula'])) {
            $stmt = $this->db->prepare('SELECT * FROM rh_colaboradores WHERE matricula = :m LIMIT 1');
            $stmt->execute(['m' => $parsed['matricula']]);
            $existing = $stmt->fetch() ?: null;
        }
        if ($existing === null && !empty($parsed['cpf'])) {
            $stmt = $this->db->prepare('SELECT * FROM rh_colaboradores WHERE cpf = :c LIMIT 1');
            $stmt->execute(['c' => $parsed['cpf']]);
            $existing = $stmt->fetch() ?: null;
        }

        if ($existing === null) {
            return [
                'acao' => 'criar',
                'chave' => $chave,
                'diff' => ['novo' => $parsed],
                'colaborador_id' => null,
                'erros' => [],
            ];
        }

        $camposCadastro = [
            'nome', 'email_corp', 'email_alt', 'telefone', 'data_admissao',
            'cargo', 'funcao', 'status', 'cpf',
        ];
        $diff = [];
        foreach ($camposCadastro as $field) {
            if (!array_key_exists($field, $parsed) || $parsed[$field] === '') {
                continue;
            }
            $antes = (string) ($existing[$field] ?? '');
            if ($field === 'data_admissao' && $antes !== '' && str_contains($antes, ' ')) {
                $antes = substr($antes, 0, 10);
            }
            $depois = $parsed[$field];
            if ($antes !== $depois) {
                $diff[$field] = ['anterior' => $antes, 'novo' => $depois];
            }
        }

        foreach (['empresa', 'gestor_matricula', 'supervisor_matricula', 'lotacao', 'centro_custo', 'os', 'localidade'] as $field) {
            if (!empty($parsed[$field])) {
                $diff[$field] = ['novo' => $parsed[$field]];
            }
        }

        $hintKeys = ['empresa', 'gestor_matricula', 'supervisor_matricula', 'lotacao', 'centro_custo', 'os', 'localidade'];
        $somenteHints = $diff;
        foreach ($hintKeys as $k) {
            unset($somenteHints[$k]);
        }
        $temMudancaCadastro = $somenteHints !== [];
        $temHintExtra = false;
        foreach ($hintKeys as $k) {
            if (isset($diff[$k])) {
                $temHintExtra = true;
                break;
            }
        }

        return [
            'acao' => ($temMudancaCadastro || $temHintExtra) ? 'atualizar' : 'igual',
            'chave' => $chave,
            'diff' => $diff,
            'colaborador_id' => (int) $existing['id'],
            'erros' => [],
        ];
    }

    /**
     * Confirma a prévia e aplica criações/atualizações no efetivo.
     */
    public function confirmar(int $importacaoId, ?int $usuarioId = null): int
    {
        $stmt = $this->db->prepare('SELECT * FROM rh_importacoes WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $importacaoId]);
        $imp = $stmt->fetch();
        if ($imp === false) {
            throw new RuntimeException('Importação não encontrada.');
        }
        if (($imp['status'] ?? '') === 'confirmado') {
            throw new RuntimeException('Importação já confirmada.');
        }

        $linhas = $this->db->prepare(
            "SELECT * FROM rh_importacoes_linhas WHERE importacao_id = :id AND acao IN ('criar','atualizar')"
        );
        $linhas->execute(['id' => $importacaoId]);
        $aplicadas = 0;

        $this->db->beginTransaction();
        try {
            $empresaId = (int) ($this->db->query("SELECT id FROM rh_empresas WHERE status='ativo' ORDER BY id LIMIT 1")->fetchColumn() ?: 0);
            if ($empresaId <= 0) {
                throw new RuntimeException('Nenhuma empresa ativa para importação.');
            }

            foreach ($linhas->fetchAll() as $linha) {
                $diff = json_decode((string) ($linha['diff_json'] ?? '{}'), true) ?: [];
                if (($linha['acao'] ?? '') === 'criar') {
                    $novo = $diff['novo'] ?? [];
                    $empId = $this->resolverEmpresaId((string) ($novo['empresa'] ?? ''), $empresaId);
                    $cpf = preg_replace('/\D+/', '', (string) ($novo['cpf'] ?? ''))
                        ?: ('9' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT));
                    $gestorId = $this->resolverColabPorMatricula((string) ($novo['gestor_matricula'] ?? ''));
                    $supervisorId = $this->resolverColabPorMatricula((string) ($novo['supervisor_matricula'] ?? ''));
                    $ins = $this->db->prepare(
                        'INSERT INTO rh_colaboradores
                            (empresa_id, matricula, nome, cpf, email_corp, email_alt, telefone, data_admissao, cargo, funcao, status, gestor_id, supervisor_id)
                         VALUES
                            (:empresa_id, :matricula, :nome, :cpf, :email_corp, :email_alt, :telefone, :data_admissao, :cargo, :funcao, :status, :gestor_id, :supervisor_id)'
                    );
                    $ins->execute([
                        'empresa_id' => $empId,
                        'matricula' => (string) ($novo['matricula'] ?? $linha['chave']),
                        'nome' => (string) ($novo['nome'] ?? 'Sem nome'),
                        'cpf' => substr($cpf, 0, 11),
                        'email_corp' => ($novo['email_corp'] ?? null) ?: null,
                        'email_alt' => ($novo['email_alt'] ?? null) ?: null,
                        'telefone' => ($novo['telefone'] ?? null) ?: null,
                        'data_admissao' => ($novo['data_admissao'] ?? null) ?: null,
                        'cargo' => ($novo['cargo'] ?? null) ?: null,
                        'funcao' => ($novo['funcao'] ?? null) ?: null,
                        'status' => ($novo['status'] ?? 'ativo') ?: 'ativo',
                        'gestor_id' => $gestorId > 0 ? $gestorId : null,
                        'supervisor_id' => $supervisorId > 0 ? $supervisorId : null,
                    ]);
                    $novoId = (int) $this->db->lastInsertId();
                    $lotacaoId = $this->resolverLotacaoFromParsed($novo);
                    if ($lotacaoId > 0 && $novoId > 0) {
                        (new TransferenciaLotacaoService($this->db))->transferir(
                            $novoId,
                            $lotacaoId,
                            (string) (($novo['data_admissao'] ?? '') ?: date('Y-m-d')),
                            'importacao',
                            'Admissão via importação',
                            $usuarioId
                        );
                    }
                    $aplicadas++;
                    continue;
                }

                $colabId = (int) ($linha['colaborador_id'] ?? 0);
                if ($colabId <= 0) {
                    continue;
                }
                $sets = [];
                $params = ['id' => $colabId];
                foreach (['nome', 'email_corp', 'email_alt', 'telefone', 'data_admissao', 'cargo', 'funcao', 'status', 'cpf'] as $field) {
                    if (isset($diff[$field]['novo'])) {
                        $sets[] = "{$field} = :{$field}";
                        $params[$field] = $diff[$field]['novo'] !== '' ? $diff[$field]['novo'] : null;
                    }
                }
                if (isset($diff['empresa']['novo'])) {
                    $sets[] = 'empresa_id = :empresa_id';
                    $params['empresa_id'] = $this->resolverEmpresaId((string) $diff['empresa']['novo'], $empresaId);
                }
                if (isset($diff['gestor_matricula']['novo'])) {
                    $gid = $this->resolverColabPorMatricula((string) $diff['gestor_matricula']['novo']);
                    $sets[] = 'gestor_id = :gestor_id';
                    $params['gestor_id'] = $gid > 0 ? $gid : null;
                }
                if (isset($diff['supervisor_matricula']['novo'])) {
                    $sid = $this->resolverColabPorMatricula((string) $diff['supervisor_matricula']['novo']);
                    $sets[] = 'supervisor_id = :supervisor_id';
                    $params['supervisor_id'] = $sid > 0 ? $sid : null;
                }
                if ($sets !== []) {
                    $sql = 'UPDATE rh_colaboradores SET ' . implode(', ', $sets) . ' WHERE id = :id';
                    $this->db->prepare($sql)->execute($params);
                    $aplicadas++;
                }

                $parsedLot = [
                    'lotacao' => (string) ($diff['lotacao']['novo'] ?? ''),
                    'centro_custo' => (string) ($diff['centro_custo']['novo'] ?? ''),
                    'os' => (string) ($diff['os']['novo'] ?? ''),
                    'localidade' => (string) ($diff['localidade']['novo'] ?? ''),
                ];
                $lotacaoId = $this->resolverLotacaoFromParsed($parsedLot);
                if ($lotacaoId > 0) {
                    $atual = $this->db->prepare(
                        'SELECT lotacao_id FROM rh_colaborador_lotacoes WHERE colaborador_id = :c AND vigencia_fim IS NULL LIMIT 1'
                    );
                    $atual->execute(['c' => $colabId]);
                    $atualLot = (int) ($atual->fetchColumn() ?: 0);
                    if ($atualLot !== $lotacaoId) {
                        (new TransferenciaLotacaoService($this->db))->transferir(
                            $colabId,
                            $lotacaoId,
                            date('Y-m-d'),
                            'importacao',
                            'Importação planilha mestre',
                            $usuarioId
                        );
                        $aplicadas++;
                    }
                }
            }

            $this->db->prepare(
                "UPDATE rh_importacoes SET status = 'confirmado', confirmed_at = NOW() WHERE id = :id"
            )->execute(['id' => $importacaoId]);

            $aud = $this->db->prepare(
                'INSERT INTO rh_auditoria (entidade, entidade_id, acao, depois_json, usuario_id)
                 VALUES (\'rh_importacoes\', :id, \'confirmar\', :depois, :usuario)'
            );
            $aud->execute([
                'id' => $importacaoId,
                'depois' => json_encode(['aplicadas' => $aplicadas], JSON_UNESCAPED_UNICODE),
                'usuario' => $usuarioId,
            ]);

            $this->db->commit();
            return $aplicadas;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** @param array<string,mixed> $parsed */
    private function resolverLotacaoFromParsed(array $parsed): int
    {
        $codigoLot = trim((string) ($parsed['lotacao'] ?? ''));
        if ($codigoLot !== '') {
            $st = $this->db->prepare("SELECT id FROM rh_lotacoes WHERE codigo = :c AND status = 'ativo' LIMIT 1");
            $st->execute(['c' => $codigoLot]);

            return (int) ($st->fetchColumn() ?: 0);
        }

        return $this->resolverLotacaoPorCodigos(
            (string) ($parsed['centro_custo'] ?? ''),
            (string) ($parsed['os'] ?? ''),
            (string) ($parsed['localidade'] ?? '')
        );
    }

    private function resolverLotacaoPorCodigos(string $cc, string $os, string $localidade): int
    {
        if ($cc === '' && $os === '' && $localidade === '') {
            return 0;
        }
        $sql = 'SELECT l.id FROM rh_lotacoes l
                INNER JOIN rh_centros_custo cc ON cc.id = l.centro_custo_id
                INNER JOIN rh_ordens_servico os ON os.id = l.ordem_servico_id
                INNER JOIN rh_localidades loc ON loc.id = l.localidade_id
                WHERE l.status = \'ativo\'';
        $params = [];
        if ($cc !== '') {
            $sql .= ' AND cc.codigo = :cc';
            $params['cc'] = $cc;
        }
        if ($os !== '') {
            $sql .= ' AND os.codigo = :os';
            $params['os'] = $os;
        }
        if ($localidade !== '') {
            $sql .= ' AND (loc.municipio = :loc OR loc.nome = :loc2)';
            $params['loc'] = $localidade;
            $params['loc2'] = $localidade;
        }
        $sql .= ' ORDER BY l.id LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    private function resolverEmpresaId(string $nomeOuId, int $fallbackId): int
    {
        $nomeOuId = trim($nomeOuId);
        if ($nomeOuId === '') {
            return $fallbackId;
        }
        if (ctype_digit($nomeOuId)) {
            return (int) $nomeOuId;
        }
        $st = $this->db->prepare("SELECT id FROM rh_empresas WHERE nome = :n AND status = 'ativo' LIMIT 1");
        $st->execute(['n' => $nomeOuId]);
        $id = (int) ($st->fetchColumn() ?: 0);

        return $id > 0 ? $id : $fallbackId;
    }

    private function resolverColabPorMatricula(string $matricula): int
    {
        $matricula = trim($matricula);
        if ($matricula === '') {
            return 0;
        }
        $st = $this->db->prepare('SELECT id FROM rh_colaboradores WHERE matricula = :m LIMIT 1');
        $st->execute(['m' => $matricula]);

        return (int) ($st->fetchColumn() ?: 0);
    }

    private function normalizarData(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $ts = strtotime($value);

        return $ts ? date('Y-m-d', $ts) : $value;
    }

    /**
     * @param array<int|string,mixed> $row
     */
    private function rowEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }
}
