<?php
declare(strict_types=1);

use Delivery\Services\ApiAuth;
use Delivery\Services\StoreContext;
use Delivery\Services\StoreService;
use Delivery\Utils\Auth;
use Delivery\Utils\View;

$storeSvc = new StoreService($pdo);
$storeSvc->ensureSchema();
$ctx = new StoreContext($pdo, $storeSvc);
$storeId = $ctx->requireStoreId();

$st = $pdo->prepare(
    "SELECT o.id, o.code, o.delivery_address, o.delivery_lat, o.delivery_lng, o.status, m.name AS motoboy_name
     FROM orders o
     LEFT JOIN users m ON m.id = o.motoboy_id
     WHERE o.status = 'saiu_entrega' AND o.store_id = ?
     ORDER BY o.id DESC"
);
$st->execute([$storeId]);
$live = $st->fetchAll() ?: [];

$token = (new ApiAuth($pdo))->issueToken((int) Auth::user()['id'], 1);
$apiBase = rtrim(delivery_env('API_URL', View::path('/api')), '/');

$focusId = (int) ($_GET['id'] ?? ($live[0]['id'] ?? 0));
if ($focusId > 0) {
    $ord = $pdo->prepare('SELECT store_id, delivery_geo_source FROM orders WHERE id=?');
    $ord->execute([$focusId]);
    $row = $ord->fetch() ?: null;
    if ($row && !$ctx->userCanAccessStoreId((int) ($row['store_id'] ?? 0))) {
        $focusId = (int) ($live[0]['id'] ?? 0);
        $destLocked = false;
    } else {
        $destLocked = ((string) ($row['delivery_geo_source'] ?? '')) === 'gps';
    }
} else {
    $destLocked = false;
}

View::render('Entregas ao vivo', 'pages/deliveries.php', [
    'activeNav' => 'deliveries',
    'pageSubtitle' => 'Mapa e status das entregas em andamento',
    'live' => $live,
    'mapToken' => $token,
    'apiBase' => $apiBase,
    'focusId' => $focusId,
    'destLocked' => $destLocked,
]);
