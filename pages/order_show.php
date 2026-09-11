<?php
declare(strict_types=1);

use Delivery\Services\ApiAuth;
use Delivery\Services\OrderService;
use Delivery\Services\StoreContext;
use Delivery\Services\StoreService;
use Delivery\Utils\Auth;
use Delivery\Utils\View;
use Delivery\Utils\WhatsApp;

$svc = new OrderService($pdo);
$id = (int) ($_GET['id'] ?? 0);
$order = $svc->find($id);
if (!$order) {
    View::redirect('/pedidos', 'Pedido não encontrado.', 'danger');
}

$storeSvc = new StoreService($pdo);
$storeSvc->ensureSchema();
$ctx = new StoreContext($pdo, $storeSvc);
$ctx->assertCanAccessOrder($order);

$motoboys = $pdo->query("SELECT id, name FROM users WHERE role='motoboy' AND active=1 ORDER BY name")->fetchAll();
$orderStoreId = (int) ($order['store_id'] ?? 1);
$store = $storeSvc->find($orderStoreId) ?: [];
if ($store === []) {
    try {
        $store = $pdo->query('SELECT * FROM store_settings WHERE id=1')->fetch() ?: [];
    } catch (Throwable) {
        $store = [];
    }
}
if (!isset($store['legal_name']) && isset($store['name'])) {
    $store['legal_name'] = $store['name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && View::checkCsrf($_POST['csrf_token'] ?? null)) {
    $ctx->assertCanAccessOrder($order);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'confirm_pix') {
        $svc->markPaid($id, 'PIX-ADMIN-' . time());
        View::redirect('/pedidos/ver?id=' . $id, 'Pagamento PIX confirmado.');
    }
    if ($action === 'status') {
        $st = (string) ($_POST['status'] ?? '');
        $moto = (int) ($_POST['motoboy_id'] ?? 0) ?: null;
        try {
            $svc->setStatus($id, $st, $moto, (string) (Auth::user()['role'] ?? 'ops'));
            View::redirect('/pedidos/ver?id=' . $id, 'Status atualizado.');
        } catch (Throwable $e) {
            View::redirect('/pedidos/ver?id=' . $id, $e->getMessage(), 'danger');
        }
    }
    if ($action === 'admin_confirm_delivery') {
        try {
            $svc->adminConfirmDelivery($id, (string) ($_POST['note'] ?? ''));
            View::redirect('/pedidos/ver?id=' . $id, 'Entrega confirmada pela loja.');
        } catch (Throwable $e) {
            View::redirect('/pedidos/ver?id=' . $id, $e->getMessage(), 'danger');
        }
    }
    if ($action === 'admin_reopen_delivery') {
        try {
            $svc->adminReopenDelivery($id, (string) ($_POST['note'] ?? ''));
            View::redirect('/pedidos/ver?id=' . $id, 'Entrega reaberta — motoboy pode resolver na rota.');
        } catch (Throwable $e) {
            View::redirect('/pedidos/ver?id=' . $id, $e->getMessage(), 'danger');
        }
    }
    if ($action === 'destination') {
        $addr = trim((string) ($_POST['delivery_address'] ?? ''));
        $lat = (float) str_replace(',', '.', (string) ($_POST['delivery_lat'] ?? ''));
        $lng = (float) str_replace(',', '.', (string) ($_POST['delivery_lng'] ?? ''));
        try {
            $svc->updateDestination($id, $addr, $lat, $lng);
            View::redirect('/pedidos/ver?id=' . $id, 'Destino atualizado no mapa.');
        } catch (Throwable $e) {
            View::redirect('/pedidos/ver?id=' . $id, $e->getMessage(), 'danger');
        }
    }
}

$token = (new ApiAuth($pdo))->issueToken((int) Auth::user()['id'], 1);
$trackUrl = rtrim((string) delivery_env('APP_URL', View::path('/')), '/') . '/rastreio?code=' . urlencode((string) $order['code']);
$waTextCliente = 'Olá! Seu pedido *' . $order['code'] . '* da ' . ($store['legal_name'] ?? $store['name'] ?? 'loja')
    . ".\nStatus: " . ($order['status_label'] ?? $order['status'])
    . "\nRastreio: " . $trackUrl;
$waLinkCliente = WhatsApp::link((string) ($order['customer_phone'] ?? ''), $waTextCliente);
$waLinkLoja = WhatsApp::storeLink($store, 'Pedido ' . $order['code']);

View::render('Pedido ' . $order['code'], 'pages/order_show.php', [
    'activeNav' => 'orders',
    'order' => $order,
    'motoboys' => $motoboys,
    'mapToken' => $token,
    'apiBase' => rtrim(delivery_env('API_URL', View::path('/api')), '/'),
    'trackUrl' => $trackUrl,
    'waLink' => $waLinkCliente,
    'waLinkCliente' => $waLinkCliente,
    'waLinkLoja' => $waLinkLoja,
    'store' => $store,
    'chatEnabled' => !empty($order['motoboy_id']),
]);
