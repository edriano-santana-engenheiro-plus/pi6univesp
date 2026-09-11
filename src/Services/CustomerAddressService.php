<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

final class CustomerAddressService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function tablesExist(): bool
    {
        try {
            $this->pdo->query('SELECT 1 FROM customer_addresses LIMIT 1');
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    public function listForUser(int $userId): array
    {
        if (!$this->tablesExist() || $userId <= 0) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM customer_addresses WHERE user_id=? ORDER BY is_default DESC, id DESC'
        );
        $stmt->execute([$userId]);
        return $stmt->fetchAll() ?: [];
    }

    public function findForUser(int $userId, int $addressId): ?array
    {
        if (!$this->tablesExist() || $userId <= 0 || $addressId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM customer_addresses WHERE id=? AND user_id=? LIMIT 1');
        $stmt->execute([$addressId, $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function defaultForUser(int $userId): ?array
    {
        if (!$this->tablesExist() || $userId <= 0) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM customer_addresses WHERE user_id=? ORDER BY is_default DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array{street:string,number?:string,complement?:string,neighborhood?:string,city?:string,zip?:string,lat?:?float,lng?:?float,label?:string,is_default?:bool} $data */
    public function save(int $userId, array $data, ?int $addressId = null): int
    {
        if (!$this->tablesExist() || $userId <= 0) {
            return 0;
        }
        $street = trim((string) ($data['street'] ?? ''));
        if ($street === '') {
            throw new \InvalidArgumentException('Informe a rua do endereço.');
        }
        $number = trim((string) ($data['number'] ?? ''));
        $complement = trim((string) ($data['complement'] ?? ''));
        $neighborhood = trim((string) ($data['neighborhood'] ?? ''));
        $city = trim((string) ($data['city'] ?? 'Itamogi')) ?: 'Itamogi';
        $zip = trim((string) ($data['zip'] ?? ''));
        $lat = isset($data['lat']) && $data['lat'] !== null && $data['lat'] !== '' ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) && $data['lng'] !== null && $data['lng'] !== '' ? (float) $data['lng'] : null;
        $label = trim((string) ($data['label'] ?? 'Casa')) ?: 'Casa';
        $makeDefault = !empty($data['is_default']);

        if ($addressId !== null && $addressId > 0) {
            $existing = $this->findForUser($userId, $addressId);
            if (!$existing) {
                throw new \RuntimeException('Endereço não encontrado.');
            }
            $this->pdo->prepare(
                'UPDATE customer_addresses SET label=?, street=?, number=?, complement=?, neighborhood=?, city=?, zip=?, lat=?, lng=?
                 WHERE id=? AND user_id=?'
            )->execute([
                $label, $street, $number ?: null, $complement ?: null, $neighborhood ?: null,
                $city, $zip ?: null, $lat, $lng, $addressId, $userId,
            ]);
            if ($makeDefault || (int) ($existing['is_default'] ?? 0) === 1) {
                $this->setDefault($userId, $addressId);
            }
            return $addressId;
        }

        $hasAny = $this->defaultForUser($userId) !== null;
        $isDefault = $makeDefault || !$hasAny ? 1 : 0;
        if ($isDefault === 1) {
            $this->pdo->prepare('UPDATE customer_addresses SET is_default=0 WHERE user_id=?')->execute([$userId]);
        }
        $this->pdo->prepare(
            'INSERT INTO customer_addresses (user_id, label, street, number, complement, neighborhood, city, zip, lat, lng, is_default)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $userId, $label, $street, $number ?: null, $complement ?: null, $neighborhood ?: null,
            $city, $zip ?: null, $lat, $lng, $isDefault,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{street:string,number?:string,complement?:string,neighborhood?:string,city?:string,zip?:string,lat?:?float,lng?:?float,label?:string} $data */
    public function upsertDefault(int $userId, array $data): int
    {
        $data['is_default'] = true;
        $existing = $this->defaultForUser($userId);
        return $this->save($userId, $data, $existing ? (int) $existing['id'] : null);
    }

    public function setDefault(int $userId, int $addressId): void
    {
        if (!$this->tablesExist() || $userId <= 0 || $addressId <= 0) {
            return;
        }
        if (!$this->findForUser($userId, $addressId)) {
            throw new \RuntimeException('Endereço não encontrado.');
        }
        $this->pdo->prepare('UPDATE customer_addresses SET is_default=0 WHERE user_id=?')->execute([$userId]);
        $this->pdo->prepare('UPDATE customer_addresses SET is_default=1 WHERE id=? AND user_id=?')
            ->execute([$addressId, $userId]);
    }

    public function delete(int $userId, int $addressId): void
    {
        if (!$this->tablesExist() || $userId <= 0 || $addressId <= 0) {
            return;
        }
        $row = $this->findForUser($userId, $addressId);
        if (!$row) {
            throw new \RuntimeException('Endereço não encontrado.');
        }
        $this->pdo->prepare('DELETE FROM customer_addresses WHERE id=? AND user_id=?')
            ->execute([$addressId, $userId]);
        if ((int) ($row['is_default'] ?? 0) === 1) {
            $next = $this->defaultForUser($userId);
            if ($next) {
                $this->setDefault($userId, (int) $next['id']);
            }
        }
    }

    public function deleteAllForUser(int $userId): void
    {
        if (!$this->tablesExist() || $userId <= 0) {
            return;
        }
        $this->pdo->prepare('DELETE FROM customer_addresses WHERE user_id=?')->execute([$userId]);
    }
}
