<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

/** Marketplace: lojas (empresas) no lugar do singleton store_settings. */
final class StoreService
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
        $done = true;
        try {
            $exists = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'stores'"
            )->fetchColumn();
            if ($exists === 0) {
                $this->migrate();
            } else {
                $this->ensureStoreIdColumns();
                $this->ensureCityColumn();
            }
        } catch (\Throwable) {
            // DB indisponível — ignora
        }
    }

    /** Migração completa (idempotente). */
    public function migrate(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS stores (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              slug VARCHAR(80) NOT NULL,
              name VARCHAR(200) NOT NULL,
              phone VARCHAR(30) NULL,
              address TEXT NULL,
              lat DECIMAL(10,7) NULL,
              lng DECIMAL(10,7) NULL,
              open_now TINYINT(1) NOT NULL DEFAULT 1,
              delivery_fee DECIMAL(10,2) NOT NULL DEFAULT 5.00,
              fee_per_km DECIMAL(10,2) NOT NULL DEFAULT 2.50,
              max_delivery_km DECIMAL(6,2) NOT NULL DEFAULT 8.00,
              city VARCHAR(80) NOT NULL DEFAULT 'Itamogi',
              min_order DECIMAL(10,2) NOT NULL DEFAULT 20.00,
              whatsapp VARCHAR(30) NULL,
              logo_url VARCHAR(255) NULL,
              mp_access_token VARCHAR(255) NULL,
              active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              UNIQUE KEY uq_stores_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS store_users (
              id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
              store_id INT UNSIGNED NOT NULL,
              user_id INT UNSIGNED NOT NULL,
              role ENUM('store_admin','atendente') NOT NULL DEFAULT 'atendente',
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              UNIQUE KEY uq_store_user (store_id, user_id),
              KEY idx_store_users_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $n = (int) $this->pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
        if ($n === 0) {
            try {
                $ss = $this->pdo->query('SELECT * FROM store_settings WHERE id=1')->fetch() ?: null;
            } catch (\Throwable) {
                $ss = null;
            }
            if ($ss) {
                $this->pdo->prepare(
                    'INSERT INTO stores (id, slug, name, phone, address, lat, lng, open_now, delivery_fee, fee_per_km, max_delivery_km, min_order, whatsapp, active, updated_at)
                     VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())'
                )->execute([
                    'santana',
                    (string) ($ss['legal_name'] ?: 'Panificadora Santana'),
                    $ss['phone'] ?? null,
                    $ss['address'] ?? null,
                    $ss['lat'] ?? null,
                    $ss['lng'] ?? null,
                    (int) ($ss['open_now'] ?? 1),
                    (float) ($ss['delivery_fee'] ?? 5),
                    (float) ($ss['fee_per_km'] ?? 2.5),
                    (float) ($ss['max_delivery_km'] ?? 8),
                    (float) ($ss['min_order'] ?? 20),
                    $ss['whatsapp'] ?? null,
                ]);
            } else {
                $this->pdo->exec(
                    "INSERT INTO stores (id, slug, name, open_now, active) VALUES (1, 'santana', 'Panificadora Santana', 1, 1)"
                );
            }
        }

        $this->ensureStoreIdColumns();
        $this->ensureCityColumn();
        $this->ensurePaymentColumns();
        $this->ensurePaymentMethodsForAllStores();

        $this->pdo->exec('UPDATE categories SET store_id = 1 WHERE store_id IS NULL');
        $this->pdo->exec('UPDATE products SET store_id = 1 WHERE store_id IS NULL');
        $this->pdo->exec('UPDATE orders SET store_id = 1 WHERE store_id IS NULL');
        try {
            $this->pdo->exec('UPDATE payment_methods SET store_id = 1 WHERE store_id IS NULL');
        } catch (\Throwable) {
        }

        $stmt = $this->pdo->query(
            "SELECT id, role FROM users WHERE role IN ('admin','atendente') AND active=1"
        );
        $ins = $this->pdo->prepare(
            'INSERT IGNORE INTO store_users (store_id, user_id, role) VALUES (1,?,?)'
        );
        foreach ($stmt->fetchAll() ?: [] as $u) {
            $role = ((string) $u['role'] === 'admin') ? 'store_admin' : 'atendente';
            $ins->execute([(int) $u['id'], $role]);
        }
    }

    private function ensureStoreIdColumns(): void
    {
        $this->addColumnIfMissing('categories', 'store_id', 'INT UNSIGNED NULL AFTER id', 'idx_cat_store');
        $this->addColumnIfMissing('products', 'store_id', 'INT UNSIGNED NULL AFTER id', 'idx_prod_store');
        $this->addColumnIfMissing('orders', 'store_id', 'INT UNSIGNED NULL AFTER id', 'idx_ord_store');
        try {
            $t = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_methods'"
            )->fetchColumn();
            if ($t > 0) {
                $this->addColumnIfMissing('payment_methods', 'store_id', 'INT UNSIGNED NULL AFTER id', 'idx_pm_store');
            }
        } catch (\Throwable) {
        }
    }

    private function ensureCityColumn(): void
    {
        try {
            $c = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='stores' AND COLUMN_NAME='city'"
            )->fetchColumn();
            if ($c === 0) {
                $this->pdo->exec(
                    "ALTER TABLE stores ADD COLUMN city VARCHAR(80) NOT NULL DEFAULT 'Itamogi' AFTER max_delivery_km"
                );
            }
            // Preenche a partir do endereço quando ainda está no padrão genérico e o endereço cita a cidade
            $this->pdo->exec(
                "UPDATE stores SET city='Itamogi'
                 WHERE (city IS NULL OR city='' OR city='Itamogi')
                   AND address IS NOT NULL AND address LIKE '%Itamogi%'"
            );
        } catch (\Throwable) {
        }
    }

    /** Chave PIX / recebedor por loja (painel Pagamentos). */
    private function ensurePaymentColumns(): void
    {
        try {
            $this->addColumnNoIndex('stores', 'pix_key', 'VARCHAR(120) NULL');
            $this->addColumnNoIndex('stores', 'pix_merchant_name', 'VARCHAR(25) NULL');
            $this->addColumnNoIndex('stores', 'pix_merchant_city', 'VARCHAR(15) NULL');
        } catch (\Throwable) {
        }
    }

    /** Toda loja precisa das formas (PIX, dinheiro, máquina…). */
    private function ensurePaymentMethodsForAllStores(): void
    {
        try {
            $pay = new PaymentMethodService($this->pdo);
            $pay->ensureSchema();
            $ids = $this->pdo->query('SELECT id FROM stores WHERE active=1')->fetchAll(PDO::FETCH_COLUMN) ?: [];
            foreach ($ids as $id) {
                $pay->ensureMethodsForStore((int) $id);
            }
        } catch (\Throwable) {
        }
    }

    private function addColumnNoIndex(string $table, string $column, string $definition): void
    {
        $c = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $this->pdo->quote($table)
             . " AND COLUMN_NAME=" . $this->pdo->quote($column)
        )->fetchColumn();
        if ($c > 0) {
            return;
        }
        $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }

    private function addColumnIfMissing(string $table, string $column, string $definition, string $index): void
    {
        $c = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=" . $this->pdo->quote($table)
             . " AND COLUMN_NAME=" . $this->pdo->quote($column)
        )->fetchColumn();
        if ($c > 0) {
            return;
        }
        $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition, ADD KEY `$index` (`$column`)");
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stores WHERE id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findActive(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stores WHERE id=? AND active=1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array> */
    public function listActive(?string $q = null): array
    {
        if ($q !== null && trim($q) !== '') {
            $like = '%' . trim($q) . '%';
            $stmt = $this->pdo->prepare(
                "SELECT id, slug, name, phone, address, lat, lng, open_now,
                        delivery_fee, fee_per_km, max_delivery_km, min_order, whatsapp, logo_url
                 FROM stores
                 WHERE active=1 AND (name LIKE ? OR address LIKE ? OR slug LIKE ?)
                 ORDER BY name ASC
                 LIMIT 100"
            );
            $stmt->execute([$like, $like, $like]);
            return $stmt->fetchAll() ?: [];
        }
        return $this->pdo->query(
            "SELECT id, slug, name, phone, address, lat, lng, open_now,
                    delivery_fee, fee_per_km, max_delivery_km, min_order, whatsapp, logo_url
             FROM stores
             WHERE active=1
             ORDER BY name ASC
             LIMIT 100"
        )->fetchAll() ?: [];
    }

    /** @return list<array> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT * FROM stores ORDER BY active DESC, name ASC'
        )->fetchAll() ?: [];
    }

    /** @return list<int> */
    public function storeIdsForUser(int $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT store_id FROM store_users WHERE user_id=?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    public function userBelongsToStore(int $userId, int $storeId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM store_users WHERE user_id=? AND store_id=? LIMIT 1');
        $stmt->execute([$userId, $storeId]);
        return (bool) $stmt->fetchColumn();
    }

    /** API-friendly store payload (compatível com antigo store_settings). */
    public function toPublicArray(array $store): array
    {
        $logoSvc = new StoreLogoService();
        $logoAbs = $logoSvc->absoluteUrl($store['logo_url'] ?? null);
        return [
            'id' => (int) $store['id'],
            'slug' => (string) ($store['slug'] ?? ''),
            'name' => (string) ($store['name'] ?? ''),
            'legal_name' => (string) ($store['name'] ?? ''),
            'phone' => $store['phone'] ?? null,
            'address' => $store['address'] ?? null,
            'lat' => $store['lat'] ?? null,
            'lng' => $store['lng'] ?? null,
            'open_now' => (int) ($store['open_now'] ?? 0),
            'delivery_fee' => (float) ($store['delivery_fee'] ?? 0),
            'fee_per_km' => (float) ($store['fee_per_km'] ?? 0),
            'max_delivery_km' => (float) ($store['max_delivery_km'] ?? 0),
            'city' => (string) ($store['city'] ?? 'Itamogi'),
            'area_mode' => 'municipality',
            'min_order' => (float) ($store['min_order'] ?? 0),
            'whatsapp' => $store['whatsapp'] ?? null,
            'logo_url' => $logoAbs,
        ];
    }

    public function create(array $data): int
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Nome da loja é obrigatório.');
        }
        $slug = $this->uniqueSlug((string) ($data['slug'] ?? $name));
        $stmt = $this->pdo->prepare(
            'INSERT INTO stores (slug, name, phone, address, lat, lng, open_now, delivery_fee, fee_per_km, max_delivery_km, city, min_order, whatsapp, logo_url, mp_access_token, active, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
        );
        $stmt->execute([
            $slug,
            $name,
            $data['phone'] ?? null,
            $data['address'] ?? null,
            $data['lat'] ?? null,
            $data['lng'] ?? null,
            (int) ($data['open_now'] ?? 1),
            (float) ($data['delivery_fee'] ?? 5),
            (float) ($data['fee_per_km'] ?? 2.5),
            (float) ($data['max_delivery_km'] ?? 8),
            trim((string) ($data['city'] ?? 'Itamogi')) ?: 'Itamogi',
            (float) ($data['min_order'] ?? 20),
            $data['whatsapp'] ?? null,
            $data['logo_url'] ?? null,
            $data['mp_access_token'] ?? null,
            (int) ($data['active'] ?? 1),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $store = $this->find($id);
        if (!$store) {
            throw new \InvalidArgumentException('Loja não encontrada.');
        }
        $name = trim((string) ($data['name'] ?? $store['name']));
        if ($name === '') {
            throw new \InvalidArgumentException('Nome da loja é obrigatório.');
        }
        $slug = trim((string) ($data['slug'] ?? $store['slug']));
        if ($slug === '') {
            $slug = $this->uniqueSlug($name, $id);
        } else {
            $slug = $this->uniqueSlug($slug, $id);
        }
        $this->ensurePaymentColumns();
        $stmt = $this->pdo->prepare(
            'UPDATE stores SET slug=?, name=?, phone=?, address=?, lat=?, lng=?, open_now=?,
             delivery_fee=?, fee_per_km=?, max_delivery_km=?, city=?, min_order=?, whatsapp=?,
             logo_url=?, mp_access_token=?, pix_key=?, pix_merchant_name=?, pix_merchant_city=?,
             active=?, updated_at=NOW()
             WHERE id=?'
        );
        $stmt->execute([
            $slug,
            $name,
            $data['phone'] ?? $store['phone'],
            $data['address'] ?? $store['address'],
            array_key_exists('lat', $data) ? $data['lat'] : $store['lat'],
            array_key_exists('lng', $data) ? $data['lng'] : $store['lng'],
            (int) ($data['open_now'] ?? $store['open_now']),
            (float) ($data['delivery_fee'] ?? $store['delivery_fee']),
            (float) ($data['fee_per_km'] ?? $store['fee_per_km']),
            (float) ($data['max_delivery_km'] ?? $store['max_delivery_km'] ?? 8),
            trim((string) ($data['city'] ?? $store['city'] ?? 'Itamogi')) ?: 'Itamogi',
            (float) ($data['min_order'] ?? $store['min_order']),
            $data['whatsapp'] ?? $store['whatsapp'],
            array_key_exists('logo_url', $data) ? $data['logo_url'] : $store['logo_url'],
            array_key_exists('mp_access_token', $data) ? $data['mp_access_token'] : $store['mp_access_token'],
            array_key_exists('pix_key', $data) ? $data['pix_key'] : ($store['pix_key'] ?? null),
            array_key_exists('pix_merchant_name', $data) ? $data['pix_merchant_name'] : ($store['pix_merchant_name'] ?? null),
            array_key_exists('pix_merchant_city', $data) ? $data['pix_merchant_city'] : ($store['pix_merchant_city'] ?? null),
            (int) ($data['active'] ?? $store['active']),
            $id,
        ]);
        // Espelha loja 1 em store_settings para compatibilidade legado
        if ($id === 1) {
            $this->mirrorToLegacySettings($id);
        }
    }

    public function attachUser(int $storeId, int $userId, string $role = 'atendente'): void
    {
        if (!in_array($role, ['store_admin', 'atendente'], true)) {
            $role = 'atendente';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO store_users (store_id, user_id, role) VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE role=VALUES(role)'
        );
        $stmt->execute([$storeId, $userId, $role]);
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = strtolower(trim($base));
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?: 'loja';
        $slug = trim($slug, '-') ?: 'loja';
        $candidate = $slug;
        $n = 1;
        while (true) {
            $sql = 'SELECT id FROM stores WHERE slug=?';
            $params = [$candidate];
            if ($ignoreId !== null) {
                $sql .= ' AND id<>?';
                $params[] = $ignoreId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
            $n++;
            $candidate = $slug . '-' . $n;
        }
    }

    private function mirrorToLegacySettings(int $storeId): void
    {
        $s = $this->find($storeId);
        if (!$s) {
            return;
        }
        try {
            $this->pdo->prepare(
                'UPDATE store_settings SET legal_name=?, phone=?, address=?, lat=?, lng=?, open_now=?,
                 delivery_fee=?, fee_per_km=?, max_delivery_km=?, min_order=?, whatsapp=?, updated_at=NOW()
                 WHERE id=1'
            )->execute([
                $s['name'], $s['phone'], $s['address'], $s['lat'], $s['lng'], $s['open_now'],
                $s['delivery_fee'], $s['fee_per_km'], $s['max_delivery_km'], $s['min_order'], $s['whatsapp'],
            ]);
        } catch (\Throwable) {
            // tabela legada pode não existir em installs novos
        }
    }
}
