<?php
declare(strict_types=1);

use Delivery\Services\OrderService;
use Delivery\Utils\StatusLabels;
use Delivery\Utils\View;

$svc = new OrderService($pdo);
$id = (int) ($_GET['id'] ?? 0);
$order = $svc->find($id);
if (!$order) {
    View::redirect('/pedidos', 'Pedido não encontrado.', 'danger');
}

$store = $pdo->query('SELECT * FROM store_settings WHERE id=1')->fetch();

// Layout mínimo para impressão (sem sidebar)
$pageTitle = 'Comanda ' . $order['code'];
$appName = delivery_env('APP_NAME', 'Delivery');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e($pageTitle) ?></title>
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
    <style>
      body { background: #fff; }
      .ticket { max-width: 360px; margin: 1rem auto; font-family: "Consolas", "Courier New", monospace; }
      .ticket h1 { font-size: 1.1rem; margin: 0 0 0.35rem; text-align: center; }
      .ticket .muted { color: #64748b; font-size: 0.8rem; text-align: center; }
      .ticket hr { border: 0; border-top: 1px dashed #94a3b8; margin: 0.75rem 0; }
      .ticket table { width: 100%; font-size: 0.9rem; }
      .ticket td { padding: 0.2rem 0; vertical-align: top; }
      .ticket .tot { font-weight: 700; font-size: 1.05rem; }
      .no-print { text-align: center; margin: 1rem; }
      @media print {
        .no-print { display: none !important; }
        .ticket { margin: 0; max-width: none; }
      }
    </style>
</head>
<body>
<div class="no-print">
    <button class="btn btn-primary" type="button" onclick="window.print()">Imprimir comanda</button>
    <a class="btn" href="<?= View::path('/pedidos/ver?id=' . (int) $order['id']) ?>">Voltar</a>
</div>
<div class="ticket">
    <h1><?= View::e($store['legal_name'] ?? 'Delivery') ?></h1>
    <div class="muted"><?= View::e($store['address'] ?? 'Itamogi/MG') ?></div>
    <div class="muted"><?= View::e($store['phone'] ?? '') ?></div>
    <hr>
    <div><strong>Pedido:</strong> <?= View::e($order['code']) ?></div>
    <div><strong>Status:</strong> <?= View::e(StatusLabels::order((string) $order['status'])) ?></div>
    <div><strong>Cliente:</strong> <?= View::e($order['customer_name']) ?></div>
    <div><strong>Fone:</strong> <?= View::e($order['customer_phone'] ?? '—') ?></div>
    <div><strong>Quando:</strong> <?= View::e((string) $order['created_at']) ?></div>
    <hr>
    <table>
        <?php foreach ($order['items'] as $it): ?>
        <tr>
            <td><?= (int) $it['qty'] ?>× <?= View::e($it['product_name']) ?></td>
            <td style="text-align:right">R$ <?= number_format((float) $it['unit_price'] * (int) $it['qty'], 2, ',', '.') ?></td>
        </tr>
        <?php if (!empty($it['notes'])): ?>
        <tr><td colspan="2" class="muted">↳ <?= View::e($it['notes']) ?></td></tr>
        <?php endif; ?>
        <?php endforeach; ?>
    </table>
    <hr>
    <div>Subtotal: R$ <?= number_format((float) $order['subtotal'], 2, ',', '.') ?></div>
    <div>Entrega: R$ <?= number_format((float) $order['delivery_fee'], 2, ',', '.') ?></div>
    <div class="tot">TOTAL: R$ <?= number_format((float) $order['total'], 2, ',', '.') ?></div>
    <div>Pagamento: <?= View::e($order['payment_method']) ?> / <?= View::e(StatusLabels::payment((string) $order['payment_status'])) ?></div>
    <hr>
    <div><strong>Entregar em:</strong></div>
    <div><?= View::e($order['delivery_address'] ?? '—') ?></div>
    <?php if (!empty($order['notes'])): ?>
    <hr>
    <div><strong>Obs:</strong> <?= View::e($order['notes']) ?></div>
    <?php endif; ?>
    <hr>
    <div class="muted">Itamogi/MG · <?= date('d/m/Y H:i') ?></div>
</div>
<script>window.addEventListener('load', function () { /* pronto para imprimir */ });</script>
</body>
</html>
<?php
exit;
