<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

final class TrackingService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function push(int $orderId, int $motoboyId, float $lat, float $lng, ?float $speed = null, ?float $heading = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO delivery_tracking (order_id, motoboy_id, lat, lng, speed_kmh, heading, recorded_at)
             VALUES (?,?,?,?,?,?,NOW())'
        )->execute([$orderId, $motoboyId, $lat, $lng, $speed, $heading]);

        // Não sobrescreve delivery_lat/lng (destino do cliente)
        $this->pdo->prepare('UPDATE orders SET updated_at=NOW() WHERE id=?')->execute([$orderId]);
    }

    public function latest(int $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM delivery_tracking WHERE order_id=? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function history(int $orderId, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT lat, lng, speed_kmh, heading, recorded_at FROM delivery_tracking
             WHERE order_id=? ORDER BY id DESC LIMIT ' . (int) $limit
        );
        $stmt->execute([$orderId]);
        return array_reverse($stmt->fetchAll());
    }

    /** Apaga o percurso GPS de um pedido. @return int linhas removidas */
    public function clearOrder(int $orderId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM delivery_tracking WHERE order_id=?');
        $stmt->execute([$orderId]);
        return $stmt->rowCount();
    }

    /**
     * Apaga rotas de pedidos já finalizados (entregue/cancelado).
     * @return int linhas removidas
     */
    public function clearFinished(?int $olderThanDays = null): int
    {
        $sql = "DELETE t FROM delivery_tracking t
                INNER JOIN orders o ON o.id = t.order_id
                WHERE o.status IN ('entregue', 'cancelado')";
        $params = [];
        if ($olderThanDays !== null && $olderThanDays > 0) {
            $sql .= ' AND t.recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY)';
            $params[] = $olderThanDays;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function countForOrder(int $orderId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM delivery_tracking WHERE order_id=?');
        $stmt->execute([$orderId]);
        return (int) $stmt->fetchColumn();
    }
}
