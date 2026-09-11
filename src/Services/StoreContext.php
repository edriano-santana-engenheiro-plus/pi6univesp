<?php
declare(strict_types=1);

namespace Delivery\Services;

use Delivery\Utils\Auth;
use PDO;

/** Contexto da loja ativa no painel (sessão). */
final class StoreContext
{
    public const SESSION_KEY = 'delivery_store_id';

    public function __construct(
        private PDO $pdo,
        private StoreService $stores,
    ) {
    }

    public function isPlatformAdmin(): bool
    {
        $role = (string) (Auth::user()['role'] ?? '');
        return $role === 'admin';
    }

    /** @return list<array> lojas acessíveis ao usuário logado */
    public function accessibleStores(): array
    {
        $user = Auth::user();
        if (!$user) {
            return [];
        }
        if ($this->isPlatformAdmin()) {
            return $this->stores->listAll();
        }
        $ids = $this->stores->storeIdsForUser((int) $user['id']);
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM stores WHERE id IN ($in) ORDER BY name");
        $stmt->execute($ids);
        return $stmt->fetchAll() ?: [];
    }

    public function currentStoreId(): ?int
    {
        $accessible = $this->accessibleStores();
        if ($accessible === []) {
            return null;
        }
        $ids = array_map(static fn ($s) => (int) $s['id'], $accessible);
        $sid = isset($_SESSION[self::SESSION_KEY]) ? (int) $_SESSION[self::SESSION_KEY] : 0;
        if ($sid > 0 && in_array($sid, $ids, true)) {
            return $sid;
        }
        $first = $ids[0];
        $_SESSION[self::SESSION_KEY] = $first;
        return $first;
    }

    public function currentStore(): ?array
    {
        $id = $this->currentStoreId();
        return $id ? $this->stores->find($id) : null;
    }

    public function setCurrentStoreId(int $storeId): bool
    {
        foreach ($this->accessibleStores() as $s) {
            if ((int) $s['id'] === $storeId) {
                $_SESSION[self::SESSION_KEY] = $storeId;
                return true;
            }
        }
        return false;
    }

    public function requireStoreId(): int
    {
        $id = $this->currentStoreId();
        if ($id === null) {
            http_response_code(403);
            echo 'Nenhuma loja vinculada ao seu usuário. Peça ao administrador da plataforma.';
            exit;
        }
        return $id;
    }

    public function userCanAccessStoreId(int $storeId): bool
    {
        if ($storeId <= 0) {
            return false;
        }
        if ($this->isPlatformAdmin()) {
            return true;
        }
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $this->stores->userBelongsToStore((int) $user['id'], $storeId);
    }

    /** @param array<string,mixed> $order */
    public function assertCanAccessOrder(array $order): void
    {
        $sid = (int) ($order['store_id'] ?? 0);
        if (!$this->userCanAccessStoreId($sid)) {
            http_response_code(403);
            echo 'Pedido de outra loja. Sem permissão.';
            exit;
        }
    }
}
