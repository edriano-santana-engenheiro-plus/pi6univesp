<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

final class OrderEventService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function tablesExist(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM order_events LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    public function add(int $orderId, string $type, string $message, ?array $meta = null): void
    {
        if (!$this->tablesExist()) {
            return;
        }
        $this->pdo->prepare(
            'INSERT INTO order_events (order_id, event_type, message, meta_json, created_at) VALUES (?,?,?,?,NOW())'
        )->execute([
            $orderId,
            $type,
            $message,
            $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /** @return list<array> */
    public function forOrder(int $orderId, int $limit = 50): array
    {
        if (!$this->tablesExist()) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, event_type, message, meta_json, created_at
             FROM order_events WHERE order_id=? ORDER BY id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$orderId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            if (!empty($r['meta_json']) && is_string($r['meta_json'])) {
                $r['meta'] = json_decode($r['meta_json'], true);
            }
        }
        return array_reverse($rows);
    }
}
