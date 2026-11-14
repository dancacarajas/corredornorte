<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

final class ModeloEfetivoService
{
    /**
     * Matriz oficial = todos os campos do cadastro de colaborador + lotação.
     * Ordem fixa para importação e exportação.
     *
     * @var list<array{campo:string,coluna:string,aliases:list<string>}>
     */
    public const CAMPOS = [
        ['campo' => 'empresa', 'coluna' => 'Empresa', 'aliases' => ['empresa', 'empresa nome']],
        ['campo' => 'matricula', 'coluna' => 'Matrícula', 'aliases' => ['matricula', 'mat', 'codigo']],
        ['campo' => 'cpf', 'coluna' => 'CPF', 'aliases' => ['cpf']],
        ['campo' => 'nome', 'coluna' => 'Nome', 'aliases' => ['nome', 'nome completo', 'colaborador']],
        ['campo' => 'email_corp', 'coluna' => 'E-mail corporativo', 'aliases' => ['e-mail corporativo', 'email corporativo', 'email', 'e-mail']],
        ['campo' => 'email_alt', 'coluna' => 'E-mail alternativo', 'aliases' => ['e-mail alternativo', 'email alternativo', 'email alt']],
        ['campo' => 'telefone', 'coluna' => 'Telefone', 'aliases' => ['telefone', 'fone', 'celular']],
        ['campo' => 'data_admissao', 'coluna' => 'Admissão', 'aliases' => ['admissão', 'admissao', 'data admissão', 'data admissao', 'data_admissao']],
        ['campo' => 'cargo', 'coluna' => 'Cargo', 'aliases' => ['cargo']],
        ['campo' => 'funcao', 'coluna' => 'Função', 'aliases' => ['função', 'funcao']],
        ['campo' => 'status', 'coluna' => 'Status', 'aliases' => ['status', 'situacao', 'situação']],
        ['campo' => 'gestor_matricula', 'coluna' => 'Gestor (matrícula)', 'aliases' => ['gestor', 'gestor (matrícula)', 'gestor matricula', 'gestor_matricula']],
        ['campo' => 'supervisor_matricula', 'coluna' => 'Supervisor (matrícula)', 'aliases' => ['supervisor', 'supervisor (matrícula)', 'supervisor matricula', 'supervisor_matricula']],
        ['campo' => 'lotacao', 'coluna' => 'Lotação', 'aliases' => ['lotação', 'lotacao', 'pacote', 'codigo lotacao']],
        ['campo' => 'centro_custo', 'coluna' => 'Centro de Custo', 'aliases' => ['centro de custo', 'cc', 'centro_custo']],
        ['campo' => 'os', 'coluna' => 'OS', 'aliases' => ['os', 'ordem de servico', 'ordem de serviço']],
        ['campo' => 'localidade', 'coluna' => 'Localidade', 'aliases' => ['localidade', 'cidade', 'municipio', 'município']],
    ];

    /** @return list<string> */
    public static function colunas(): array
    {
        return array_map(static fn (array $c): string => $c['coluna'], self::CAMPOS);
    }

    /** @return array<string,list<string>> campo => aliases */
    public static function aliasesPorCampo(): array
    {
        $out = [];
        foreach (self::CAMPOS as $c) {
            $names = array_map('mb_strtolower', $c['aliases']);
            $names[] = mb_strtolower($c['coluna']);
            $out[$c['campo']] = array_values(array_unique($names));
        }

        return $out;
    }

    public function gerarArquivo(?string $destino = null): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/templates';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $destino ?: ($dir . '/modelo_matriz_efetivo.xlsx');

        $exemplos = [
            [
                'Arcadis Serviços', '10021', '52998224725', 'João Silva',
                'joao.silva@empresa.com', '', '94999990001', '2026-01-10',
                'Assistente Administrativo', 'Administrativo', 'ativo',
                '', '', 'LOT-ADM-PBE', 'CC-1234', 'OS-001', 'Parauapebas',
            ],
            [
                'Arcadis Serviços', '10045', '39053344705', 'Maria Silva',
                'maria.silva@empresa.com', '', '94999990002', '2026-02-01',
                'Analista', 'Administrativo', 'ativo',
                '10021', '', 'LOT-ADM-PBE', 'CC-1234', 'OS-001', 'Parauapebas',
            ],
            [
                'Arcadis Serviços', '10088', '15350946056', 'Carlos Souza',
                'carlos.souza@empresa.com', '', '94999990003', '2026-03-15',
                'Operador', 'Operação', 'ativo',
                '', '', 'LOT-OP-PBE', 'CC-200', 'OS-003', 'Parauapebas',
            ],
        ];

