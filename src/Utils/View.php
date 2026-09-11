<?php
declare(strict_types=1);

namespace Delivery\Utils;

final class View
{
    public static function e(?string $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function basePath(): string
    {
        // Preferência: caminho configurado no .env
        $configured = function_exists('delivery_env') ? delivery_env('APP_BASE_PATH', null) : null;
        if ($configured !== null) {
            $configured = trim(str_replace('\\', '/', $configured));
            if ($configured === '/' || $configured === '') {
                return '';
            }
            return rtrim($configured, '/');
        }

        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if (str_ends_with($script, '/api')) {
            $script = dirname($script);
        }

        // Só remove /public_html da URL quando o APP_URL de produção
        // NÃO contém public_html (subdomínio com rewrite oculto).
        // No XAMPP (APP_URL=.../delivery/public_html) devemos MANTER o segmento.
        $appUrl = function_exists('delivery_env') ? (string) delivery_env('APP_URL', '') : '';
        if (
            str_ends_with($script, '/public_html')
            && $appUrl !== ''
            && !str_contains($appUrl, '/public_html')
        ) {
            $script = dirname($script);
        }

        if ($script === '/' || $script === '.' || $script === '\\') {
            return '';
        }
        return rtrim($script, '/');
    }

    public static function path(string $path = '/'): string
    {
        if ($path === '' || $path === '/') {
            $path = '/';
        }
        // URL absoluta (http/https) — não prefixar
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        // Query-only
        if (str_starts_with($path, '?')) {
            return self::basePath() . '/' . $path;
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $base = self::basePath();
        $pathOnly = explode('?', $path, 2)[0];
        // Já veio com o prefixo (ex.: REQUEST_URI=/delivery/public_html/lojas)
        if ($base !== '' && (str_starts_with($pathOnly, $base . '/') || $pathOnly === $base || $pathOnly === $base . '/')) {
            return $pathOnly === $base ? ($base . '/' . (str_contains($path, '?') ? ('?' . explode('?', $path, 2)[1]) : '')) : $path;
        }
        return $base . $path;
    }

    /**
     * Normaliza destino de redirect: aceita rota (/lojas), REQUEST_URI ou URL absoluta.
     */
    public static function normalizeRedirectTarget(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return '/';
        }
        if (preg_match('#^https?://#i', $target)) {
            $path = parse_url($target, PHP_URL_PATH) ?: '/';
            $query = parse_url($target, PHP_URL_QUERY);
            $target = $path . ($query ? ('?' . $query) : '');
        }
        // Só o path (+ query)
        if (str_contains($target, '?')) {
            [$pathPart, $queryPart] = explode('?', $target, 2);
        } else {
            $pathPart = $target;
            $queryPart = null;
        }
        $base = self::basePath();
        if ($base !== '' && str_starts_with($pathPart, $base)) {
            $pathPart = substr($pathPart, strlen($base)) ?: '/';
        }
        // Remove duplicatas acidentais do base
        while ($base !== '' && str_starts_with($pathPart, $base)) {
            $pathPart = substr($pathPart, strlen($base)) ?: '/';
        }
        $pathPart = '/' . trim(str_replace('\\', '/', $pathPart), '/');
        if ($pathPart === '//') {
            $pathPart = '/';
        }
        return $queryPart !== null && $queryPart !== '' ? ($pathPart . '?' . $queryPart) : $pathPart;
    }

    public static function redirect(string $path, ?string $flash = null, string $type = 'success'): never
    {
        if ($flash !== null) {
            $_SESSION['flash'] = ['text' => $flash, 'type' => $type];
        }
        // Evita página em branco quando algum arquivo PHP foi salvo com BOM UTF-8
        // (BOM vira output → headers já enviados → Location falha).
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $location = self::path(self::normalizeRedirectTarget($path));
        if (!headers_sent()) {
            header('Location: ' . $location, true, 302);
            exit;
        }
        $url = self::e($location);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url='
            . $url . '"><title>Redirecionando…</title></head><body>'
            . '<p><a href="' . $url . '">Continuar</a></p></body></html>';
        exit;
    }

    public static function asset(string $path): string
    {
        $rel = ltrim(str_replace('\\', '/', $path), '/');

        // Prefixo configurável (ex.: /public_html/assets no cPanel com docroot = pasta pai)
        $configured = function_exists('delivery_env') ? trim((string) delivery_env('APP_ASSET_BASE', '')) : '';
        if ($configured !== '') {
            $prefix = '/' . trim(str_replace('\\', '/', $configured), '/');
        } else {
            $prefix = self::resolveAssetPrefix();
        }

        $url = self::path($prefix . '/' . $rel);

        $ver = function_exists('delivery_env') ? trim((string) delivery_env('APP_ASSET_VERSION', '')) : '';
        if ($ver === '' && defined('DELIVERY_ROOT')) {
            $disk = DELIVERY_ROOT . '/public_html/assets/' . $rel;
            if (!is_file($disk) && defined('DELIVERY_ROOT')) {
                $disk = DELIVERY_ROOT . '/assets/' . $rel;
            }
            $ver = is_file($disk) ? (string) filemtime($disk) : date('YmdH');
        }
        if ($ver === '') {
            $ver = date('YmdH');
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($ver);
    }

    /**
     * Local (APP_BASE_PATH=/delivery/public_html): /assets
     * Produção docroot = pasta pai: /public_html/assets (arquivos reais)
     * Produção docroot = public_html: /assets
     */
    private static function resolveAssetPrefix(): string
    {
        // Com base path (XAMPP), assets ficam relativos a public_html na URL
        if (self::basePath() !== '') {
            return '/assets';
        }

        $doc = rtrim(str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
        if ($doc !== '') {
            if (is_dir($doc . '/assets/css') || is_dir($doc . '/assets')) {
                return '/assets';
            }
            if (is_dir($doc . '/public_html/assets/css') || is_dir($doc . '/public_html/assets')) {
                // Caso real do app.integrasoft.eng.br (docroot = pasta do projeto)
                return '/public_html/assets';
            }
        }

        if (defined('DELIVERY_ROOT') && is_dir(DELIVERY_ROOT . '/public_html/assets')) {
            return '/public_html/assets';
        }

        return '/assets';
    }

    /** true quando APP_ENV=production */
    public static function isProduction(): bool
    {
        return strtolower((string) (function_exists('delivery_env') ? delivery_env('APP_ENV', 'local') : 'local')) === 'production';
    }

    /** Titular dos direitos autorais do software Delivery. */
    public static function copyrightHolder(): string
    {
        return (string) (function_exists('delivery_env')
            ? delivery_env('APP_COPYRIGHT_HOLDER', 'INTEGRASOFT - ENGENHARIA E SISTEMAS LTDA')
            : 'INTEGRASOFT - ENGENHARIA E SISTEMAS LTDA');
    }

    public static function copyrightCnpj(): string
    {
        $raw = (string) (function_exists('delivery_env')
            ? delivery_env('APP_COPYRIGHT_CNPJ', '67.018.797/0001-25')
            : '67.018.797/0001-25');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3)
                . '/' . substr($digits, 8, 4) . '-' . substr($digits, 12, 2);
        }
        return $raw !== '' ? $raw : '67.018.797/0001-25';
    }

    /** Linha curta para rodapé: © ANO TITULAR · CNPJ … */
    public static function copyrightLine(): string
    {
        return '© ' . date('Y') . ' ' . self::copyrightHolder() . ' · CNPJ ' . self::copyrightCnpj();
    }

    public static function flash(): ?array
    {
        if (empty($_SESSION['flash'])) {
            return null;
        }
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }

    public static function render(string $pageTitle, string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $appName = delivery_env('APP_NAME', 'Delivery');
        $currentUser = Auth::user();
        $activeNav = $data['activeNav'] ?? '';
        $pageSubtitle = $data['pageSubtitle'] ?? null;
        $flashMsg = self::flash();

        // Marketplace: loja ativa no painel
        if (!isset($accessibleStores) && $currentUser && function_exists('delivery_pdo')) {
            try {
                $pdo = delivery_pdo();
                $storeSvc = new \Delivery\Services\StoreService($pdo);
                $storeSvc->ensureSchema();
                $ctx = new \Delivery\Services\StoreContext($pdo, $storeSvc);
                $accessibleStores = $ctx->accessibleStores();
                if (!isset($currentStore)) {
                    $currentStore = $ctx->currentStore();
                }
            } catch (\Throwable) {
                $accessibleStores = $accessibleStores ?? [];
            }
        }
        $accessibleStores = $accessibleStores ?? [];
        $currentStore = $currentStore ?? null;

        $templateFile = DELIVERY_ROOT . '/templates/' . ltrim($template, '/');
        include DELIVERY_ROOT . '/templates/layout.php';
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        $token = (string) $_SESSION['csrf'];
        // Mantém cookie CSRF alinhado ao token da sessão
        if (function_exists('delivery_set_cookie')) {
            delivery_set_cookie('DELIVERY_CSRF', $token);
        }
        return $token;
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::e(self::csrfToken()) . '">';
    }

    public static function checkCsrf(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }
        $sessionToken = isset($_SESSION['csrf']) ? (string) $_SESSION['csrf'] : '';
        $cookieToken = isset($_COOKIE['DELIVERY_CSRF']) ? (string) $_COOKIE['DELIVERY_CSRF'] : '';

        if ($sessionToken !== '' && hash_equals($sessionToken, $token)) {
            return true;
        }
        // Double-submit: válido se o POST bate com o cookie (sessão em disco pode falhar no host)
        if ($cookieToken !== '' && hash_equals($cookieToken, $token)) {
            $_SESSION['csrf'] = $cookieToken;
            return true;
        }
        return false;
    }
}
