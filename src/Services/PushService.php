<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

/** Inbox in-app + tentativa de push FCM (legado) quando FCM_SERVER_KEY estiver no .env */
final class PushService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function tablesExist(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM app_notifications LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function registerDevice(int $userId, string $token, string $platform = 'android', ?string $appRole = null): void
    {
        if (!$this->tablesExist() || $token === '') {
            return;
        }
        $platform = in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'android';
        $appRole = in_array((string) $appRole, ['cliente', 'motoboy', 'admin'], true) ? $appRole : null;
        try {
            $cols = $this->pdo->query('SHOW COLUMNS FROM device_tokens LIKE \'app_role\'')->fetch();
            if ($cols) {
                $this->pdo->prepare(
                    'INSERT INTO device_tokens (user_id, token, platform, app_role, updated_at) VALUES (?,?,?,?,NOW())
                     ON DUPLICATE KEY UPDATE platform=VALUES(platform), app_role=VALUES(app_role), updated_at=NOW()'
                )->execute([$userId, $token, $platform, $appRole]);
                return;
            }
        } catch (\Throwable) {
        }
        $this->pdo->prepare(
            'INSERT INTO device_tokens (user_id, token, platform, updated_at) VALUES (?,?,?,NOW())
             ON DUPLICATE KEY UPDATE platform=VALUES(platform), updated_at=NOW()'
        )->execute([$userId, $token, $platform]);
    }

    /** @param array<string,mixed>|null $data */
    public function notifyUser(int $userId, string $title, string $body, ?array $data = null): void
    {
        if (!$this->tablesExist()) {
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO app_notifications (user_id, title, body, data_json, created_at) VALUES (?,?,?,?,NOW())'
        )->execute([
            $userId,
            mb_substr($title, 0, 160),
            mb_substr($body, 0, 500),
            $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
        ]);

        $tokens = $this->tokensFor($userId);
        foreach ($tokens as $tok) {
            $this->sendFcm($userId, $tok, $title, $body, $data);
        }
        $this->log($userId, 'inbox', ['title' => $title, 'body' => $body, 'data' => $data], 'saved');
    }

    /** Notifica sem bloquear a resposta HTTP (register_shutdown_function). */
    public function notifyOrderActorsDeferred(array $order, string $title, string $body): void
    {
        $pdo = $this->pdo;
        register_shutdown_function(static function () use ($pdo, $order, $title, $body): void {
            try {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                (new self($pdo))->notifyOrderActors($order, $title, $body);
            } catch (\Throwable) {
            }
        });
    }

    public function notifyOrderActors(array $order, string $title, string $body): void
    {
        $payload = [
            'order_id' => (int) ($order['id'] ?? 0),
            'code' => (string) ($order['code'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'type' => $this->notifTypeFromStatus((string) ($order['status'] ?? '')),
        ];
        $customerId = (int) ($order['customer_id'] ?? 0);
        if ($customerId > 0) {
            $this->notifyUser($customerId, $title, $body, $payload);
        }
        $motoId = (int) ($order['motoboy_id'] ?? 0);
        if ($motoId > 0 && $motoId !== $customerId) {
            $this->notifyUser($motoId, $title, $body, $payload);
        }
        // Admins/atendentes (painel)
        try {
            $admins = $this->pdo->query("SELECT id FROM users WHERE role IN ('admin','atendente') AND active=1")->fetchAll();
            foreach ($admins as $a) {
                $uid = (int) $a['id'];
                if ($uid === $customerId || $uid === $motoId) {
                    continue;
                }
                $this->notifyUser($uid, $title, $body, $payload);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Avisa motoboys ativos (fila "pronto").
     * Se $storeId > 0 e houver vínculos em store_users, notifica só da loja;
     * senão, notifica todos os motoboys ativos (compatível com lojas sem vínculo).
     */
    public function notifyActiveMotoboys(string $title, string $body, ?array $data = null, ?int $storeId = null): void
    {
        try {
            $rows = [];
            if ($storeId !== null && $storeId > 0) {
                $st = $this->pdo->prepare(
                    "SELECT DISTINCT u.id
                     FROM users u
                     INNER JOIN store_users su ON su.user_id = u.id AND su.store_id = ?
                     WHERE u.role='motoboy' AND u.active=1"
                );
                $st->execute([$storeId]);
                $rows = $st->fetchAll() ?: [];
            }
            if ($rows === []) {
                $rows = $this->pdo->query(
                    "SELECT id FROM users WHERE role='motoboy' AND active=1"
                )->fetchAll() ?: [];
            }
        } catch (\Throwable) {
            return;
        }
        foreach ($rows as $r) {
            $this->notifyUser((int) $r['id'], $title, $body, $data);
        }
    }

    public function notifyActiveMotoboysDeferred(string $title, string $body, ?array $data = null, ?int $storeId = null): void
    {
        $pdo = $this->pdo;
        register_shutdown_function(static function () use ($pdo, $title, $body, $data, $storeId): void {
            try {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                (new self($pdo))->notifyActiveMotoboys($title, $body, $data, $storeId);
            } catch (\Throwable) {
            }
        });
    }

    /** Notifica só o cliente do pedido (sem motoboy/admin). */
    public function notifyCustomerOnly(array $order, string $title, string $body): void
    {
        $customerId = (int) ($order['customer_id'] ?? 0);
        if ($customerId <= 0) {
            return;
        }
        $this->notifyUser($customerId, $title, $body, [
            'order_id' => (int) ($order['id'] ?? 0),
            'code' => (string) ($order['code'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'type' => 'logistics',
        ]);
    }

    private function notifTypeFromStatus(string $status): string
    {
        return match ($status) {
            'pago', 'aguardando_pagamento' => 'payment',
            'cancelado' => 'cancel',
            'saiu_entrega', 'entregue', 'preparando' => 'logistics',
            default => 'order',
        };
    }

    /** @return list<array> */
    public function listForUser(int $userId, int $limit = 40, bool $unreadOnly = false): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        $sql = 'SELECT * FROM app_notifications WHERE user_id=?';
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public function markRead(int $userId, ?int $notificationId = null): void
    {
        if (!$this->tablesExist()) {
            return;
        }
        if ($notificationId) {
            $this->pdo->prepare('UPDATE app_notifications SET read_at=NOW() WHERE id=? AND user_id=?')
                ->execute([$notificationId, $userId]);
            return;
        }
        $this->pdo->prepare('UPDATE app_notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')
            ->execute([$userId]);
    }

    /** @return list<string> */
    private function tokensFor(int $userId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT token FROM device_tokens WHERE user_id=?');
            $stmt->execute([$userId]);
            return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed>|null $data */
    private function sendFcm(int $userId, string $token, string $title, string $body, ?array $data): void
    {
        $key = trim((string) delivery_env('FCM_SERVER_KEY', ''));
        if ($key === '') {
            $this->log($userId, 'fcm_skip', ['token' => substr($token, 0, 12) . '…'], 'FCM_SERVER_KEY não configurada');
            return;
        }
        // API legada FCM (ainda útil para testes; prefira HTTP v1 em produção)
        $payload = [
            'to' => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'data' => array_map('strval', $data ?? []),
            'priority' => 'high',
        ];
        $ch = curl_init('https://fcm.googleapis.com/fcm/send');
        if ($ch === false) {
            return;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: key=' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 12,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->log($userId, 'fcm', $payload, 'HTTP ' . $code . ' ' . (is_string($raw) ? substr($raw, 0, 120) : ''));
    }

    /** @param array<string,mixed>|null $payload */
    private function log(?int $userId, string $channel, ?array $payload, string $result): void
    {
        try {
            $this->pdo->prepare(
                'INSERT INTO push_log (user_id, channel, payload_json, result, created_at) VALUES (?,?,?,?,NOW())'
            )->execute([
                $userId,
                $channel,
                $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                mb_substr($result, 0, 255),
            ]);
        } catch (\Throwable) {
        }
    }
}
