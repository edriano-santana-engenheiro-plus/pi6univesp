<?php
declare(strict_types=1);

use Delivery\Services\AccessProfileService;
use Delivery\Services\AuthRateLimiter;
use Delivery\Utils\Auth;
use Delivery\Utils\View;

if (Auth::check()) {
    View::redirect('/');
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!View::checkCsrf($_POST['csrf_token'] ?? null)) {
        unset($_SESSION['csrf']);
        $error = 'Sessão expirada ou cookie bloqueado. Atualize a página (F5) e tente entrar de novo.';
    } else {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        try {
            AuthRateLimiter::forDeliveryRoot(DELIVERY_ROOT)->assertAllowed('panel_login', $ip, $email);
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
        if ($error === null) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email=? AND active=1 LIMIT 1');
            $stmt->execute([$email]);
            $u = $stmt->fetch();
            if ($u && password_verify($pass, $u['password_hash']) && in_array($u['role'], ['admin', 'atendente'], true)) {
                AuthRateLimiter::forDeliveryRoot(DELIVERY_ROOT)->clear('panel_login', $ip, $email);
                $profiles = new AccessProfileService($pdo);
                $profiles->ensureSchema();
                $profiles->seedDefaults();
                $perms = $profiles->permissionsForUser($u);
                Auth::login($u, $perms);
                View::redirect('/', 'Bem-vindo ao painel.');
            }
            $error = 'Login inválido ou sem permissão de painel.';
        }
    }
}

View::render('Entrar', 'pages/login.php', [
    'activeNav' => '',
    'error' => $error,
    'currentUser' => null,
    'hideSiteFooter' => true,
]);
