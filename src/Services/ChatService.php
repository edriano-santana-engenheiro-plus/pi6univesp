<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;
use RuntimeException;

/** Chat por pedido entre motoboy atribuído e painel (admin/atendente). */
final class ChatService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $this->pdo->query('SELECT 1 FROM chat_messages LIMIT 1');
            $done = true;
            return;
        } catch (\Throwable) {
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS chat_messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id INT UNSIGNED NOT NULL,
                sender_id INT UNSIGNED NOT NULL,
                sender_role ENUM('admin','atendente','motoboy') NOT NULL,
                body VARCHAR(1000) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                read_at DATETIME NULL,
                PRIMARY KEY (id),
                KEY idx_chat_order_id (order_id, id),
                KEY idx_chat_unread (order_id, read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $done = true;
    }

    /** @param array<string,mixed> $user */
    public function assertCanAccess(array $user, array $order): void
    {
        $role = (string) ($user['role'] ?? '');
        if (in_array($role, ['admin', 'atendente'], true)) {
            return;
        }
        if ($role === 'motoboy') {
            $motoId = (int) ($order['motoboy_id'] ?? 0);
            if ($motoId > 0 && $motoId === (int) ($user['id'] ?? 0)) {
                return;
            }
            throw new RuntimeException('Pedido não atribuído a você');
        }
        throw new RuntimeException('Sem permissão para o chat');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listMessages(int $orderId, int $afterId = 0, int $limit = 100): array
    {
        $this->ensureSchema();
        $limit = max(1, min(200, $limit));
        if ($afterId > 0) {
            $st = $this->pdo->prepare(
                "SELECT m.id, m.order_id, m.sender_id, m.sender_role, m.body, m.created_at, m.read_at,
                        u.name AS sender_name
                 FROM chat_messages m
                 JOIN users u ON u.id = m.sender_id
                 WHERE m.order_id = ? AND m.id > ?
                 ORDER BY m.id ASC
                 LIMIT $limit"
            );
            $st->execute([$orderId, $afterId]);
        } else {
            // últimas N, depois ordena ASC para o cliente
            $st = $this->pdo->prepare(
                "SELECT * FROM (
                    SELECT m.id, m.order_id, m.sender_id, m.sender_role, m.body, m.created_at, m.read_at,
                           u.name AS sender_name
                    FROM chat_messages m
                    JOIN users u ON u.id = m.sender_id
                    WHERE m.order_id = ?
                    ORDER BY m.id DESC
                    LIMIT $limit
                 ) t ORDER BY id ASC"
            );
            $st->execute([$orderId]);
        }
        $rows = $st->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->formatRow($r);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function send(int $orderId, array $user, string $body, ?array $order = null): array
    {
        $this->ensureSchema();
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);
        if ($body === '') {
            throw new RuntimeException('Mensagem vazia');
        }
        if (mb_strlen($body) > 1000) {
            $body = mb_substr($body, 0, 1000);
        }
        $role = (string) ($user['role'] ?? '');
        if (!in_array($role, ['admin', 'atendente', 'motoboy'], true)) {
            throw new RuntimeException('Papel sem acesso ao chat');
        }
        $st = $this->pdo->prepare(
            'INSERT INTO chat_messages (order_id, sender_id, sender_role, body, created_at) VALUES (?,?,?,?,NOW())'
        );
        $st->execute([$orderId, (int) $user['id'], $role, $body]);
        $id = (int) $this->pdo->lastInsertId();
        $msg = $this->find($id);
        if ($msg === null) {
            throw new RuntimeException('Falha ao gravar mensagem');
        }

        $this->notifyPeers($orderId, $user, $body, $order);
        return $msg;
    }

    /** Marca como lidas as mensagens de terceiros neste pedido. */
    public function markRead(int $orderId, int $readerId): int
    {
        $this->ensureSchema();
        $st = $this->pdo->prepare(
            'UPDATE chat_messages SET read_at = NOW()
             WHERE order_id = ? AND sender_id <> ? AND read_at IS NULL'
        );
        $st->execute([$orderId, $readerId]);
        return $st->rowCount();
    }

    /**
     * Threads recentes para o painel (admin/atendente).
     *
     * @return list<array<string,mixed>>
     */
    public function listThreads(int $limit = 40, ?int $storeId = null): array
    {
        $this->ensureSchema();
        $limit = max(1, min(100, $limit));
        $storeSql = ($storeId !== null && $storeId > 0) ? ' AND o.store_id = ?' : '';
        $sql = "SELECT o.id AS order_id, o.code, o.status, o.motoboy_id,
                       u.name AS customer_name,
                       m.name AS motoboy_name,
                       lm.body AS last_body,
                       lm.created_at AS last_at,
                       lm.sender_role AS last_sender_role,
                       lm.sender_name AS last_sender_name,
                       (SELECT COUNT(*) FROM chat_messages cm
                        WHERE cm.order_id = o.id
                          AND cm.sender_role = 'motoboy'
                          AND cm.read_at IS NULL) AS unread_from_motoboy
                FROM orders o
                JOIN users u ON u.id = o.customer_id
                LEFT JOIN users m ON m.id = o.motoboy_id
                JOIN (
                    SELECT x.order_id, x.body, x.created_at, x.sender_role, us.name AS sender_name
                    FROM chat_messages x
                    JOIN users us ON us.id = x.sender_id
                    INNER JOIN (
                        SELECT order_id, MAX(id) AS max_id FROM chat_messages GROUP BY order_id
                    ) last ON last.max_id = x.id
                ) lm ON lm.order_id = o.id
                WHERE 1=1{$storeSql}
                ORDER BY lm.created_at DESC
                LIMIT $limit";
        try {
            if ($storeId !== null && $storeId > 0) {
                $st = $this->pdo->prepare($sql);
                $st->execute([$storeId]);
                $rows = $st->fetchAll() ?: [];
            } else {
                $rows = $this->pdo->query($sql)->fetchAll() ?: [];
            }
        } catch (\Throwable) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'order_id' => (int) $r['order_id'],
                'code' => (string) $r['code'],
                'status' => (string) $r['status'],
                'customer_name' => (string) ($r['customer_name'] ?? ''),
                'motoboy_id' => (int) ($r['motoboy_id'] ?? 0) ?: null,
                'motoboy_name' => (string) ($r['motoboy_name'] ?? ''),
                'last_body' => (string) ($r['last_body'] ?? ''),
                'last_at' => (string) ($r['last_at'] ?? ''),
                'last_sender_role' => (string) ($r['last_sender_role'] ?? ''),
                'last_sender_name' => (string) ($r['last_sender_name'] ?? ''),
                'unread_from_motoboy' => (int) ($r['unread_from_motoboy'] ?? 0),
            ];
        }
        return $out;
    }

    public function unreadFromMotoboyTotal(): int
    {
        $this->ensureSchema();
        try {
            return (int) $this->pdo->query(
                "SELECT COUNT(*) FROM chat_messages WHERE sender_role='motoboy' AND read_at IS NULL"
            )->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed>|null */
    private function find(int $id): ?array
    {
        $st = $this->pdo->prepare(
            "SELECT m.id, m.order_id, m.sender_id, m.sender_role, m.body, m.created_at, m.read_at,
                    u.name AS sender_name
             FROM chat_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.id = ? LIMIT 1"
        );
        $st->execute([$id]);
        $r = $st->fetch();
        return $r ? $this->formatRow($r) : null;
    }

    /** @param array<string,mixed> $r */
    private function formatRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'order_id' => (int) $r['order_id'],
            'sender_id' => (int) $r['sender_id'],
            'sender_role' => (string) $r['sender_role'],
            'sender_name' => (string) ($r['sender_name'] ?? ''),
            'body' => (string) $r['body'],
            'created_at' => (string) $r['created_at'],
            'read_at' => $r['read_at'] !== null ? (string) $r['read_at'] : null,
            'mine_role_group' => in_array((string) $r['sender_role'], ['admin', 'atendente'], true)
                ? 'painel'
                : 'motoboy',
        ];
    }

    /** @param array<string,mixed> $user */
    private function notifyPeers(int $orderId, array $user, string $body, ?array $order): void
    {
        try {
            $push = new PushService($this->pdo);
            if (!$push->tablesExist()) {
                return;
            }
            if ($order === null) {
                $st = $this->pdo->prepare('SELECT id, code, motoboy_id, status FROM orders WHERE id=? LIMIT 1');
                $st->execute([$orderId]);
                $order = $st->fetch() ?: [];
            }
            $code = (string) ($order['code'] ?? ('#' . $orderId));
            $preview = mb_strlen($body) > 80 ? mb_substr($body, 0, 77) . '…' : $body;
            $payload = [
                'type' => 'chat',
                'order_id' => $orderId,
                'code' => $code,
            ];
            $role = (string) ($user['role'] ?? '');
            $senderName = (string) ($user['name'] ?? 'Usuário');

            if ($role === 'motoboy') {
                $title = "Chat · $code";
                $admins = $this->pdo->query(
                    "SELECT id FROM users WHERE role IN ('admin','atendente') AND active=1"
                )->fetchAll() ?: [];
                foreach ($admins as $a) {
                    $push->notifyUser((int) $a['id'], $title, "$senderName: $preview", $payload);
                }
                return;
            }

            $motoId = (int) ($order['motoboy_id'] ?? 0);
            if ($motoId > 0) {
                $push->notifyUser($motoId, "Chat · $code", "Painel: $preview", $payload);
            }
        } catch (\Throwable) {
        }
    }
}
