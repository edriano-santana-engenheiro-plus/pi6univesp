<?php
declare(strict_types=1);

use Delivery\Services\OrderService;
use Delivery\Services\StoreContext;
use Delivery\Utils\Auth;

@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', 'off');
while (ob_get_level() > 0) {
    ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

$user = Auth::user();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'atendente'], true)) {
    echo "event: error\ndata: {\"ok\":false,\"message\":\"Não autenticado\"}\n\n";
    flush();
    exit;
}

$storeId = isset($_SESSION[StoreContext::SESSION_KEY])
    ? (int) $_SESSION[StoreContext::SESSION_KEY]
    : 0;

session_write_close();

echo "retry: 3000\n\n";
flush();

$ordersSvc = new OrderService($pdo);
$todayStart = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
$storeFilter = $storeId > 0 ? ' AND store_id = ?' : '';
$statsStmt = $pdo->prepare(
    "SELECT
        SUM(CASE WHEN status IN ('pago','preparando','pronto','saiu_entrega','aguardando_pagamento','entrega_informada','entrega_contestada') THEN 1 ELSE 0 END) AS abertos,
        SUM(CASE WHEN status='saiu_entrega' THEN 1 ELSE 0 END) AS rota,
        SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS hoje,
        SUM(CASE WHEN payment_status='aprovado' AND created_at >= ? AND created_at < ? THEN total ELSE 0 END) AS faturamento
     FROM orders
     WHERE 1=1" . $storeFilter
);
$ultimoStmt = $pdo->prepare(
    "SELECT o.id, o.code, o.status, o.total, o.updated_at, o.created_at, u.name AS customer_name
     FROM orders o JOIN users u ON u.id=o.customer_id
     WHERE 1=1" . ($storeId > 0 ? ' AND o.store_id=?' : '') . "
     ORDER BY o.id DESC LIMIT 1"
);

$lastSig = '';
$started = time();
while (time() - $started < 55) {
    if (connection_aborted()) {
        break;
    }
    if ($storeId > 0) {
        $statsStmt->execute([$todayStart, $tomorrowStart, $todayStart, $tomorrowStart, $storeId]);
        $ultimoStmt->execute([$storeId]);
    } else {
        $statsStmt->execute([$todayStart, $tomorrowStart, $todayStart, $tomorrowStart]);
        $ultimoStmt->execute();
    }
    $stats = $statsStmt->fetch() ?: [];
    $ultimo = $ultimoStmt->fetch() ?: null;

    $delay = $ordersSvc->delayedOpenOrders(20, $storeId > 0 ? $storeId : null);

    $snap = [
        'ts' => date('c'),
        'store_id' => $storeId,
        'abertos' => (int) ($stats['abertos'] ?? 0),
        'rota' => (int) ($stats['rota'] ?? 0),
        'hoje' => (int) ($stats['hoje'] ?? 0),
        'faturamento' => (float) ($stats['faturamento'] ?? 0),
        'atrasados' => (int) ($delay['count'] ?? 0),
        'delay' => $delay,
        'ultimo' => $ultimo,
    ];
    $sig = md5(json_encode($snap));
    if ($sig !== $lastSig) {
        $lastSig = $sig;
        echo "event: orders\n";
        echo 'data: ' . json_encode(['ok' => true, 'snapshot' => $snap], JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    } else {
        echo ": ping\n\n";
        flush();
    }
    sleep(2);
}
echo "event: bye\ndata: {\"ok\":true}\n\n";
flush();
exit;
