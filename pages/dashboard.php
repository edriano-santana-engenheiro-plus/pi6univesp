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
$current = $ctx->currentStore();

$ordersSvc = new OrderService($pdo);

$st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE store_id=? AND DATE(created_at)=CURDATE()");
$st->execute([$storeId]);
$hoje = (int) $st->fetchColumn();

$st = $pdo->prepare(
    "SELECT COUNT(*) FROM orders WHERE store_id=? AND status IN ('pago','preparando','pronto','saiu_entrega','aguardando_pagamento','entrega_informada','entrega_contestada')"
);
$st->execute([$storeId]);
$abertos = (int) $st->fetchColumn();

$st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE store_id=? AND status='saiu_entrega'");
$st->execute([$storeId]);
$rota = (int) $st->fetchColumn();

$st = $pdo->prepare(
    "SELECT COALESCE(SUM(total),0) FROM orders WHERE store_id=? AND payment_status='aprovado' AND DATE(created_at)=CURDATE()"
);
$st->execute([$storeId]);
$faturamento = (float) $st->fetchColumn();

$kpis = [
    'hoje' => $hoje,
    'abertos' => $abertos,
    'rota' => $rota,
    'faturamento' => $faturamento,
];

$delayInfo = $ordersSvc->delayedOpenOrders(20, $storeId);
$kpis['atrasados'] = (int) ($delayInfo['count'] ?? 0);

$st = $pdo->prepare(
    "SELECT o.id, o.code, o.status, o.total, o.created_at, u.name AS customer_name
     FROM orders o JOIN users u ON u.id=o.customer_id
     WHERE o.store_id=?
     ORDER BY o.id DESC LIMIT 8"
);
$st->execute([$storeId]);
$recent = $st->fetchAll() ?: [];

View::render('Dashboard', 'pages/dashboard.php', [
    'activeNav' => 'dashboard',
    'pageSubtitle' => 'Pedidos, cozinha e entregas — ' . (string) ($current['name'] ?? 'loja'),
    'kpis' => $kpis,
    'recent' => $recent,
    'delayInfo' => $delayInfo,
    'delayAlerts' => $delayInfo,
    'currentStore' => $current,
]);