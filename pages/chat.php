<?php
declare(strict_types=1);

use Delivery\Services\ApiAuth;
use Delivery\Services\ChatService;
use Delivery\Services\StoreContext;
use Delivery\Services\StoreService;
use Delivery\Utils\Auth;
use Delivery\Utils\View;

$storeSvc = new StoreService($pdo);
$storeSvc->ensureSchema();
$ctx = new StoreContext($pdo, $storeSvc);
$storeId = $ctx->isPlatformAdmin() ? null : $ctx->requireStoreId();

$chat = new ChatService($pdo);
$chat->ensureSchema();
$threads = $chat->listThreads(50, $storeId);
$unread = $chat->unreadFromMotoboyTotal();
$token = (new ApiAuth($pdo))->issueToken((int) Auth::user()['id'], 2);

View::render('Chat com motoboys', 'pages/chat.php', [
    'activeNav' => 'chat',
    'pageSubtitle' => 'Mensagens por pedido com os entregadores',
    'threads' => $threads,
    'unread' => $unread,
    'mapToken' => $token,
    'apiBase' => rtrim(delivery_env('API_URL', View::path('/api')), '/'),
    'delayAlerts' => null,
]);
