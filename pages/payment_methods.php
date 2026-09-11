<?php
declare(strict_types=1);

use Delivery\Services\PaymentMethodService;
use Delivery\Services\PaymentService;
use Delivery\Services\StoreContext;
use Delivery\Services\StoreService;
use Delivery\Utils\Auth;
use Delivery\Utils\PixKey;
use Delivery\Utils\View;

Auth::requireRoles(['admin', 'atendente']);

$storeSvc = new StoreService($pdo);
$storeSvc->ensureSchema();
$ctx = new StoreContext($pdo, $storeSvc);
$storeId = $ctx->requireStoreId();
$canWrite = Auth::can('settings.write');

$svc = new PaymentMethodService($pdo);
$svc->ensureSchema();
$error = null;
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = null;
$pixKeyType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && View::checkCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_pix' && $canWrite) {
            $rawKey = trim((string) ($_POST['pix_key'] ?? ''));
            $merchantName = trim((string) ($_POST['pix_merchant_name'] ?? ''));
            $merchantCity = trim((string) ($_POST['pix_merchant_city'] ?? ''));
            $mpToken = trim((string) ($_POST['mp_access_token'] ?? ''));

            $pixKey = null;
            if ($rawKey !== '') {
                $check = PixKey::validate($rawKey);
                if (!$check['ok']) {
                    throw new InvalidArgumentException((string) $check['message']);
                }
                $pixKey = $check['key'];
                $pixKeyType = $check['type'];
            }

            $storeSvc->update($storeId, [
                'pix_key' => $pixKey,
                'pix_merchant_name' => $merchantName !== '' ? PixKey::merchantName($merchantName) : null,
                'pix_merchant_city' => $merchantCity !== '' ? PixKey::merchantCity($merchantCity) : null,
                'mp_access_token' => $mpToken !== '' ? $mpToken : null,
            ]);
            View::redirect('/formas-pagamento', 'Configuração de pagamento salva. A chave PIX será usada nas próximas transações.');
        }
        if ($action === 'save') {
            $id = (int) ($_POST['id'] ?? 0);
            $svc->save(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['code'] ?? ''),
                (string) ($_POST['type'] ?? 'other'),
                (string) ($_POST['instructions'] ?? ''),
                (int) ($_POST['sort_order'] ?? 100),
                !empty($_POST['active']),
                $id > 0 ? $id : null
            );
            View::redirect('/formas-pagamento', $id > 0 ? 'Forma atualizada.' : 'Forma cadastrada.');
        }
        if ($action === 'toggle') {
            $svc->toggle((int) ($_POST['id'] ?? 0));
            View::redirect('/formas-pagamento', 'Status atualizado.');
        }
        if ($action === 'delete') {
            $svc->delete((int) ($_POST['id'] ?? 0));
            View::redirect('/formas-pagamento', 'Forma removida.');
        }
    } catch (InvalidArgumentException|RuntimeException $e) {
        $error = $e->getMessage();
        $editId = (int) ($_POST['id'] ?? 0);
    }
}

$store = $storeSvc->find($storeId) ?: [];
$paySvc = new PaymentService($pdo);
$pixCfg = $paySvc->resolveConfig($storeId);
if (!empty($pixCfg['pix_key']) && $pixCfg['pix_key'] !== 'loja@delivery.local') {
    $pixKeyType = PixKey::validate((string) $pixCfg['pix_key'])['type'] ?? null;
}

$methods = $svc->listAll($storeId);
if ($editId > 0) {
    foreach ($methods as $m) {
        if ((int) $m['id'] === $editId) {
            $editing = $m;
            break;
        }
    }
}

View::render('Pagamentos', 'pages/payment_methods.php', [
    'activeNav' => 'payments',
    'methods' => $methods,
    'editing' => $editing,
    'error' => $error,
    'store' => $store,
    'pixCfg' => $pixCfg,
    'pixKeyType' => $pixKeyType,
    'canWrite' => $canWrite,
    'webhookUrl' => rtrim((string) delivery_env('APP_URL'), '/') . '/api/webhooks/mercadopago',
]);
