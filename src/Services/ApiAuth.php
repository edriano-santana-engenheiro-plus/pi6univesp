<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

final class ApiAuth
{
    public function __construct(private PDO $pdo)
    {
    }

    public function issueToken(int $userId, int $days = 30): string
    {
        $token = bin2hex(random_bytes(32));
        $exp = (new \DateTimeImmutable("+{$days} days"))->format('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO api_tokens (user_id, token, expires_at) VALUES (?,?,?)')
            ->execute([$userId, $token, $exp]);
        return $token;
    }

    public function userFromToken(?string $token): ?array
    {
        if (!$token) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT u.* FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token=? AND u.active=1
               AND (t.expires_at IS NULL OR t.expires_at > NOW())
             LIMIT 1'
        );
        $stmt->execute([$token]);
        $u = $stmt->fetch();
        if ($u) {
            unset($u['password_hash']);
        }
        return $u ?: null;
    }
}
