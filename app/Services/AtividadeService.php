<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use PDO;
use Throwable;

final class AtividadeService
{
    public const ONLINE_SEGUNDOS = 120;

    private PDO $db;
    private bool $schemaOk = false;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    public function ensureSchema(): void
    {
        if ($this->schemaOk) {
            return;
        }
        $this->db->exec(
            "CREATE TABLE IF NOT EXISTS login_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                usuario_id BIGINT UNSIGNED NOT NULL,
                session_token CHAR(64) NOT NULL,
                ip VARCHAR(45) NULL,
                user_agent VARCHAR(255) NULL,
                logged_in_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                logged_out_at DATETIME NULL,
                ended_reason ENUM('logout','expired','replaced','forced') NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uk_ls_token (session_token),
                KEY idx_ls_user_seen (usuario_id, last_seen_at),
                KEY idx_ls_open (logged_out_at, last_seen_at),
                CONSTRAINT fk_ls_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        $this->schemaOk = true;
    }

    public function abrirSessao(int $usuarioId, string $sessionId, ?string $ip, ?string $userAgent): int
    {
        $this->ensureSchema();
        $token = $this->token($sessionId);
        // Encerra sessões abertas anteriores do mesmo usuário.
        $this->db->prepare(
            "UPDATE login_sessions
             SET logged_out_at = NOW(), ended_reason = 'replaced'
             WHERE usuario_id = ? AND logged_out_at IS NULL"
        )->execute([$usuarioId]);

        $ins = $this->db->prepare(
            'INSERT INTO login_sessions (usuario_id, session_token, ip, user_agent, logged_in_at, last_seen_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())'
        );
        $ins->execute([
            $usuarioId,
            $token,
            $ip !== null && $ip !== '' ? mb_substr($ip, 0, 45) : null,
            $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 255) : null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function encerrarSessao(string $sessionId, string $motivo = 'logout'): void
    {
        $this->ensureSchema();
        $this->db->prepare(
            "UPDATE login_sessions
             SET logged_out_at = COALESCE(logged_out_at, NOW()),
                 ended_reason = COALESCE(ended_reason, ?),
                 last_seen_at = NOW()
             WHERE session_token = ? AND logged_out_at IS NULL"
        )->execute([$motivo, $this->token($sessionId)]);
    }

    public function heartbeat(int $usuarioId, string $sessionId): void
    {
        $this->ensureSchema();
        $this->expirarOrfas();
        $token = $this->token($sessionId);
        $upd = $this->db->prepare(
            'UPDATE login_sessions SET last_seen_at = NOW()
             WHERE session_token = ? AND usuario_id = ? AND logged_out_at IS NULL'
        );
        $upd->execute([$token, $usuarioId]);
        if ($upd->rowCount() === 0) {
            // Sessão PHP ainda válida, mas registro perdido — reabre.
            $this->abrirSessao($usuarioId, $sessionId, $this->clientIp(), $this->userAgent());
        }
    }

    public function expirarOrfas(): int
    {
        $this->ensureSchema();
        $st = $this->db->prepare(
            "UPDATE login_sessions
             SET logged_out_at = last_seen_at, ended_reason = 'expired'
             WHERE logged_out_at IS NULL
               AND last_seen_at < (NOW() - INTERVAL " . self::ONLINE_SEGUNDOS . ' SECOND)'
        );
        $st->execute();
        return $st->rowCount();
    }

    /** @return list<array<string,mixed>> */
    public function listarOnline(): array
    {
        $this->ensureSchema();
        $this->expirarOrfas();
        $sql = "SELECT u.id AS usuario_id, u.nome, u.email, u.avatar_path, u.status,
                       s.id AS sessao_id, s.ip, s.user_agent, s.logged_in_at, s.last_seen_at,
                       TIMESTAMPDIFF(SECOND, s.logged_in_at, s.last_seen_at) AS segundos_ativos
                FROM login_sessions s
                INNER JOIN usuarios u ON u.id = s.usuario_id
                WHERE s.logged_out_at IS NULL
                  AND s.last_seen_at >= (NOW() - INTERVAL " . self::ONLINE_SEGUNDOS . " SECOND)
                ORDER BY s.last_seen_at DESC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function listarUsuariosComPresenca(?string $busca = null): array
    {
        $this->ensureSchema();
        $this->expirarOrfas();
        $params = [];
        $where = '1=1';
        if ($busca !== null && trim($busca) !== '') {
            $where .= ' AND (u.nome LIKE :q OR u.email LIKE :q)';
            $params['q'] = '%' . trim($busca) . '%';
        }
        $sql = "SELECT u.id, u.nome, u.email, u.avatar_path, u.status, u.last_login_at,
                       (
                         SELECT MAX(s2.last_seen_at) FROM login_sessions s2
                         WHERE s2.usuario_id = u.id AND s2.logged_out_at IS NULL
                           AND s2.last_seen_at >= (NOW() - INTERVAL " . self::ONLINE_SEGUNDOS . " SECOND)
                       ) AS online_at,
                       (
                         SELECT s3.logged_in_at FROM login_sessions s3
                         WHERE s3.usuario_id = u.id
                         ORDER BY s3.id DESC LIMIT 1
                       ) AS ultimo_login,
                       (
                         SELECT s4.logged_out_at FROM login_sessions s4
                         WHERE s4.usuario_id = u.id
                         ORDER BY s4.id DESC LIMIT 1
                       ) AS ultimo_logout,
                       (
                         SELECT COUNT(*) FROM login_sessions s5
                         WHERE s5.usuario_id = u.id
                           AND s5.logged_in_at >= (NOW() - INTERVAL 30 DAY)
                       ) AS logins_30d
                FROM usuarios u
                WHERE {$where}
                ORDER BY (online_at IS NULL), online_at DESC, u.nome";
        $st = $this->db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string,mixed>> */
    public function historicoUsuario(int $usuarioId, int $limite = 100): array
    {
        $this->ensureSchema();
        $st = $this->db->prepare(
            "SELECT id, ip, user_agent, logged_in_at, last_seen_at, logged_out_at, ended_reason,
                    TIMESTAMPDIFF(SECOND, logged_in_at, COALESCE(logged_out_at, last_seen_at)) AS segundos
             FROM login_sessions
             WHERE usuario_id = ?
             ORDER BY id DESC
             LIMIT " . max(1, min(500, $limite))
        );
        $st->execute([$usuarioId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{
     *   resumo:array<string,int|float>,
     *   por_dia:list<array{dia:string,logins:int,minutos:int}>,
     *   por_hora:list<array{hora:int,logins:int}>,
     *   online:bool
     * }
     */
    public function dashboardUsuario(int $usuarioId, int $dias = 30): array
    {
        $this->ensureSchema();
        $this->expirarOrfas();
        $dias = max(7, min(90, $dias));

        $chk = $this->db->prepare(
            "SELECT 1 FROM login_sessions
             WHERE usuario_id = ? AND logged_out_at IS NULL
               AND last_seen_at >= (NOW() - INTERVAL " . self::ONLINE_SEGUNDOS . " SECOND)
             LIMIT 1"
        );
        $chk->execute([$usuarioId]);
        $online = (bool) $chk->fetchColumn();

        $resumoSt = $this->db->prepare(
            "SELECT COUNT(*) AS logins,
                    COALESCE(SUM(TIMESTAMPDIFF(SECOND, logged_in_at, COALESCE(logged_out_at, last_seen_at))), 0) AS segundos,
                    COALESCE(AVG(TIMESTAMPDIFF(SECOND, logged_in_at, COALESCE(logged_out_at, last_seen_at))), 0) AS media_segundos
             FROM login_sessions
             WHERE usuario_id = ? AND logged_in_at >= (NOW() - INTERVAL {$dias} DAY)"
        );
        $resumoSt->execute([$usuarioId]);
        $resumo = $resumoSt->fetch(PDO::FETCH_ASSOC) ?: ['logins' => 0, 'segundos' => 0, 'media_segundos' => 0];

        $porDiaSt = $this->db->prepare(
            "SELECT DATE(logged_in_at) AS dia,
                    COUNT(*) AS logins,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE, logged_in_at, COALESCE(logged_out_at, last_seen_at))), 0) AS minutos
             FROM login_sessions
             WHERE usuario_id = ? AND logged_in_at >= (NOW() - INTERVAL {$dias} DAY)
             GROUP BY DATE(logged_in_at)
             ORDER BY dia"
        );
        $porDiaSt->execute([$usuarioId]);
        $porDia = $porDiaSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $porHoraSt = $this->db->prepare(
            "SELECT HOUR(logged_in_at) AS hora, COUNT(*) AS logins
             FROM login_sessions
             WHERE usuario_id = ? AND logged_in_at >= (NOW() - INTERVAL {$dias} DAY)
             GROUP BY HOUR(logged_in_at)
             ORDER BY hora"
        );
        $porHoraSt->execute([$usuarioId]);
        $porHoraRaw = $porHoraSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $porHora = [];
        $map = [];
        foreach ($porHoraRaw as $row) {
            $map[(int) $row['hora']] = (int) $row['logins'];
        }
        for ($h = 0; $h < 24; $h++) {
            $porHora[] = ['hora' => $h, 'logins' => $map[$h] ?? 0];
        }

        return [
            'resumo' => [
                'logins' => (int) ($resumo['logins'] ?? 0),
                'minutos' => (int) floor(((int) ($resumo['segundos'] ?? 0)) / 60),
                'media_minutos' => round(((float) ($resumo['media_segundos'] ?? 0)) / 60, 1),
                'dias' => $dias,
            ],
            'por_dia' => array_map(static fn(array $r): array => [
                'dia' => (string) $r['dia'],
                'logins' => (int) $r['logins'],
                'minutos' => (int) $r['minutos'],
            ], $porDia),
            'por_hora' => $porHora,
            'online' => $online,
        ];
    }

    /** @return array{online:int,sessoes_hoje:int,usuarios_ativos_7d:int} */
    public function resumoGeral(): array
    {
        $this->ensureSchema();
        $this->expirarOrfas();
        $online = (int) $this->db->query(
            "SELECT COUNT(DISTINCT usuario_id) FROM login_sessions
             WHERE logged_out_at IS NULL
               AND last_seen_at >= (NOW() - INTERVAL " . self::ONLINE_SEGUNDOS . ' SECOND)'
        )->fetchColumn();
        $hoje = (int) $this->db->query(
            "SELECT COUNT(*) FROM login_sessions WHERE DATE(logged_in_at) = CURDATE()"
        )->fetchColumn();
        $ativos = (int) $this->db->query(
            "SELECT COUNT(DISTINCT usuario_id) FROM login_sessions
             WHERE logged_in_at >= (NOW() - INTERVAL 7 DAY)"
        )->fetchColumn();
        return [
            'online' => $online,
            'sessoes_hoje' => $hoje,
            'usuarios_ativos_7d' => $ativos,
        ];
    }

    private function token(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function clientIp(): ?string
    {
        $ip = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip !== '' ? $ip : null;
    }

    private function userAgent(): ?string
    {
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return $ua !== '' ? $ua : null;
    }
}
