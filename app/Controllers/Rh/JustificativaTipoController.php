<?php

declare(strict_types=1);

namespace App\Controllers\Rh;

use App\Helpers\Auth;
use App\Helpers\Csrf;
use App\Helpers\Db;
use App\Helpers\View;
use App\Services\CalculoPontoService;
use PDO;
use RuntimeException;
use Throwable;

final class JustificativaTipoController
{
    public function index(): void
    {
        Auth::requirePermission('ponto.ver');
        $pdo = Db::tryConnection();
        $tipos = [];
        $erro = null;
        if ($pdo instanceof PDO) {
            try {
                $this->ensureSeed($pdo);
                $tipos = $pdo->query(
                    "SELECT t.*,
                            (SELECT COUNT(*) FROM ponto_justificativas j WHERE j.tipo_id = t.id) AS usos
                     FROM ponto_justificativa_tipos t
                     ORDER BY t.status ASC, t.nome ASC"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable $e) {
                $erro = $e->getMessage();
            }
        }

        $podeEditar = Auth::can('ponto.editar');
        $topbarActions = '';
        if ($podeEditar) {
            $topbarActions = '<a class="btn btn-primary btn-sm" href="#novoTipo">Novo tipo</a>';
        }

        View::render('rh/ponto/justificativa_tipos', [
            'pageTitle' => 'Tipos de justificativa',
            'pageSubtitle' => 'Definem se a justificativa abona falta no cartão',
            'activeMenu' => 'justificativa-tipos',
            'tipos' => $tipos,
            'podeEditar' => $podeEditar,
            'topbarActions' => $topbarActions,
            'flashOk' => $_GET['ok'] ?? null,
            'flashErro' => $_GET['erro'] ?? $erro,
            'dbOk' => $pdo instanceof PDO,
            'editId' => (int) ($_GET['editar'] ?? 0),
        ]);
    }

    public function store(): void
    {
        Auth::requirePermission('ponto.editar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            $this->redirect(null, 'Banco indisponível.');
        }
        try {
            $data = $this->validar($_POST);
            $pdo->prepare(
                'INSERT INTO ponto_justificativa_tipos (codigo, nome, abona_falta, status)
                 VALUES (:c, :n, :a, :st)'
            )->execute([
                'c' => $data['codigo'],
                'n' => $data['nome'],
                'a' => $data['abona_falta'],
                'st' => $data['status'],
            ]);
            $this->redirect('Tipo cadastrado.');
        } catch (Throwable $e) {
            $this->redirect(null, $e->getMessage());
        }
    }

    public function update(string $id = '0'): void
    {
        Auth::requirePermission('ponto.editar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $tipoId = (int) $id;
        if (!$pdo instanceof PDO || $tipoId <= 0) {
            $this->redirect(null, 'Tipo inválido.');
        }
        try {
            $antes = $pdo->prepare('SELECT abona_falta, status FROM ponto_justificativa_tipos WHERE id = :id LIMIT 1');
            $antes->execute(['id' => $tipoId]);
            $prev = $antes->fetch(PDO::FETCH_ASSOC);
            if ($prev === false) {
                throw new RuntimeException('Tipo não encontrado.');
            }

            $data = $this->validar($_POST, $tipoId);
            $pdo->prepare(
                'UPDATE ponto_justificativa_tipos
                 SET codigo = :c, nome = :n, abona_falta = :a, status = :st
                 WHERE id = :id'
            )->execute([
                'c' => $data['codigo'],
                'n' => $data['nome'],
                'a' => $data['abona_falta'],
                'st' => $data['status'],
                'id' => $tipoId,
            ]);

            $mudouEfeito = (int) ($prev['abona_falta'] ?? 0) !== (int) $data['abona_falta']
                || (string) ($prev['status'] ?? '') !== (string) $data['status'];
            if ($mudouEfeito) {
                $this->recalcularEspelhosAfetados($pdo, $tipoId);
            }
            $this->redirect('Tipo atualizado.');
        } catch (Throwable $e) {
            $this->redirect(null, $e->getMessage(), $tipoId);
        }
    }

    public function excluir(string $id = '0'): void
    {
        Auth::requirePermission('ponto.editar');
        Csrf::requireValid();
        $pdo = Db::tryConnection();
        $tipoId = (int) $id;
        if (!$pdo instanceof PDO || $tipoId <= 0) {
            $this->redirect(null, 'Tipo inválido.');
        }
        try {
            $st = $pdo->prepare('SELECT id, codigo, nome FROM ponto_justificativa_tipos WHERE id = :id LIMIT 1');
            $st->execute(['id' => $tipoId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new RuntimeException('Tipo não encontrado.');
            }
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM ponto_justificativas WHERE tipo_id = :id');
            $cnt->execute(['id' => $tipoId]);
            $usos = (int) $cnt->fetchColumn();

            if ($usos > 0) {
                $pdo->prepare(
                    "UPDATE ponto_justificativa_tipos SET status = 'inativo' WHERE id = :id"
                )->execute(['id' => $tipoId]);
                $this->recalcularEspelhosAfetados($pdo, $tipoId);
                $this->redirect('Tipo desativado (já usado em ' . $usos . ' justificativa(s)).');
            }

            $pdo->prepare('DELETE FROM ponto_justificativa_tipos WHERE id = :id')->execute(['id' => $tipoId]);
            $this->redirect('Tipo excluído.');
        } catch (Throwable $e) {
            $this->redirect(null, $e->getMessage());
        }
    }

    /** Recalcula cartões abertos que usam o tipo (efeito de abono no ponto). */
    private function recalcularEspelhosAfetados(PDO $pdo, int $tipoId): void
    {
        $st = $pdo->prepare(
            "SELECT DISTINCT j.espelho_id
             FROM ponto_justificativas j
             INNER JOIN ponto_espelhos e ON e.id = j.espelho_id
             WHERE j.tipo_id = :t
               AND e.status NOT IN ('fechado','enviado','assinado')"
        );
        $st->execute(['t' => $tipoId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if ($ids === []) {
            return;
        }
        $calc = new CalculoPontoService($pdo);
        foreach ($ids as $espelhoId) {
            try {
                $calc->recalcularEspelho($espelhoId);
            } catch (Throwable) {
            }
        }
    }

    /** @param array<string,mixed> $post */
    private function validar(array $post, ?int $excetoId = null): array
    {
        $codigo = strtolower(trim((string) ($post['codigo'] ?? '')));
        $codigo = preg_replace('/[^a-z0-9_]+/', '_', $codigo) ?? '';
        $codigo = trim($codigo, '_');
        $nome = trim((string) ($post['nome'] ?? ''));
        $status = (($post['status'] ?? 'ativo') === 'inativo') ? 'inativo' : 'ativo';
        $abona = !empty($post['abona_falta']) ? 1 : 0;

        if ($codigo === '' || mb_strlen($codigo) < 2) {
            throw new RuntimeException('Informe um código válido (letras, números e _).');
        }
        if ($nome === '' || mb_strlen($nome) < 2) {
            throw new RuntimeException('Informe o nome do tipo.');
        }

        $pdo = Db::tryConnection();
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Banco indisponível.');
        }
        $sql = 'SELECT id FROM ponto_justificativa_tipos WHERE codigo = :c';
        $params = ['c' => $codigo];
        if ($excetoId !== null && $excetoId > 0) {
            $sql .= ' AND id <> :id';
            $params['id'] = $excetoId;
        }
        $sql .= ' LIMIT 1';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        if ($st->fetchColumn()) {
            throw new RuntimeException('Já existe um tipo com este código.');
        }

        return [
            'codigo' => mb_substr($codigo, 0, 40),
            'nome' => mb_substr($nome, 0, 120),
            'abona_falta' => $abona,
            'status' => $status,
        ];
    }

    private function ensureSeed(PDO $pdo): void
    {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM ponto_justificativa_tipos')->fetchColumn();
        if ($n > 0) {
            return;
        }
        $pdo->exec(
            "INSERT IGNORE INTO ponto_justificativa_tipos (codigo, nome, abona_falta, status) VALUES
             ('atestado', 'Atestado médico', 1, 'ativo'),
             ('ferias', 'Férias', 1, 'ativo'),
             ('folga_compensada', 'Folga compensada', 1, 'ativo'),
             ('outros', 'Outros', 0, 'ativo')"
        );
    }

    private function redirect(?string $ok = null, ?string $erro = null, ?int $editar = null): void
    {
        $qs = [];
        if ($ok !== null) {
            $qs['ok'] = $ok;
        }
        if ($erro !== null) {
            $qs['erro'] = $erro;
        }
        if ($editar !== null && $editar > 0) {
            $qs['editar'] = (string) $editar;
        }
        header('Location: /rh/ponto/justificativa-tipos' . ($qs !== [] ? '?' . http_build_query($qs) : ''));
        exit;
    }
}
