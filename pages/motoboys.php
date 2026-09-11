<?php
declare(strict_types=1);

use Delivery\Services\UserAccountService;
use Delivery\Utils\Auth;
use Delivery\Utils\View;

$me = Auth::user();
$isAdmin = ($me['role'] ?? '') === 'admin';
$accounts = new UserAccountService($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && View::checkCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? 'create');

    if ($action === 'create' || $action === 'update') {
        $id = $action === 'update' ? (int) ($_POST['id'] ?? 0) : null;
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        // Cadastro exige senha; edição com senha vazia = manter
        if ($action === 'create' && strlen($pass) < 6) {
            View::redirect('/motoboys', 'Defina uma senha com no mínimo 6 caracteres.', 'danger');
        }
        $passArg = $action === 'create'
            ? $pass
            : ($pass !== '' ? $pass : null);

        try {
            $result = $accounts->save(
                'motoboy',
                $name,
                $email,
                $phone !== '' ? $phone : null,
                $passArg,
                ($id !== null && $id > 0) ? $id : null,
                true
            );
            if ($result['created']) {
                View::redirect('/motoboys', 'Motoboy cadastrado.');
            }
            View::redirect('/motoboys', 'Motoboy atualizado' . ($action === 'create' ? ' (e-mail já existia — dados salvos).' : '.'));
        } catch (Throwable $e) {
            $editQs = ($id !== null && $id > 0) ? ('?edit=' . $id) : '';
            // Se e-mail já é motoboy e veio do create, abrir edição
            if ($action === 'create') {
                $existing = $accounts->findByEmail($email);
                if ($existing && (string) $existing['role'] === 'motoboy') {
                    View::redirect('/motoboys?edit=' . (int) $existing['id'], $e->getMessage(), 'danger');
                }
            }
            View::redirect('/motoboys' . $editQs, $e->getMessage(), 'danger');
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE users SET active = IF(active=1,0,1) WHERE id=? AND role=?')
                ->execute([$id, 'motoboy']);
            View::redirect('/motoboys', 'Status do motoboy atualizado.');
        }
        View::redirect('/motoboys', 'Motoboy inválido.', 'danger');
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $st = $pdo->prepare("SELECT id, name, email, phone, active FROM users WHERE id=? AND role='motoboy' LIMIT 1");
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
}

$list = $pdo->query("SELECT id, name, email, phone, active FROM users WHERE role='motoboy' ORDER BY name")->fetchAll();

View::render('Motoboys', 'pages/motoboys.php', [
    'activeNav' => 'motoboys',
    'pageSubtitle' => 'Cadastrar e editar entregadores do app',
    'list' => $list,
    'edit' => $edit,
    'isAdmin' => $isAdmin,
]);
