<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;
use PDOException;

/** Formas de pagamento cadastráveis (PIX, cartões, dinheiro e customizadas). */
final class PaymentMethodService
{
    private bool $ready = false;

    public function __construct(private PDO $pdo)
    {
    }

    public function ensureSchema(): void
    {
        if ($this->ready) {
            return;
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS payment_methods (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                type ENUM('online_pix','online_card','on_delivery','other') NOT NULL DEFAULT 'other',
                instructions VARCHAR(255) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_pay_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_method'")->fetch();
            if ($col && stripos((string) ($col['Type'] ?? ''), 'enum') !== false) {
                $this->pdo->exec(
                    "ALTER TABLE orders MODIFY COLUMN payment_method VARCHAR(40) NOT NULL DEFAULT 'pix'"
                );
            }
        } catch (PDOException) {
            // ambiente sem permissão ALTER — segue com ENUM legado
        }
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM payment_methods')->fetchColumn();
        if ($count === 0) {
            $seed = $this->pdo->prepare(
                'INSERT INTO payment_methods (code, name, type, instructions, sort_order, active) VALUES (?,?,?,?,?,1)'
            );
            foreach ($this->defaultMethods() as $m) {
                $seed->execute([
                    $m['code'], $m['name'], $m['type'], $m['instructions'], $m['sort_order'],
                ]);
            }
        }
        $this->relaxCodeUniqueForMultistore();
        $this->ready = true;
    }

    /** Multiloja: código único por loja, não global. */
    private function relaxCodeUniqueForMultistore(): void
    {
        if (!$this->hasStoreIdColumn()) {
            return;
        }
        try {
            $this->pdo->exec('ALTER TABLE payment_methods DROP INDEX uq_pay_code');
        } catch (\Throwable) {
        }
        try {
            $this->pdo->exec(
                'ALTER TABLE payment_methods ADD UNIQUE KEY uq_pay_store_code (store_id, code)'
            );
        } catch (\Throwable) {
        }
    }

    /** @return list<array{code:string,name:string,type:string,instructions:?string,sort_order:int}> */
    private function defaultMethods(): array
    {
        return [
            [
                'code' => 'pix',
                'name' => 'PIX',
                'type' => 'online_pix',
                'instructions' => 'Pague pelo QR Code / copia e cola após finalizar o pedido.',
                'sort_order' => 10,
            ],
            [
                'code' => 'cartao_credito',
                'name' => 'Cartão de crédito',
                'type' => 'online_card',
                'instructions' => 'Cobrança online na confirmação do pedido.',
                'sort_order' => 20,
            ],
            [
                'code' => 'cartao_debito',
                'name' => 'Cartão de débito',
                'type' => 'online_card',
                'instructions' => 'Cobrança online na confirmação do pedido.',
                'sort_order' => 30,
            ],
            [
                'code' => 'dinheiro',
                'name' => 'Dinheiro na entrega',
                'type' => 'on_delivery',
                'instructions' => 'Pague ao motoboy. Informe o troco nas observações, se precisar.',
                'sort_order' => 40,
            ],
            [
                'code' => 'cartao_maquina',
                'name' => 'Cartão na entrega (máquina)',
                'type' => 'on_delivery',
                'instructions' => 'Crédito ou débito na maquininha do entregador.',
                'sort_order' => 50,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function listActive(?int $storeId = null): array
    {
        $this->ensureSchema();
        if ($storeId !== null && $storeId > 0 && $this->hasStoreIdColumn()) {
            $st = $this->pdo->prepare(
                'SELECT id, code, name, type, instructions, sort_order
                 FROM payment_methods
                 WHERE active=1 AND store_id=?
                 ORDER BY sort_order, name'
            );
            $st->execute([$storeId]);
            $rows = $st->fetchAll() ?: [];
            if ($rows !== []) {
                return $rows;
            }
            // Loja nova sem cópia das formas: usa globais / loja 1 e espelha
            $this->ensureMethodsForStore($storeId);
            $st->execute([$storeId]);
            $rows = $st->fetchAll() ?: [];
            if ($rows !== []) {
                return $rows;
            }
            // Fallback leitura (sem store_id ou loja 1)
            $st2 = $this->pdo->prepare(
                'SELECT id, code, name, type, instructions, sort_order
                 FROM payment_methods
                 WHERE active=1 AND (store_id IS NULL OR store_id=1)
                 ORDER BY sort_order, name'
            );
            $st2->execute();
            return $st2->fetchAll() ?: [];
        }
        return $this->pdo->query(
            'SELECT id, code, name, type, instructions, sort_order FROM payment_methods WHERE active=1 ORDER BY sort_order, name'
        )->fetchAll() ?: [];
    }

    /** Copia formas padrão (loja 1 / NULL) para a loja informada. */
    public function ensureMethodsForStore(int $storeId): void
    {
        if ($storeId <= 0 || !$this->hasStoreIdColumn()) {
            return;
        }
        try {
            $cnt = $this->pdo->prepare('SELECT COUNT(*) FROM payment_methods WHERE store_id=?');
            $cnt->execute([$storeId]);
            if ((int) $cnt->fetchColumn() > 0) {
                return;
            }
            // Prefere modelos da loja 1; senão qualquer linha “global”
            $src = $this->pdo->query(
                'SELECT COUNT(*) FROM payment_methods WHERE store_id=1'
            )->fetchColumn();
            if ((int) $src > 0) {
                $this->pdo->prepare(
                    'INSERT INTO payment_methods (store_id, code, name, type, instructions, sort_order, active)
                     SELECT ?, code, name, type, instructions, sort_order, active
                     FROM payment_methods WHERE store_id=1'
                )->execute([$storeId]);
            } else {
                $this->pdo->prepare(
                    'INSERT INTO payment_methods (store_id, code, name, type, instructions, sort_order, active)
                     SELECT ?, code, name, type, instructions, sort_order, active
                     FROM payment_methods WHERE store_id IS NULL'
                )->execute([$storeId]);
            }
        } catch (\Throwable) {
            // UNIQUE / schema — ignora; listActive usa fallback
        }
    }

    /** @return list<array<string,mixed>> */
    public function listAll(?int $storeId = null): array
    {
        $this->ensureSchema();
        if ($storeId !== null && $storeId > 0 && $this->hasStoreIdColumn()) {
            $st = $this->pdo->prepare(
                'SELECT * FROM payment_methods WHERE store_id=? OR store_id IS NULL ORDER BY sort_order, name'
            );
            $st->execute([$storeId]);
            return $st->fetchAll() ?: [];
        }
        return $this->pdo->query(
            'SELECT * FROM payment_methods ORDER BY sort_order, name'
        )->fetchAll() ?: [];
    }

    private function hasStoreIdColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $this->pdo->query('SELECT store_id FROM payment_methods LIMIT 1');
            $has = true;
        } catch (\Throwable) {
            $has = false;
        }
        return $has;
    }

    public function findByCode(string $code): ?array
    {
        $this->ensureSchema();
        $code = $this->normalizeCode($code);
        $st = $this->pdo->prepare('SELECT * FROM payment_methods WHERE code=? LIMIT 1');
        $st->execute([$code]);
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
        // Legado: "cartao" genérico
        if ($code === 'cartao') {
            $st->execute(['cartao_credito']);
            return $st->fetch() ?: null;
        }
        return null;
    }

    public function findActiveByCode(string $code): ?array
    {
        $row = $this->findByCode($code);
        if (!$row || !(int) ($row['active'] ?? 0)) {
            return null;
        }
        return $row;
    }

    public function label(string $code): string
    {
        $row = $this->findByCode($code);
        return $row ? (string) $row['name'] : $code;
    }

    public function normalizeCode(string $code): string
    {
        $code = mb_strtolower(trim($code));
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? $code;
        return trim($code, '_') ?: 'pix';
    }

    public function isPix(string $code): bool
    {
        $row = $this->findByCode($code);
        if ($row) {
            return (string) $row['type'] === 'online_pix' || (string) $row['code'] === 'pix';
        }
        return $this->normalizeCode($code) === 'pix';
    }

    public function isOnlineCard(string $code): bool
    {
        $row = $this->findByCode($code);
        if ($row) {
            return (string) $row['type'] === 'online_card';
        }
        $c = $this->normalizeCode($code);
        return in_array($c, ['cartao', 'cartao_credito', 'cartao_debito'], true);
    }

    public function isOnDelivery(string $code): bool
    {
        $row = $this->findByCode($code);
        if ($row) {
            return in_array((string) $row['type'], ['on_delivery', 'other'], true);
        }
        $c = $this->normalizeCode($code);
        return in_array($c, ['dinheiro', 'cartao_maquina'], true);
    }

    /**
     * @return array{id:int,created:bool,updated:bool}
     */
    public function save(
        string $name,
        string $code,
        string $type,
        ?string $instructions,
        int $sortOrder = 100,
        bool $active = true,
        ?int $id = null
    ): array {
        $this->ensureSchema();
        $name = trim($name);
        $code = $this->normalizeCode($code);
        $type = in_array($type, ['online_pix', 'online_card', 'on_delivery', 'other'], true) ? $type : 'other';
        $instructions = $instructions !== null ? trim($instructions) : '';
        if ($name === '' || $code === '') {
            throw new \InvalidArgumentException('Nome e código são obrigatórios.');
        }
        if ($id !== null && $id > 0) {
            $this->pdo->prepare(
                'UPDATE payment_methods SET name=?, code=?, type=?, instructions=?, sort_order=?, active=? WHERE id=?'
            )->execute([
                $name, $code, $type, $instructions !== '' ? $instructions : null,
                $sortOrder, $active ? 1 : 0, $id,
            ]);
            return ['id' => $id, 'created' => false, 'updated' => true];
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO payment_methods (code, name, type, instructions, sort_order, active) VALUES (?,?,?,?,?,?)'
            )->execute([
                $code, $name, $type, $instructions !== '' ? $instructions : null,
                $sortOrder, $active ? 1 : 0,
            ]);
        } catch (PDOException) {
            throw new \RuntimeException('Código já cadastrado. Use outro ou edite o existente.');
        }
        return ['id' => (int) $this->pdo->lastInsertId(), 'created' => true, 'updated' => false];
    }

    public function toggle(int $id): void
    {
        $this->ensureSchema();
        $this->pdo->prepare('UPDATE payment_methods SET active = IF(active=1,0,1) WHERE id=?')->execute([$id]);
    }

    public function delete(int $id): void
    {
        $this->ensureSchema();
        $st = $this->pdo->prepare('SELECT code FROM payment_methods WHERE id=?');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            return;
        }
        $code = (string) $row['code'];
        if (in_array($code, ['pix', 'dinheiro', 'cartao_credito', 'cartao_debito'], true)) {
            throw new \RuntimeException('Formas padrão não podem ser excluídas — desative-as se necessário.');
        }
        $this->pdo->prepare('DELETE FROM payment_methods WHERE id=?')->execute([$id]);
    }
}
