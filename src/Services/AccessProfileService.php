<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

/** Perfis de acesso (RBAC) do painel administrativo. */
final class AccessProfileService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Catálogo de permissões disponíveis (chave → metadados).
     *
     * @return array<string, array{label:string, group:string}>
     */
    public static function catalog(): array
    {
        return [
            'nav.dashboard' => ['label' => 'Dashboard', 'group' => 'Telas'],
            'nav.orders' => ['label' => 'Pedidos', 'group' => 'Telas'],
            'nav.chat' => ['label' => 'Chat com motoboys', 'group' => 'Telas'],
            'nav.deliveries' => ['label' => 'Entregas ao vivo', 'group' => 'Telas'],
            'nav.track' => ['label' => 'Rastreio (atalho no menu)', 'group' => 'Telas'],
            'nav.products' => ['label' => 'Cardápio', 'group' => 'Telas'],
            'nav.motoboys' => ['label' => 'Motoboys', 'group' => 'Telas'],
            'nav.users' => ['label' => 'Usuários', 'group' => 'Telas'],
            'nav.profiles' => ['label' => 'Perfis de acesso', 'group' => 'Telas'],
            'nav.payments' => ['label' => 'Formas de pagamento', 'group' => 'Telas'],
            'nav.settings' => ['label' => 'Configurações (visualizar)', 'group' => 'Telas'],
            'live.stream' => ['label' => 'Feed ao vivo (SSE)', 'group' => 'Telas'],
            'settings.write' => ['label' => 'Alterar configurações da loja', 'group' => 'Configurações'],
            'users.manage' => ['label' => 'Cadastrar/editar usuários', 'group' => 'Administração'],
            'profiles.manage' => ['label' => 'Cadastrar/editar perfis de acesso', 'group' => 'Administração'],
        ];
    }

    /** Mapa rota do painel → permissão exigida. */
    public static function routePermission(string $uri): ?string
    {
        return match ($uri) {
            '/' => 'nav.dashboard',
            '/pedidos', '/pedidos/ver', '/pedidos/comanda' => 'nav.orders',
            '/chat' => 'nav.chat',
            '/entregas' => 'nav.deliveries',
            '/cardapio', '/cardapio/imagens', '/cardapio/imagens/navegador' => 'nav.products',
            '/motoboys' => 'nav.motoboys',
            '/usuarios' => 'nav.users',
            '/perfis' => 'nav.profiles',
            '/formas-pagamento' => 'nav.payments',
            '/configuracoes' => 'nav.settings',
            '/live-stream' => 'live.stream',
            default => null,
        };
    }

    public function ensureSchema(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS access_profiles (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name VARCHAR(80) NOT NULL,
                slug VARCHAR(80) NOT NULL,
                description VARCHAR(255) NULL,
                is_system TINYINT(1) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_access_profiles_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS access_profile_permissions (
                profile_id INT UNSIGNED NOT NULL,
                permission_key VARCHAR(64) NOT NULL,
                PRIMARY KEY (profile_id, permission_key),
                KEY idx_app_perm_key (permission_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'profile_id'")->fetch();
            if (!$col) {
                $this->pdo->exec('ALTER TABLE users ADD COLUMN profile_id INT UNSIGNED NULL AFTER role');
            }
        } catch (\Throwable) {
        }
        $done = true;
    }

    public function seedDefaults(): void
    {
        $this->ensureSchema();
        $all = array_keys(self::catalog());
        $atendente = array_values(array_filter(
            $all,
            static fn (string $k): bool => !in_array($k, [
                'nav.users',
                'nav.profiles',
                'users.manage',
                'profiles.manage',
            ], true)
        ));

        $adminId = $this->upsertSystemProfile(
            'Administrador',
            'administrador',
            'Acesso total ao painel',
            $all
        );
        $this->upsertSystemProfile(
            'Atendente',
            'atendente',
            'Operação do dia a dia (sem usuários/perfis)',
            $atendente
        );

        // Vincula usuários órfãos
        if ($adminId > 0) {
            try {
                $this->pdo->prepare(
                    "UPDATE users SET profile_id=? WHERE role='admin' AND (profile_id IS NULL OR profile_id=0)"
                )->execute([$adminId]);
            } catch (\Throwable) {
            }
        }
        $atendId = $this->findIdBySlug('atendente');
        if ($atendId) {
            try {
                $this->pdo->prepare(
                    "UPDATE users SET profile_id=? WHERE role='atendente' AND (profile_id IS NULL OR profile_id=0)"
                )->execute([$atendId]);
            } catch (\Throwable) {
            }
        }
    }

    public function findIdBySlug(string $slug): ?int
    {
        $this->ensureSchema();
        $st = $this->pdo->prepare('SELECT id FROM access_profiles WHERE slug=? LIMIT 1');
        $st->execute([$slug]);
        $id = $st->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    /** @return list<array<string,mixed>> */
    public function listProfiles(bool $activeOnly = false): array
    {
        $this->ensureSchema();
        $sql = 'SELECT p.*,
                       (SELECT COUNT(*) FROM access_profile_permissions x WHERE x.profile_id=p.id) AS perm_count,
                       (SELECT COUNT(*) FROM users u WHERE u.profile_id=p.id) AS user_count
                FROM access_profiles p';
        if ($activeOnly) {
            $sql .= ' WHERE p.active=1';
        }
        $sql .= ' ORDER BY p.is_system DESC, p.name ASC';
        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $this->ensureSchema();
        $st = $this->pdo->prepare('SELECT * FROM access_profiles WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return list<string> */
    public function permissionsOf(int $profileId): array
    {
        $this->ensureSchema();
        $st = $this->pdo->prepare(
            'SELECT permission_key FROM access_profile_permissions WHERE profile_id=? ORDER BY permission_key'
        );
        $st->execute([$profileId]);
        return array_values(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []));
    }

    /**
     * @param list<string> $permissionKeys
     * @return array{id:int, created:bool}
     */
    public function save(
        string $name,
        string $description,
        array $permissionKeys,
        ?int $id = null,
        bool $active = true
    ): array {
        $this->ensureSchema();
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Nome do perfil é obrigatório.');
        }
        $description = trim($description);
        $valid = array_keys(self::catalog());
        $permissionKeys = array_values(array_unique(array_filter(
            $permissionKeys,
            static fn ($k) => is_string($k) && in_array($k, $valid, true)
        )));

        if ($id !== null && $id > 0) {
            $current = $this->find($id);
            if (!$current) {
                throw new \RuntimeException('Perfil não encontrado.');
            }
            if ((int) $current['is_system'] === 1) {
                // Sistema: mantém nome/slug; garante chaves críticas no Administrador
                if ((string) $current['slug'] === 'administrador') {
                    foreach (['profiles.manage', 'users.manage', 'nav.users', 'nav.profiles'] as $must) {
                        if (!in_array($must, $permissionKeys, true)) {
                            $permissionKeys[] = $must;
                        }
                    }
                }
                $this->pdo->prepare(
                    'UPDATE access_profiles SET description=?, active=1 WHERE id=?'
                )->execute([$description !== '' ? $description : null, $id]);
            } else {
                $slug = $this->uniqueSlug($this->slugify($name), $id);
                $this->pdo->prepare(
                    'UPDATE access_profiles SET name=?, slug=?, description=?, active=? WHERE id=?'
                )->execute([
                    $name,
                    $slug,
                    $description !== '' ? $description : null,
                    $active ? 1 : 0,
                    $id,
                ]);
            }
            $this->replacePermissions($id, $permissionKeys);
            return ['id' => $id, 'created' => false];
        }

        $slug = $this->uniqueSlug($this->slugify($name), null);
        $this->pdo->prepare(
            'INSERT INTO access_profiles (name, slug, description, is_system, active) VALUES (?,?,?,0,?)'
        )->execute([
            $name,
            $slug,
            $description !== '' ? $description : null,
            $active ? 1 : 0,
        ]);
        $newId = (int) $this->pdo->lastInsertId();
        $this->replacePermissions($newId, $permissionKeys);
        return ['id' => $newId, 'created' => true];
    }

    public function delete(int $id): void
    {
        $this->ensureSchema();
        $p = $this->find($id);
        if (!$p) {
            throw new \RuntimeException('Perfil não encontrado.');
        }
        if ((int) $p['is_system'] === 1) {
            throw new \RuntimeException('Perfis de sistema não podem ser excluídos.');
        }
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE profile_id=?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            throw new \RuntimeException('Há usuários vinculados a este perfil. Reatribua-os antes de excluir.');
        }
        $this->pdo->prepare('DELETE FROM access_profiles WHERE id=?')->execute([$id]);
    }

    /**
     * Permissões efetivas do usuário do painel.
     *
     * @param array<string,mixed> $user
     * @return list<string>
     */
    public function permissionsForUser(array $user): array
    {
        $this->ensureSchema();
        $this->seedDefaults();

        $role = (string) ($user['role'] ?? '');
        // Admin de canal sempre tem tudo (evita lockout)
        if ($role === 'admin') {
            return array_keys(self::catalog());
        }

        $profileId = (int) ($user['profile_id'] ?? 0);
        if ($profileId <= 0) {
            // fallback legado
            if ($role === 'atendente') {
                $profileId = (int) ($this->findIdBySlug('atendente') ?? 0);
            }
        }
        if ($profileId > 0) {
            $keys = $this->permissionsOf($profileId);
            if ($keys !== []) {
                return $keys;
            }
        }
        // Sem perfil: atendente legado sem usuários/perfis
        return array_values(array_filter(
            array_keys(self::catalog()),
            static fn (string $k): bool => !in_array($k, [
                'nav.users', 'nav.profiles', 'users.manage', 'profiles.manage',
            ], true)
        ));
    }

    public function setUserProfile(int $userId, ?int $profileId): void
    {
        $this->ensureSchema();
        if ($profileId !== null && $profileId > 0) {
            if (!$this->find($profileId)) {
                throw new \RuntimeException('Perfil inválido.');
            }
            $this->pdo->prepare('UPDATE users SET profile_id=? WHERE id=?')->execute([$profileId, $userId]);
            return;
        }
        $this->pdo->prepare('UPDATE users SET profile_id=NULL WHERE id=?')->execute([$userId]);
    }

    /** @param list<string> $keys */
    private function replacePermissions(int $profileId, array $keys): void
    {
        $this->pdo->prepare('DELETE FROM access_profile_permissions WHERE profile_id=?')->execute([$profileId]);
        $ins = $this->pdo->prepare(
            'INSERT INTO access_profile_permissions (profile_id, permission_key) VALUES (?,?)'
        );
        foreach ($keys as $k) {
            $ins->execute([$profileId, $k]);
        }
    }

    /** @param list<string> $keys */
    private function upsertSystemProfile(string $name, string $slug, string $description, array $keys): int
    {
        $existing = $this->findIdBySlug($slug);
        if ($existing) {
            $this->pdo->prepare(
                'UPDATE access_profiles SET name=?, description=?, is_system=1, active=1 WHERE id=?'
            )->execute([$name, $description, $existing]);
            $this->replacePermissions($existing, $keys);
            return $existing;
        }
        $this->pdo->prepare(
            'INSERT INTO access_profiles (name, slug, description, is_system, active) VALUES (?,?,?,1,1)'
        )->execute([$name, $slug, $description]);
        $id = (int) $this->pdo->lastInsertId();
        $this->replacePermissions($id, $keys);
        return $id;
    }

    private function slugify(string $name): string
    {
        $s = mb_strtolower(trim($name));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s) ?? $s;
        $s = trim($s, '-');
        if ($s === '') {
            $s = 'perfil';
        }
        return mb_substr($s, 0, 70);
    }

    private function uniqueSlug(string $base, ?int $ignoreId): string
    {
        $slug = $base;
        $n = 2;
        while (true) {
            $st = $this->pdo->prepare('SELECT id FROM access_profiles WHERE slug=? LIMIT 1');
            $st->execute([$slug]);
            $id = $st->fetchColumn();
            if ($id === false || ($ignoreId !== null && (int) $id === $ignoreId)) {
                return $slug;
            }
            $slug = mb_substr($base, 0, 60) . '-' . $n;
            $n++;
        }
    }
}
