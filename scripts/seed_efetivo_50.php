<?php

declare(strict_types=1);

/**
 * Gera efetivo de 50 colaboradores ativos, todos na jornada/escala SEG-SEX (ADM-44H).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Helpers\Db;

$pdo = Db::tryConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "MySQL indisponível.\n");
    exit(1);
}

function cpfValido(int $seed): string
{
    $base = str_pad((string) (abs($seed) % 1000000000), 9, '0', STR_PAD_LEFT);
    $n = array_map('intval', str_split($base));
    if (count(array_unique($n)) === 1) {
        $n[8] = ($n[8] + 1) % 10;
    }

    $s = 0;
    for ($i = 0, $w = 10; $i < 9; $i++, $w--) {
        $s += $n[$i] * $w;
    }
    $d1 = ($s * 10) % 11;
    $d1 = $d1 === 10 ? 0 : $d1;

    $s = 0;
    $digits = array_merge($n, [$d1]);
    for ($i = 0, $w = 11; $i < 10; $i++, $w--) {
        $s += $digits[$i] * $w;
    }
    $d2 = ($s * 10) % 11;
    $d2 = $d2 === 10 ? 0 : $d2;

    return implode('', $n) . $d1 . $d2;
}

$nomes = [
    'Ana Paula Costa', 'Bruno Almeida', 'Camila Rocha', 'Diego Martins', 'Elisa Fernandes',
    'Fabio Nunes', 'Gabriela Lopes', 'Henrique Dias', 'Isabela Castro', 'Joao Pedro Ramos',
    'Karina Mendes', 'Lucas Barbosa', 'Marina Teixeira', 'Nicolas Azevedo', 'Olivia Cardoso',
    'Paulo Henrique Lima', 'Queila Souza', 'Rafael Pinto', 'Sabrina Duarte', 'Thiago Moreira',
    'Ursula Freitas', 'Vitor Hugo Reis', 'Wesley Campos', 'Yasmin Oliveira', 'Zeca Antunes',
    'Amanda Ribeiro', 'Bernardo Cunha', 'Carolina Vieira', 'Daniel Prado', 'Eduarda Sales',
    'Felipe Monteiro', 'Giovana Pires', 'Hugo Carvalho', 'Ingrid Batista', 'Jorge Farias',
    'Kelly Andrade', 'Leandro Gomes', 'Monica Xavier', 'Nathan Correia', 'Patricia Melo',
    'Renato Borges', 'Simone Aguiar', 'Tulio Peixoto', 'Vanessa Brito', 'Wagner Moura',
    'Aline Siqueira', 'Caio Fonseca', 'Debora Guimaraes', 'Everton Barros', 'Fernanda Tavares',
];

$cargos = ['Assistente Administrativo', 'Analista', 'Auxiliar', 'Coordenador', 'Assistente'];
$funcoes = ['Administrativo', 'Suporte', 'Planejamento', 'Controles', 'Documentação'];

$lotacaoId = (int) $pdo->query(
    "SELECT id FROM rh_lotacoes
     WHERE jornada_id = (SELECT id FROM rh_jornadas WHERE codigo = 'ADM-44H' LIMIT 1)
       AND escala_id = (SELECT id FROM rh_escalas WHERE codigo = 'SEG-SEX' LIMIT 1)
       AND status = 'ativo'
     ORDER BY id LIMIT 1"
)->fetchColumn();

if ($lotacaoId <= 0) {
    fwrite(STDERR, "Lotação SEG-SEX / ADM-44H não encontrada.\n");
    exit(1);
}

$empresaId = (int) $pdo->query("SELECT id FROM rh_empresas WHERE status='ativo' ORDER BY id LIMIT 1")->fetchColumn();
if ($empresaId <= 0) {
    fwrite(STDERR, "Empresa ativa não encontrada.\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    // Desativa quem não for do pacote 20001-20050
    $pdo->exec("UPDATE rh_colaboradores SET status='inativo' WHERE matricula NOT LIKE '2____' OR matricula < '20001' OR matricula > '20050'");

    $insColab = $pdo->prepare(
        'INSERT INTO rh_colaboradores
         (empresa_id, matricula, nome, cpf, email_corp, telefone, data_admissao, cargo, funcao, status)
         VALUES
         (:empresa_id, :matricula, :nome, :cpf, :email_corp, :telefone, :data_admissao, :cargo, :funcao, \'ativo\')
         ON DUPLICATE KEY UPDATE
           nome = VALUES(nome),
           cpf = VALUES(cpf),
           email_corp = VALUES(email_corp),
           telefone = VALUES(telefone),
           data_admissao = VALUES(data_admissao),
           cargo = VALUES(cargo),
           funcao = VALUES(funcao),
           status = \'ativo\''
    );

    $findByMat = $pdo->prepare('SELECT id FROM rh_colaboradores WHERE empresa_id = :e AND matricula = :m LIMIT 1');
    $closeOpen = $pdo->prepare(
        'UPDATE rh_colaborador_lotacoes
         SET vigencia_fim = DATE_SUB(:d, INTERVAL 1 DAY)
         WHERE colaborador_id = :c AND vigencia_fim IS NULL'
    );
    $insLot = $pdo->prepare(
        'INSERT INTO rh_colaborador_lotacoes
         (colaborador_id, lotacao_id, vigencia_inicio, vigencia_fim, escala_ancora_data, motivo, observacao, usuario_id)
         VALUES (:c, :l, :d, NULL, :a, \'admissao\', \'Efetivo SEG-SEX 50\', 1)'
    );

    $criados = 0;
    for ($i = 0; $i < 50; $i++) {
        $seq = $i + 1;
        $matricula = sprintf('2%04d', $seq);
        $nome = $nomes[$i];
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '.', $nome) ?: ('colab' . $seq));
        $slug = trim($slug, '.');
        $email = $slug . '@arcadis.local';
        $cpf = cpfValido(310000000 + ($seq * 137) + 17);
        $telefone = sprintf('9499%07d', 1000000 + $seq);
        $mes = (($seq - 1) % 10) + 1;
        $dia = (($seq - 1) % 27) + 1;
        $admissao = sprintf('2026-%02d-%02d', $mes, $dia);
        $cargo = $cargos[$i % count($cargos)];
        $funcao = $funcoes[$i % count($funcoes)];

        $insColab->execute([
            'empresa_id' => $empresaId,
            'matricula' => $matricula,
            'nome' => $nome,
            'cpf' => $cpf,
            'email_corp' => $email,
            'telefone' => $telefone,
            'data_admissao' => $admissao,
            'cargo' => $cargo,
            'funcao' => $funcao,
        ]);

        $findByMat->execute(['e' => $empresaId, 'm' => $matricula]);
        $colabId = (int) $findByMat->fetchColumn();
        if ($colabId <= 0) {
            throw new RuntimeException("Falha ao obter colaborador {$matricula}");
        }

        $closeOpen->execute(['d' => $admissao, 'c' => $colabId]);
        $insLot->execute([
            'c' => $colabId,
            'l' => $lotacaoId,
            'd' => $admissao,
            'a' => $admissao,
        ]);
        $criados++;
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}

$naSegSex = (int) $pdo->query(
    "SELECT COUNT(*) FROM rh_colaboradores c
     INNER JOIN rh_colaborador_lotacoes cl ON cl.colaborador_id = c.id AND cl.vigencia_fim IS NULL
     INNER JOIN rh_lotacoes l ON l.id = cl.lotacao_id
     INNER JOIN rh_jornadas j ON j.id = l.jornada_id
     INNER JOIN rh_escalas e ON e.id = l.escala_id
     WHERE c.status = 'ativo' AND j.codigo = 'ADM-44H' AND e.codigo = 'SEG-SEX'"
)->fetchColumn();
$ativos = (int) $pdo->query("SELECT COUNT(*) FROM rh_colaboradores WHERE status='ativo'")->fetchColumn();

echo "Lotação SEG-SEX id={$lotacaoId}\n";
echo "Processados={$criados}\n";
echo "Ativos={$ativos}\n";
echo "Ativos SEG-SEX/ADM-44H={$naSegSex}\n";
