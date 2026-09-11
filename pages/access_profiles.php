<?php
declare(strict_types=1);

use Delivery\Services\AccessProfileService;
use Delivery\Utils\Auth;
use Delivery\Utils\View;

Auth::requirePermission('profiles.manage');

$svc = new AccessProfileService($pdo);
$svc->ensureSchema();
$svc->seedDefaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && View::checkCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save') {
        $id = (int) ($_POST['id'] ?? 0) ?: null;
        $name = (string) ($_POST['name'] ?? '');
        $description = (string) ($_POST['description'] ?? '');
        $active = isset($_POST['active']);
        $keys = $_POST['permissions'] ?? [];
        if (!is_array($keys)) {
            $keys = [];
        }
        try {
            $result = $svc->save($name, $description, $keys, $id, $active);
            // Atualiza permissões da sessão se o perfil editado for o do usuário atual
            $me = Auth::user();
            if ($me && (int) ($me['profile_id'] ?? 0) === (int) $result['id']) {
                Auth::setPermissions($svc->permissionsForUser($me));
            }
            View::redirect(
                '/perfis?edit=' . (int) $result['id'],
                $result['created'] ? 'Perfil criado.' : 'Perfil atualizado.'
            );
        } catch (Throwable $e) {
            $qs = $id ? ('?edit=' . $id) : '';
            View::redirect('/perfis' . $qs, $e->getMessage(), 'danger');
        }
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $svc->delete($id);
            View::redirect('/perfis', 'Perfil excluído.');
        } catch (Throwable $e) {
            View::redirect('/perfis', $e->getMessage(), 'danger');
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId > 0 ? $svc->find($editId) : null;
$editPerms = $edit ? $svc->permissionsOf((int) $edit['id']) : [];
$list = $svc->listProfiles(false);
$catalog = AccessProfileService::catalog();

View::render('Perfis de acesso', 'pages/access_profiles.php', [
    'activeNav' => 'profiles',
    'pageSubtitle' => 'Defina quais telas e configurações cada perfil pode usar',
    'list' => $list,
    'edit' => $edit,
    'editPerms' => $editPerms,
    'catalog' => $catalog,
]);
