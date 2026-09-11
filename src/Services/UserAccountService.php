<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;
use PDOException;

/** Cadastro/atualização de usuários no painel (motoboy, admin, cliente…). */
final class UserAccountService
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{id:int}|null */
    public function findByEmail(string $email): ?array
    {
        $st = $this->pdo->prepare('SELECT id, name, email, phone, role, profile_id, active FROM users WHERE email=? LIMIT 1');
        try {
            $st->execute([mb_strtolower(trim($email))]);
        } catch (\PDOException) {
            $st = $this->pdo->prepare('SELECT id, name, email, phone, role, active FROM users WHERE email=? LIMIT 1');
            $st->execute([mb_strtolower(trim($email))]);
        }
        $row = $st->fetch();
        return $row ?: null;
    }

    public function findById(int $id): ?array
    {
        try {
            $st = $this->pdo->prepare('SELECT id, name, email, phone, role, profile_id, active FROM users WHERE id=? LIMIT 1');
            $st->execute([$id]);
        } catch (\PDOException) {
            $st = $this->pdo->prepare('SELECT id, name, email, phone, role, active FROM users WHERE id=? LIMIT 1');
            $st->execute([$id]);
        }
        $row = $st->fetch();
        return $row ?: null;
    }

    /**
     * Cria ou atualiza pelo e-mail quando já existir o mesmo papel.
     * Se o e-mail pertencer a outro papel, lança RuntimeException.
     *
     * @return array{id:int, created:bool, updated:bool}
     */
    public function save(
        string $role,
        string $name,
        string $email,
        ?string $phone,
        ?string $password,
        ?int $id = null,
        bool $reactivate = true,
        ?int $profileId = null
    ): array {
        $name = trim($name);
        $email = mb_strtolower(trim($email));
        $phone = $phone !== null ? trim($phone) : '';
        $phoneVal = $phone !== '' ? $phone : null;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Nome e e-mail válidos são obrigatórios.');
        }

        $existing = $this->findByEmail($email);

        // Atualização explícita por id
        if ($id !== null && $id > 0) {
            $current = $this->findById($id);
            if (!$current || (string) $current['role'] !== $role) {
                throw new \RuntimeException('Usuário não encontrado para este perfil.');
            }
            if ($existing && (int) $existing['id'] !== $id) {
                throw new \RuntimeException(
                    'Este e-mail já está em uso por outro usuário (' . $existing['role'] . ').'
                );
            }
            $this->updateRow($id, $name, $email, $phoneVal, $password, $role, $reactivate, $profileId);
            return ['id' => $id, 'created' => false, 'updated' => true];
        }

        // Cadastro: se e-mail já existe no mesmo papel → atualiza
        if ($existing) {
            if ((string) $existing['role'] !== $role) {
                throw new \RuntimeException(
                    'E-mail já cadastrado como ' . $existing['role'] . '. Use outro e-mail ou edite na tela correspondente.'
                );
            }
            $this->updateRow((int) $existing['id'], $name, $email, $phoneVal, $password, $role, $reactivate, $profileId);
            return ['id' => (int) $existing['id'], 'created' => false, 'updated' => true];
        }

        if ($password === null || $password === '') {
            throw new \InvalidArgumentException('Senha obrigatória no cadastro (mín. 6 caracteres).');
        }
        if (strlen($password) < 6) {
            throw new \InvalidArgumentException('Senha deve ter pelo menos 6 caracteres.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $this->pdo->prepare(
                'INSERT INTO users (name, email, phone, password_hash, role, profile_id, active) VALUES (?,?,?,?,?,?,1)'
            )->execute([$name, $email, $phoneVal, $hash, $role, $profileId]);
        } catch (PDOException $e) {
            // Sem coluna profile_id
            if (str_contains($e->getMessage(), 'profile_id')) {
                $this->pdo->prepare(
                    'INSERT INTO users (name, email, phone, password_hash, role, active) VALUES (?,?,?,?,?,1)'
                )->execute([$name, $email, $phoneVal, $hash, $role]);
            } else {
                // Corrida rara: e-mail criado entre o SELECT e o INSERT
                $again = $this->findByEmail($email);
                if ($again && (string) $again['role'] === $role) {
                    $this->updateRow((int) $again['id'], $name, $email, $phoneVal, $password, $role, $reactivate, $profileId);
                    return ['id' => (int) $again['id'], 'created' => false, 'updated' => true];
                }
                throw new \RuntimeException('Não foi possível cadastrar (e-mail duplicado?).');
            }
        }

        return ['id' => (int) $this->pdo->lastInsertId(), 'created' => true, 'updated' => false];
    }

    /**
     * Atualização self-service do cliente (perfil da loja / API).
     *
     * @return array{id:int,name:string,email:string,phone:?string,role:string,active:int|string}
     */
    public function updateClienteSelf(
        int $id,
        string $name,
        string $email,
        ?string $phone,
        ?string $password = null
    ): array {
        $current = $this->findById($id);
        if (!$current || (string) $current['role'] !== 'cliente' || !(int) ($current['active'] ?? 0)) {
            throw new \RuntimeException('Conta de cliente não encontrada.');
        }
        $this->save('cliente', $name, $email, $phone, $password, $id, false);
        $fresh = $this->findById($id);
        if (!$fresh) {
            throw new \RuntimeException('Não foi possível atualizar o perfil.');
        }
        return $fresh;
    }

    /**
     * Exclusão de perfil do cliente: remove endereços/tokens e anonimiza dados
     * (pedidos históricos são preservados por FK).
     */
    public function eraseClienteAccount(int $id, ?string $confirmPassword = null): void
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, password_hash, role, active FROM users WHERE id=? AND role='cliente' LIMIT 1"
        );
        $stmt->execute([$id]);
        $u = $stmt->fetch();
        if (!$u || !(int) ($u['active'] ?? 0)) {
            throw new \RuntimeException('Conta não encontrada.');
        }
        if ($confirmPassword !== null) {
            if (!password_verify($confirmPassword, (string) $u['password_hash'])) {
                throw new \InvalidArgumentException('Senha incorreta para confirmar a exclusão.');
            }
        }

        (new CustomerAddressService($this->pdo))->deleteAllForUser($id);
        $this->pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([$id]);
        try {
            $this->pdo->prepare('DELETE FROM devices WHERE user_id=?')->execute([$id]);
        } catch (\PDOException) {
            // tabela opcional
        }
        try {
            $this->pdo->prepare('DELETE FROM notifications WHERE user_id=?')->execute([$id]);
        } catch (\PDOException) {
            // tabela opcional
        }

        $anonEmail = 'excluido+' . $id . '.' . bin2hex(random_bytes(4)) . '@delivery.local';
        $deadHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $this->pdo->prepare(
            "UPDATE users SET name='Conta excluída', email=?, phone=NULL, password_hash=?, active=0 WHERE id=? AND role='cliente'"
        )->execute([$anonEmail, $deadHash, $id]);
    }

    private function updateRow(
        int $id,
        string $name,
        string $email,
        ?string $phone,
        ?string $password,
        string $role,
        bool $reactivate,
        ?int $profileId = null
    ): void {
        $hasProfile = true;
        try {
            $this->pdo->query("SELECT profile_id FROM users LIMIT 1");
        } catch (\Throwable) {
            $hasProfile = false;
        }

        if ($password !== null && $password !== '') {
            if ($hasProfile) {
                $sql = 'UPDATE users SET name=?, email=?, phone=?, password_hash=?, role=?, profile_id=?'
                    . ($reactivate ? ', active=1' : '')
                    . ' WHERE id=?';
                $this->pdo->prepare($sql)->execute([
                    $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $role, $profileId, $id,
                ]);
            } else {
                $sql = 'UPDATE users SET name=?, email=?, phone=?, password_hash=?, role=?'
                    . ($reactivate ? ', active=1' : '')
                    . ' WHERE id=?';
                $this->pdo->prepare($sql)->execute([
                    $name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $role, $id,
                ]);
            }
            try {
                $this->pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([$id]);
            } catch (\Throwable) {
            }
        } else {
            if ($hasProfile) {
                $sql = 'UPDATE users SET name=?, email=?, phone=?, role=?, profile_id=?'
                    . ($reactivate ? ', active=1' : '')
                    . ' WHERE id=?';
                $this->pdo->prepare($sql)->execute([$name, $email, $phone, $role, $profileId, $id]);
            } else {
                $sql = 'UPDATE users SET name=?, email=?, phone=?, role=?'
                    . ($reactivate ? ', active=1' : '')
                    . ' WHERE id=?';
                $this->pdo->prepare($sql)->execute([$name, $email, $phone, $role, $id]);
            }
        }
    }

    /**
     * Redefine senha com e-mail + telefone cadastrado (apps sem SMTP).
     * Usado principalmente pelo Motoboy; também serve cliente se $role permitir.
     *
     * @param list<string> $allowedRoles
     * @return array{id:int,name:string,email:string,role:string}
     */
    public function resetPasswordByEmailAndPhone(
        string $email,
        string $phone,
        string $newPassword,
        array $allowedRoles = ['motoboy']
    ): array {
        $email = mb_strtolower(trim($email));
        $phone = trim($phone);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Informe um e-mail válido.');
        }
        if (strlen($newPassword) < 6) {
            throw new \InvalidArgumentException('A nova senha deve ter no mínimo 6 caracteres.');
        }
        if ($phone === '') {
            throw new \InvalidArgumentException('Informe o telefone cadastrado na conta.');
        }

        $st = $this->pdo->prepare(
            'SELECT id, name, email, phone, role, active FROM users WHERE email=? LIMIT 1'
        );
        $st->execute([$email]);
        $u = $st->fetch();
        // Mensagens genéricas evitam enumeração de contas
        $fail = static function (): void {
            throw new \RuntimeException(
                'Não foi possível redefinir a senha. Verifique e-mail, telefone e se a conta é deste app.'
            );
        };
        if (!$u || !(int) ($u['active'] ?? 0)) {
            $fail();
        }
        $role = (string) ($u['role'] ?? '');
        if (!in_array($role, $allowedRoles, true)) {
            $fail();
        }
        $storedPhone = trim((string) ($u['phone'] ?? ''));
        if ($storedPhone === '' || !$this->phonesMatch($storedPhone, $phone)) {
            $fail();
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->pdo->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, (int) $u['id']]);
        try {
            $this->pdo->prepare('DELETE FROM api_tokens WHERE user_id=?')->execute([(int) $u['id']]);
        } catch (\Throwable) {
        }

        return [
            'id' => (int) $u['id'],
            'name' => (string) $u['name'],
            'email' => (string) $u['email'],
            'role' => $role,
        ];
    }

    /** Compara telefone nacional BR (DDI 55 opcional); exige match completo (sem “últimos 8”). */
    public function phonesMatch(string $a, string $b): bool
    {
        $norm = static function (string $raw): string {
            $d = preg_replace('/\D+/', '', $raw) ?? '';
            if (str_starts_with($d, '55') && strlen($d) >= 12) {
                $d = substr($d, 2);
            }
            return $d;
        };
        $da = $norm($a);
        $db = $norm($b);
        if (strlen($da) < 10 || strlen($db) < 10) {
            return false;
        }

        return $da === $db;
    }
}
