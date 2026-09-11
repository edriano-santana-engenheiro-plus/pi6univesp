<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

/** Resolve loja ativa na loja online (/pedir). */
final class ShopStoreResolver
{
    public function __construct(
        private PDO $pdo,
        private StoreService $stores,
    ) {
        $this->stores->ensureSchema();
    }

    public function fromRequest(): ?array
    {
        $slug = trim((string) ($_GET['loja'] ?? $_GET['store'] ?? ''));
        $id = (int) ($_GET['store_id'] ?? 0);
        if ($slug !== '') {
            $st = $this->pdo->prepare('SELECT * FROM stores WHERE (slug=? OR id=?) AND active=1 LIMIT 1');
            $st->execute([$slug, ctype_digit($slug) ? (int) $slug : 0]);
            $row = $st->fetch();
            return $row ?: null;
        }
        if ($id > 0) {
            return $this->stores->findActive($id);
        }
        return null;
    }

    /** Compatível com templates antigos (legal_name). */
    public function toShopArray(array $store): array
    {
        $store['legal_name'] = (string) ($store['name'] ?? '');
        return $store;
    }

    public function shopPath(array $store, string $suffix = ''): string
    {
        $slug = (string) ($store['slug'] ?? $store['id'] ?? '');
        $base = '/pedir?loja=' . rawurlencode($slug);
        if ($suffix !== '' && str_starts_with($suffix, '#')) {
            return $base . $suffix;
        }
        if ($suffix !== '' && str_starts_with($suffix, '?')) {
            return '/pedir?loja=' . rawurlencode($slug) . '&' . ltrim($suffix, '?');
        }
        return $base . $suffix;
    }
}