        return $this->gravarPlanilha($path, $exemplos, true);
    }

    /**
     * @param array{
     *   q?:string,contrato_id?:int,centro_custo_id?:int,
     *   ordem_servico_id?:int,localidade_id?:int,status?:string
     * } $filters
     */
    public function exportar(PDO $db, array $filters = [], ?string $destino = null): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/exports';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $destino ?: ($dir . '/efetivo_export_' . date('Ymd_His') . '.xlsx');

        return $this->gravarPlanilha($path, $this->consultarEfetivo($db, $filters), false);
    }

    /**
     * @param array{
     *   q?:string,contrato_id?:int,centro_custo_id?:int,
     *   ordem_servico_id?:int,localidade_id?:int,status?:string
     * } $filters
     * @return list<list<string>>
     */
    public function consultarEfetivo(PDO $db, array $filters = []): array
    {
        $sql = <<<SQL
            SELECT
              emp.nome AS empresa_nome,
              c.matricula, c.nome, c.cpf, c.email_corp, c.email_alt, c.telefone,
              c.data_admissao, c.cargo, c.funcao, c.status,
              g.matricula AS gestor_matricula,
              s.matricula AS supervisor_matricula,
              lot.codigo AS lotacao_codigo,
              cc.codigo AS cc_codigo,
              os.codigo AS os_codigo,
              loc.municipio, loc.nome AS localidade_nome
            FROM rh_colaboradores c
            INNER JOIN rh_empresas emp ON emp.id = c.empresa_id
            LEFT JOIN rh_colaboradores g ON g.id = c.gestor_id
            LEFT JOIN rh_colaboradores s ON s.id = c.supervisor_id
            LEFT JOIN rh_colaborador_lotacoes cl
              ON cl.colaborador_id = c.id AND cl.vigencia_fim IS NULL
            LEFT JOIN rh_lotacoes lot ON lot.id = cl.lotacao_id
            LEFT JOIN rh_contratos ct ON ct.id = lot.contrato_id
            LEFT JOIN rh_centros_custo cc ON cc.id = lot.centro_custo_id
            LEFT JOIN rh_ordens_servico os ON os.id = lot.ordem_servico_id
            LEFT JOIN rh_localidades loc ON loc.id = lot.localidade_id
            WHERE 1=1
        SQL;
        $params = [];
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= ' AND (c.nome LIKE :q1 OR c.matricula LIKE :q2 OR c.cpf LIKE :q3)';
            $like = '%' . $q . '%';
            $params['q1'] = $like;
            $params['q2'] = $like;
            $params['q3'] = $like;
        }
        if ((int) ($filters['contrato_id'] ?? 0) > 0) {
            $sql .= ' AND ct.id = :contrato_id';
            $params['contrato_id'] = (int) $filters['contrato_id'];
        }
        if ((int) ($filters['centro_custo_id'] ?? 0) > 0) {
            $sql .= ' AND cc.id = :cc_id';
            $params['cc_id'] = (int) $filters['centro_custo_id'];
        }
        if ((int) ($filters['ordem_servico_id'] ?? 0) > 0) {
            $sql .= ' AND os.id = :os_id';
            $params['os_id'] = (int) $filters['ordem_servico_id'];
        }
        if ((int) ($filters['localidade_id'] ?? 0) > 0) {
            $sql .= ' AND loc.id = :loc_id';
            $params['loc_id'] = (int) $filters['localidade_id'];
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $sql .= ' AND c.status = :status';
            $params['status'] = $status;
        }
        $sql .= ' ORDER BY c.nome LIMIT 10000';

        $st = $db->prepare($sql);
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $localidade = (string) ($row['municipio'] ?? '');
            if ($localidade === '' && !empty($row['localidade_nome'])) {
                $localidade = (string) $row['localidade_nome'];
            }
            $admissao = (string) ($row['data_admissao'] ?? '');
            if ($admissao !== '' && str_contains($admissao, ' ')) {
                $admissao = substr($admissao, 0, 10);
            }
            $out[] = [
                (string) ($row['empresa_nome'] ?? ''),
                (string) ($row['matricula'] ?? ''),
                (string) ($row['cpf'] ?? ''),
                (string) ($row['nome'] ?? ''),
                (string) ($row['email_corp'] ?? ''),
                (string) ($row['email_alt'] ?? ''),
                (string) ($row['telefone'] ?? ''),
                $admissao,
                (string) ($row['cargo'] ?? ''),
                (string) ($row['funcao'] ?? ''),
                (string) ($row['status'] ?? 'ativo'),
                (string) ($row['gestor_matricula'] ?? ''),
                (string) ($row['supervisor_matricula'] ?? ''),
                (string) ($row['lotacao_codigo'] ?? ''),
                (string) ($row['cc_codigo'] ?? ''),
                (string) ($row['os_codigo'] ?? ''),
                $localidade,
            ];
        }

        return $out;
    }

    /**
     * @param list<list<string>> $linhas
     */
    private function gravarPlanilha(string $path, array $linhas, bool $comInstrucoes): string
    {
        $cols = self::colunas();
        $colCount = count($cols);
        $lastCol = $this->colLetter($colCount - 1);

        $sheet = new Spreadsheet();
        $dados = $sheet->getActiveSheet();
        $dados->setTitle('Efetivo');

        foreach ($cols as $i => $col) {
            $dados->setCellValue($this->colLetter($i) . '1', $col);
        }
        foreach ($linhas as $r => $linha) {
            foreach ($linha as $c => $valor) {
                if ($c >= $colCount) {
                    break;
                }
                $safe = (string) $valor;
                if ($safe !== '' && in_array($safe[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $safe = "'" . $safe;
                }
                $dados->setCellValueExplicit(
                    $this->colLetter($c) . ($r + 2),
                    $safe,
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
                );
            }
        }

        $lastRow = max(1, count($linhas) + 1);
        $headerRange = 'A1:' . $lastCol . '1';
        $dados->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'C45C26'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']],
            ],
        ]);
        if ($lastRow >= 2) {
            $dados->getStyle('A2:' . $lastCol . $lastRow)->applyFromArray([
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']],
                ],
            ]);
        }
        for ($i = 0; $i < $colCount; $i++) {
            $dados->getColumnDimension($this->colLetter($i))->setAutoSize(true);
        }
        $dados->freezePane('A2');
        $dados->setAutoFilter('A1:' . $lastCol . $lastRow);

        if ($comInstrucoes) {
            $info = $sheet->createSheet();
            $info->setTitle('Instrucoes');
            $textos = [
                'Modelo matriz padrão — Cadastro completo do colaborador (Arcadis RH)',
                '',
                'Esta planilha espelha TODOS os campos da tela de cadastro de colaborador.',
                '',
                'Colunas (não renomear a linha 1)',
                implode(' | ', $cols),
                '',
                'Obrigatórios: Matrícula (ou CPF), Nome, CPF, Empresa (se houver mais de uma).',
                'Lotação: informe o código da lotação (ex.: LOT-ADM-PBE). Alternativa: Centro de Custo + OS + Localidade.',
                'Gestor/Supervisor: use a matrícula do colaborador já cadastrado.',
                'Admissão: data no formato AAAA-MM-DD (ex.: 2026-01-10).',
                'Status: ativo | afastado | inativo | desligado.',
                '',
                'Fluxo: baixar modelo → preencher → Importar → conferir prévia → confirmar.',
                'Exportar efetivo gera a mesma matriz com os dados atuais (pode reimportar).',
            ];
            foreach ($textos as $i => $txt) {
                $info->setCellValue('A' . ($i + 1), $txt);
            }
            $info->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $info->getStyle('A5')->getFont()->setBold(true);
            $info->getColumnDimension('A')->setWidth(120);
        }

        $sheet->setActiveSheetIndex(0);
        $parent = dirname($path);
        if (!is_dir($parent)) {
            mkdir($parent, 0775, true);
        }
        (new Xlsx($sheet))->save($path);
        $sheet->disconnectWorksheets();

        if (!is_file($path)) {
            throw new RuntimeException('Falha ao gerar a planilha do efetivo.');
        }

        return $path;
    }

    private function colLetter(int $index): string
    {
        $index = max(0, $index);
        $letter = '';
        while ($index >= 0) {
            $letter = chr(($index % 26) + 65) . $letter;
            $index = intdiv($index, 26) - 1;
        }

        return $letter;
    }
}
