<?php
declare(strict_types=1);

namespace Delivery\Utils;

final class Auth
{
    public static function user(): ?array
    {
        return $_SESSION['delivery_user'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /** @param list<string>|null $permissions */
    public static function login(array $user, ?array $permissions = null): void
    {
        unset($user['password_hash']);
        $_SESSION['delivery_user'] = $user;
        if ($permissions !== null) {
            $_SESSION['delivery_permissions'] = array_values(array_unique(array_map('strval', $permissions)));
        } else {
            unset($_SESSION['delivery_permissions']);
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['delivery_user'], $_SESSION['delivery_permissions']);
    }

    /** @param list<string> $permissions */
    public static function setPermissions(array $permissions): void
    {
        $_SESSION['delivery_permissions'] = array_values(array_unique(array_map('strval', $permissions)));
    }

    /** @return list<string> */
    public static function permissions(): array
    {
        $p = $_SESSION['delivery_permissions'] ?? null;
        return is_array($p) ? array_values(array_map('strval', $p)) : [];
    }

    public static function can(string $permission): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }
        // Canal admin nunca fica sem acesso total
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        $perms = self::permissions();
        if ($perms === []) {
            // Sessão antiga sem carga de permissões: libera operação básica
            return !in_array($permission, [
                'nav.users', 'nav.profiles', 'users.manage', 'profiles.manage',
            ], true);
        }
        return in_array($permission, $perms, true);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            View::redirect('/login');
        }
    }

    public static function requireRoles(array $roles): void
    {
        self::requireLogin();
        $role = (string) (self::user()['role'] ?? '');
        if (!in_array($role, $roles, true)) {
            http_response_code(403);
            echo 'Acesso negado';
            exit;
        }
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (!self::can($permission)) {
            http_response_code(403);
            echo 'Acesso negado: permissão insuficiente (' . htmlspecialchars($permission, ENT_QUOTES, 'UTF-8') . ').';
            exit;
        }
    }
}
