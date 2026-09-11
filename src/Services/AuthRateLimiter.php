<?php
declare(strict_types=1);

namespace Delivery\Services;

/**
 * Rate limit simples em arquivo (sem Redis) para login / forgot-password.
 */
final class AuthRateLimiter
{
    public function __construct(
        private string $storageDir,
        private int $maxAttempts = 5,
        private int $windowSeconds = 900,
    ) {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
    }

    public static function forDeliveryRoot(string $root): self
    {
        $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';
        return new self($dir);
    }

    /** Rastreio público: mais permissivo que login (mapa faz polling). */
    public static function forPublicTrack(string $root): self
    {
        $dir = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rate_limit';
        return new self($dir, 90, 60);
    }

    /**
     * @throws \RuntimeException quando excedido
     */
    public function assertAllowed(string $action, string $ip, string $email = ''): void
    {
        $key = hash('sha256', strtolower($action) . '|' . $ip . '|' . mb_strtolower(trim($email)));
        $path = $this->storageDir . DIRECTORY_SEPARATOR . $key . '.json';
        $now = time();
        $data = ['attempts' => [], 'blocked_until' => 0];
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
        }
        $blockedUntil = (int) ($data['blocked_until'] ?? 0);
        if ($blockedUntil > $now) {
            $wait = $blockedUntil - $now;
            throw new \RuntimeException(
                'Muitas tentativas. Aguarde ' . max(1, (int) ceil($wait / 60)) . ' min e tente de novo.'
            );
        }
        $windowStart = $now - $this->windowSeconds;
        $attempts = [];
        foreach (($data['attempts'] ?? []) as $ts) {
            $ts = (int) $ts;
            if ($ts >= $windowStart) {
                $attempts[] = $ts;
            }
        }
        if (count($attempts) >= $this->maxAttempts) {
            $data['blocked_until'] = $now + $this->windowSeconds;
            $data['attempts'] = $attempts;
            @file_put_contents($path, json_encode($data), LOCK_EX);
            throw new \RuntimeException(
                'Muitas tentativas. Aguarde ' . (int) ceil($this->windowSeconds / 60) . ' min e tente de novo.'
            );
        }
        $attempts[] = $now;
        $data['attempts'] = $attempts;
        $data['blocked_until'] = 0;
        @file_put_contents($path, json_encode($data), LOCK_EX);
    }

    public function clear(string $action, string $ip, string $email = ''): void
    {
        $key = hash('sha256', strtolower($action) . '|' . $ip . '|' . mb_strtolower(trim($email)));
        $path = $this->storageDir . DIRECTORY_SEPARATOR . $key . '.json';
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
