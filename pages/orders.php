<?php
declare(strict_types=1);

use Delivery\Services\OrderService;
use Delivery\Services\StoreContext;
use Delivery\Services\StoreService;
use Delivery\Utils\View;

$storeSvc = new StoreService($pdo);
$storeSvc->ensureSchema();
$ctx = new StoreContext($pdo, $storeSvc);
$storeId = $ctx->requireStoreId();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$svc = new OrderService($pdo);
$svc->ensureProntoStatusEnum();

$allowed = [
    'aguardando_pagamento', 'pago', 'preparando', 'pronto',
    'saiu_entrega', 'entrega_informada', 'entrega_contestada',
    'entregue', 'cancelado',
];
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : null;
if ($status === '' || ($status !== null && !in_array($status, $allowed, true))) {
    $status = null;
}

$list = $svc->listRecent(80, $status, $storeId);
$statusCounts = $svc->countByStatus($storeId);
$delayInfo = $svc->delayedOpenOrders(100, $storeId);
$delayedIds = [];
foreach ($delayInfo['orders'] as $d) {
    $delayedIds[(int) $d['id']] = $d;
}

$current = $ctx->currentStore();

View::render('Pedidos', 'pages/orders.php', [
    'activeNav' => 'orders',
    'pageSubtitle' => 'Pedidos — ' . (string) ($current['name'] ?? 'loja'),
    'list' => $list,
    'status' => $status,
    'statusCounts' => $statusCounts,
    'delayInfo' => $delayInfo,
    'delayedIds' => $delayedIds,
    'delayAlerts' => $delayInfo,
    'currentStore' => $current,
]);
