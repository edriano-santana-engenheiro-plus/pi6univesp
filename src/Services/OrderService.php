<?php
declare(strict_types=1);

namespace Delivery\Services;

use Delivery\Utils\StatusLabels;
use Delivery\Utils\StatusTransition;
use PDO;

final class OrderService
{
    public function __construct(
        private PDO $pdo,
        private ?DeliveryFeeService $fees = null,
        private ?OrderEventService $events = null,
        private ?PushService $push = null,
        private ?StoreService $stores = null,
    ) {
        $this->stores ??= new StoreService($pdo);
        $this->fees ??= new DeliveryFeeService($pdo, $this->stores);
        $this->events ??= new OrderEventService($pdo);
        $this->push ??= new PushService($pdo);
    }

    public function nextCode(): string
    {
        // D + yymmdd + 8 hex (~4e9 combinações/dia) — dificulta enumeração do rastreio público
        return 'D' . date('ymd') . strtoupper(bin2hex(random_bytes(4)));
    }

    public function assertStoreOpen(int $storeId = 1): void
    {
        $store = $this->stores->findActive($storeId);
        if (!$store) {
            throw new \RuntimeException('Loja indisponível.');
        }
        if ((int) ($store['open_now'] ?? 0) !== 1) {
            throw new \RuntimeException('A loja está fechada no momento.');
        }
    }

    /**
     * @param list<array{product_id:int,qty:int,notes?:string}> $items
     */
    public function create(
        int $customerId,
        array $items,
        string $paymentMethod,
        ?array $address,
        ?string $notes,
        int $storeId = 1,
    ): array {
        $this->assertStoreOpen($storeId);
        if ($items === []) {
            throw new \InvalidArgumentException('Carrinho vazio.');
        }

        $lat = null;
        $lng = null;
        $geoSource = null;
        $addrText = null;
        if ($address) {
            $addrText = trim(
                ($address['street'] ?? '') . ', ' . ($address['number'] ?? '')
                . ' — ' . ($address['neighborhood'] ?? '') . ', ' . ($address['city'] ?? 'Itamogi')
            );
            $lat = isset($address['lat']) ? (float) $address['lat'] : null;
            $lng = isset($address['lng']) ? (float) $address['lng'] : null;
            $src = strtolower(trim((string) ($address['geo_source'] ?? '')));
            if (in_array($src, ['gps', 'address', 'admin'], true)) {
                $geoSource = $src;
            } elseif ($lat !== null && $lng !== null) {
                $geoSource = 'address';
            }
        }

        $quote = $this->fees->quote(
            $lat,
            $lng,
            $storeId,
            isset($address['city']) ? (string) $address['city'] : null
        );
        if (!$quote['within_range']) {
            throw new \InvalidArgumentException(
                (string) ($quote['message'] ?? 'Fora da área urbana do município da loja. Pedido bloqueado.')
            );
        }
        $fee = (float) $quote['fee'];

        $store = $this->stores->find($storeId) ?: [];
        $minOrder = (float) ($store['min_order'] ?? 0);
        $ids = [];
        $qtyById = [];
        $notesById = [];
        foreach ($items as $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            // Ignora preços enviados pelo cliente — só qty/product_id
            $qty = max(1, min(99, (int) ($it['qty'] ?? 1)));
            $ids[$pid] = $pid;
            $qtyById[$pid] = ($qtyById[$pid] ?? 0) + $qty;
            if (!isset($notesById[$pid]) && !empty($it['notes'])) {
                $notesById[$pid] = mb_substr((string) $it['notes'], 0, 200);
            }
        }
        if ($ids === []) {
            throw new \InvalidArgumentException('Carrinho vazio.');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, name, price FROM products WHERE active=1 AND store_id = ? AND id IN ($in)"
        );
        $stmt->execute(array_merge([$storeId], array_values($ids)));
        $found = [];
        foreach ($stmt->fetchAll() as $p) {
            $found[(int) $p['id']] = $p;
        }
        $subtotal = 0.0;
        $lines = [];
        $sentPriceByPid = [];
        foreach ($items as $raw) {
            $rpid = (int) ($raw['product_id'] ?? 0);
            if ($rpid <= 0) {
                continue;
            }
            if (isset($raw['unit_price']) || isset($raw['price'])) {
                $sentPriceByPid[$rpid] = (float) ($raw['unit_price'] ?? $raw['price']);
            }
        }
        foreach ($qtyById as $pid => $qty) {
            if (!isset($found[$pid])) {
                throw new \InvalidArgumentException('Produto inválido para esta loja.');
            }
            $p = $found[$pid];
            $unit = (float) $p['price'];
            if (!is_finite($unit) || $unit < 0 || $unit > 100000) {
                throw new \InvalidArgumentException('Preço de produto inválido no cardápio.');
            }
            if (isset($sentPriceByPid[$pid])) {
                $sent = $sentPriceByPid[$pid];
                if (is_finite($sent) && abs($sent - $unit) > 0.05) {
                    throw new \InvalidArgumentException(
                        'Preço inválido na comanda (não confere com o cardápio).'
                    );
                }
            }
            $subtotal += $unit * $qty;
            $lines[] = [
                'product_id' => $pid,
                'product_name' => (string) $p['name'],
                'unit_price' => $unit,
                'qty' => $qty,
                'notes' => (string) ($notesById[$pid] ?? ''),
            ];
        }
        $subtotal = round($subtotal, 2);
        if ($subtotal < $minOrder) {
            throw new \InvalidArgumentException('Pedido mínimo: R$ ' . number_format($minOrder, 2, ',', '.'));
        }

        $this->fees->assertFeeWithinProductTriple($subtotal, $fee);
        $total = round($subtotal + $fee, 2);
        $code = $this->nextCode();

        $this->pdo->beginTransaction();
        try {
            if ($this->hasGeoSourceColumn()) {
                $ins = $this->pdo->prepare(
                    'INSERT INTO orders (store_id, code, customer_id, status, payment_method, payment_status, subtotal, delivery_fee, total, notes, delivery_lat, delivery_lng, delivery_address, delivery_geo_source, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
                );
                $ins->execute([
                    $storeId, $code, $customerId, 'aguardando_pagamento', $paymentMethod, 'pendente',
                    $subtotal, $fee, $total, $notes, $lat, $lng, $addrText, $geoSource,
                ]);
            } else {
                $ins = $this->pdo->prepare(
                    'INSERT INTO orders (store_id, code, customer_id, status, payment_method, payment_status, subtotal, delivery_fee, total, notes, delivery_lat, delivery_lng, delivery_address, created_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())'
                );
                $ins->execute([
                    $storeId, $code, $customerId, 'aguardando_pagamento', $paymentMethod, 'pendente',
                    $subtotal, $fee, $total, $notes, $lat, $lng, $addrText,
                ]);
            }
            $orderId = (int) $this->pdo->lastInsertId();
            $itemIns = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, unit_price, qty, notes) VALUES (?,?,?,?,?,?)'
            );
            foreach ($lines as $line) {
                $itemIns->execute([$orderId, $line['product_id'], $line['product_name'], $line['unit_price'], $line['qty'], $line['notes'] ?: null]);
            }
            $this->events->add($orderId, 'criado', 'Pedido criado — aguardando pagamento', [
                'distance_km' => $quote['distance_km'],
                'delivery_fee' => $fee,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $order = $this->find($orderId);
        if ($order) {
            $order['delivery_quote'] = $quote;
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Novo pedido ' . $order['code'],
                'Total R$ ' . number_format((float) $order['total'], 2, ',', '.') . ' — aguardando pagamento'
            );
        }
        return $order;
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.*, u.name AS customer_name, u.phone AS customer_phone,
                    m.name AS motoboy_name,
                    s.name AS store_name, s.lat AS store_lat, s.lng AS store_lng,
                    s.address AS store_address, s.phone AS store_phone
             FROM orders o
             JOIN users u ON u.id = o.customer_id
             LEFT JOIN users m ON m.id = o.motoboy_id
             LEFT JOIN stores s ON s.id = o.store_id
             WHERE o.id = ?'
        );
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }
        $items = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id=?');
        $items->execute([$id]);
        $order['items'] = $items->fetchAll();
        $order['status_label'] = StatusLabels::order((string) $order['status']);
        $order['payment_status_label'] = StatusLabels::payment((string) $order['payment_status']);
        $order['events'] = $this->events->forOrder($id);
        if (!empty($order['store_id'])) {
            $order['store'] = [
                'id' => (int) $order['store_id'],
                'name' => (string) ($order['store_name'] ?? ''),
                'lat' => $order['store_lat'] ?? null,
                'lng' => $order['store_lng'] ?? null,
                'address' => $order['store_address'] ?? null,
                'phone' => $order['store_phone'] ?? null,
            ];
        }
        return $order;
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM orders WHERE code=?');
        $stmt->execute([$code]);
        $id = (int) $stmt->fetchColumn();
        return $id > 0 ? $this->find($id) : null;
    }

    /** Confirma pagamento de forma atômica. Retorna false se já estava pago. */
    public function markPaid(int $orderId, string $ref = ''): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE orders SET payment_status='aprovado', status='pago', payment_ref=?, paid_at=NOW(), updated_at=NOW()
             WHERE id=? AND payment_status='pendente'
               AND status NOT IN ('cancelado','entregue')"
        );
        $stmt->execute([$ref ?: null, $orderId]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->events->add($orderId, 'pago', 'Pagamento confirmado', ['ref' => $ref]);
        $order = $this->find($orderId);
        if ($order) {
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Pagamento confirmado',
                'Pedido ' . $order['code'] . ' está pago e segue para a cozinha.'
            );
            // NÃO libera fila do motoboy aqui — só quando a cozinha marcar "pronto"
        }
        return true;
    }

    /** Garante o valor ENUM `pronto` no status do pedido. */
    public function ensureProntoStatusEnum(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM orders LIKE 'status'")->fetch();
            $type = (string) ($col['Type'] ?? '');
            if ($type !== '' && stripos($type, "'pronto'") === false) {
                $this->pdo->exec(
                    "ALTER TABLE orders MODIFY COLUMN status ENUM(
                        'rascunho','aguardando_pagamento','pago','preparando','pronto',
                        'saiu_entrega','entrega_informada','entrega_contestada','entregue','cancelado'
                    ) NOT NULL DEFAULT 'aguardando_pagamento'"
                );
            }
            $done = true;
            // Pedidos dinheiro/máquina criados como status=pago com payment pendente → preparando
            $this->pdo->exec(
                "UPDATE orders SET status='preparando', updated_at=NOW()
                 WHERE status='pago' AND payment_status='pendente'
                   AND (
                     payment_method IN ('dinheiro','cartao_maquina')
                     OR payment_method LIKE '%dinheiro%'
                     OR payment_method LIKE '%maquina%'
                     OR payment_method LIKE '%vale%'
                   )"
            );
        } catch (\Throwable) {
        }
    }

    public function setStatus(int $orderId, string $status, ?int $motoboyId = null, string $actor = 'ops'): void
    {
        if ($status === 'pronto') {
            $this->ensureProntoStatusEnum();
        }
        if (!StatusTransition::isValid($status) || $status === 'rascunho') {
            throw new \InvalidArgumentException('Status inválido');
        }
        $curStmt = $this->pdo->prepare('SELECT status FROM orders WHERE id=?');
        $curStmt->execute([$orderId]);
        $from = (string) ($curStmt->fetchColumn() ?: '');
        if ($from === '') {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        $role = match ($actor) {
            'motoboy' => 'motoboy',
            'cliente', 'customer' => 'cliente',
            default => 'ops',
        };
        StatusTransition::assertCanTransition($from, $status, $role);
        // Liberar fila = status pronto SEM motoboy (eles pegam no app via claim)
        if ($status === 'pronto') {
            $this->pdo->prepare(
                "UPDATE orders SET status='pronto', motoboy_id=NULL, updated_at=NOW() WHERE id=?"
            )->execute([$orderId]);
        } elseif ($status === 'saiu_entrega') {
            if (!$motoboyId) {
                throw new \InvalidArgumentException(
                    'Sem atribuição manual: marque como PRONTO para liberar a fila; o motoboy confirma a retirada no app (com GPS).'
                );
            }
            $this->pdo->prepare('UPDATE orders SET status=?, motoboy_id=?, updated_at=NOW() WHERE id=?')
                ->execute([$status, $motoboyId, $orderId]);
        } elseif ($status === 'entregue') {
            try {
                $this->pdo->prepare(
                    "UPDATE orders SET status='entregue', delivered_at=COALESCE(delivered_at, NOW()), delivery_confirmed_at=COALESCE(delivery_confirmed_at, NOW()), updated_at=NOW() WHERE id=?"
                )->execute([$orderId]);
            } catch (\PDOException) {
                $this->pdo->prepare(
                    "UPDATE orders SET status='entregue', delivered_at=COALESCE(delivered_at, NOW()), updated_at=NOW() WHERE id=?"
                )->execute([$orderId]);
            }
        } else {
            $this->pdo->prepare('UPDATE orders SET status=?, updated_at=NOW() WHERE id=?')
                ->execute([$status, $orderId]);
        }
        $msg = StatusLabels::order($status);
        if ($status === 'saiu_entrega' && $motoboyId) {
            $name = $this->pdo->prepare('SELECT name FROM users WHERE id=?');
            $name->execute([$motoboyId]);
            $moto = (string) ($name->fetchColumn() ?: '');
            $msg .= $moto !== '' ? (' — motoboy ' . $moto) : '';
        }
        $this->events->add($orderId, 'status', $msg, ['status' => $status, 'motoboy_id' => $motoboyId]);
        $order = $this->find($orderId);
        if ($order) {
            $title = match ($status) {
                'pago' => 'Pagamento confirmado',
                'cancelado' => 'Pedido cancelado',
                'preparando' => 'Pedido em preparo',
                'pronto' => 'Pedido pronto',
                'saiu_entrega' => 'Saiu para entrega',
                'entrega_informada' => 'Motoboy informou a entrega',
                'entrega_contestada' => 'Cliente contestou a entrega',
                'entregue' => 'Pedido entregue',
                default => 'Pedido ' . $order['code'],
            };
            $this->push->notifyOrderActorsDeferred($order, $title, $msg . ' · ' . $order['code']);
            // Só na cozinha "pronto" o pedido entra na fila (sem atribuição prévia)
            if ($status === 'pronto' && empty($order['motoboy_id'])) {
                $storeId = (int) ($order['store_id'] ?? 0);
                $this->push->notifyActiveMotoboysDeferred(
                    'Pedido pronto na fila',
                    'Pedido ' . $order['code'] . ' está pronto — pegue no app (sem atribuição).',
                    [
                        'order_id' => (int) $order['id'],
                        'code' => (string) $order['code'],
                        'type' => 'queue',
                        'status' => 'pronto',
                        'store_id' => $storeId,
                    ],
                    $storeId > 0 ? $storeId : null
                );
            }
        }
    }

    /** Motoboy informa que concluiu a entrega — aguarda confirmação do cliente. */
    public function reportDelivery(int $orderId, int $motoboyId): array
    {
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        if ((int) ($order['motoboy_id'] ?? 0) !== $motoboyId) {
            throw new \RuntimeException('Pedido não atribuído a este motoboy.');
        }
        $st = (string) $order['status'];
        if ($st === 'entrega_informada') {
            throw new \RuntimeException('Entrega já foi informada. Aguardando o cliente.');
        }
        StatusTransition::assertCanTransition($st, 'entrega_informada', 'ops');
        try {
            $this->pdo->prepare(
                "UPDATE orders SET status='entrega_informada', delivery_reported_at=NOW(),
                 delivery_contested_at=NULL, delivery_contest_reason=NULL, delivery_resolution_note=NULL, updated_at=NOW()
                 WHERE id=?"
            )->execute([$orderId]);
        } catch (\PDOException) {
            // Migração ainda não aplicada: atualiza só o status
            $this->pdo->prepare(
                "UPDATE orders SET status='entrega_informada', updated_at=NOW() WHERE id=?"
            )->execute([$orderId]);
        }
        $this->events->add($orderId, 'entrega_informada', 'Motoboy informou que a entrega foi efetuada', [
            'motoboy_id' => $motoboyId,
        ]);
        $order = $this->find($orderId);
        if ($order) {
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Confirme sua entrega',
                'O motoboy informou a entrega do pedido ' . $order['code'] . '. Confirme ou conteste no app.'
            );
        }
        return $order ?? [];
    }

    /** Motoboy confirma que recebeu o pagamento na entrega (dinheiro/máquina). */
    public function confirmPaymentReceived(int $orderId, int $motoboyId): array
    {
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        if ((int) ($order['motoboy_id'] ?? 0) !== $motoboyId) {
            throw new \RuntimeException('Pedido não atribuído a este motoboy.');
        }
        if ((string) ($order['payment_status'] ?? '') === 'aprovado') {
            return $order;
        }
        $method = (string) ($order['payment_method'] ?? '');
        $payMethods = new PaymentMethodService($this->pdo);
        $onDelivery = $payMethods->isOnDelivery($method)
            || in_array($method, ['dinheiro', 'cartao_maquina'], true)
            || str_contains($method, 'maquina')
            || str_contains($method, 'dinheiro')
            || str_contains($method, 'vale');
        // PIX / online: só o painel confirma. Motoboy confirma dinheiro ou maquininha.
        if (!$onDelivery) {
            throw new \RuntimeException('Este pagamento deve ser confirmado no painel da loja (PIX/online).');
        }
        $st = (string) ($order['status'] ?? '');
        if (!in_array($st, ['pago', 'preparando', 'saiu_entrega', 'entrega_informada', 'pronto'], true)) {
            throw new \RuntimeException('Status do pedido não permite confirmar pagamento.');
        }
        $ref = 'MOTO-PAY-' . $motoboyId . '-' . time();
        $this->markPaid($orderId, $ref);
        // markPaid força status=pago — restaura o status operacional (inclui pronto)
        if (in_array($st, ['preparando', 'pronto', 'saiu_entrega', 'entrega_informada'], true)) {
            $this->pdo->prepare('UPDATE orders SET status=?, updated_at=NOW() WHERE id=?')->execute([$st, $orderId]);
        }
        $this->events->add($orderId, 'pagamento_recebido', 'Motoboy confirmou recebimento do pagamento na entrega', [
            'motoboy_id' => $motoboyId,
            'payment_method' => $method,
            'on_delivery' => $onDelivery,
        ]);
        return $this->find($orderId) ?? [];
    }

    /** Cliente confirma a entrega informada pelo motoboy. */
    public function confirmDelivery(int $orderId, int $customerId): array
    {
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        if ((int) $order['customer_id'] !== $customerId) {
            throw new \RuntimeException('Pedido de outro cliente.');
        }
        if ((string) $order['status'] !== 'entrega_informada') {
            throw new \RuntimeException('Não há entrega aguardando confirmação.');
        }
        StatusTransition::assertCanTransition((string) $order['status'], 'entregue', 'ops');
        try {
            $this->pdo->prepare(
                "UPDATE orders SET status='entregue', delivered_at=COALESCE(delivered_at, NOW()),
                 delivery_confirmed_at=NOW(), updated_at=NOW() WHERE id=?"
            )->execute([$orderId]);
        } catch (\PDOException) {
            $this->pdo->prepare(
                "UPDATE orders SET status='entregue', delivered_at=COALESCE(delivered_at, NOW()), updated_at=NOW() WHERE id=?"
            )->execute([$orderId]);
        }
        $this->events->add($orderId, 'entrega_confirmada', 'Cliente confirmou a entrega', [
            'customer_id' => $customerId,
        ]);
        $order = $this->find($orderId);
        if ($order) {
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Entrega confirmada',
                'Cliente confirmou o pedido ' . $order['code']
            );
        }
        return $order ?? [];
    }

    /** Cliente contesta a entrega informada. */
    public function contestDelivery(int $orderId, int $customerId, string $reason): array
    {
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        if ((int) $order['customer_id'] !== $customerId) {
            throw new \RuntimeException('Pedido de outro cliente.');
        }
        if ((string) $order['status'] !== 'entrega_informada') {
            throw new \RuntimeException('Não há entrega aguardando confirmação para contestar.');
        }
        StatusTransition::assertCanTransition((string) $order['status'], 'entrega_contestada', 'ops');
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException('Informe o motivo da contestação (mín. 5 caracteres).');
        }
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }
        try {
            $this->pdo->prepare(
                "UPDATE orders SET status='entrega_contestada', delivery_contested_at=NOW(),
                 delivery_contest_reason=?, updated_at=NOW() WHERE id=?"
            )->execute([$reason, $orderId]);
        } catch (\PDOException) {
            $this->pdo->prepare(
                "UPDATE orders SET status='entrega_contestada', updated_at=NOW() WHERE id=?"
            )->execute([$orderId]);
        }
        $this->events->add($orderId, 'entrega_contestada', 'Cliente contestou a entrega: ' . $reason, [
            'customer_id' => $customerId,
            'reason' => $reason,
        ]);
        $order = $this->find($orderId);
        if ($order) {
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Entrega contestada',
                'Pedido ' . $order['code'] . ' — ' . $reason
            );
        }
        return $order ?? [];
    }

    /** Admin confirma entrega (mesmo contestada ou só informada). */
    public function adminConfirmDelivery(int $orderId, string $note = ''): array
    {
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        $st = (string) $order['status'];
        StatusTransition::assertCanTransition($st, 'entregue', 'ops');
        $note = trim($note);
        try {
            $this->pdo->prepare(
                "UPDATE orders SET status='entregue', delivered_at=COALESCE(delivered_at, NOW()),
                 delivery_confirmed_at=NOW(), delivery_resolution_note=?, updated_at=NOW() WHERE id=?"
            )->execute([$note !== '' ? $note : null, $orderId]);
        } catch (\PDOException) {
            $this->pdo->prepare(
                "UPDATE orders SET status='entregue', delivered_at=COALESCE(delivered_at, NOW()), updated_at=NOW() WHERE id=?"
            )->execute([$orderId]);
        }
        $this->events->add($orderId, 'entrega_confirmada_admin', 'Admin confirmou a entrega' . ($note !== '' ? ': ' . $note : ''), [
            'note' => $note,
        ]);
        $order = $this->find($orderId);
        if ($order) {
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Entrega confirmada pela loja',
                'Pedido ' . $order['code'] . ' marcado como entregue'
            );
        }
        return $order ?? [];
    }

    /** Admin reabre entrega contestada (volta para rota). */
    public function adminReopenDelivery(int $orderId, string $note = ''): array
    {
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        StatusTransition::assertCanTransition((string) $order['status'], 'saiu_entrega', 'ops');
        $note = trim($note);
        $this->pdo->prepare(
            "UPDATE orders SET status='saiu_entrega', delivery_resolution_note=?, updated_at=NOW() WHERE id=?"
        )->execute([$note !== '' ? $note : null, $orderId]);
        $this->events->add($orderId, 'entrega_reaberta', 'Admin reabriu a entrega' . ($note !== '' ? ': ' . $note : ''), [
            'note' => $note,
        ]);
        $order = $this->find($orderId);
        if ($order) {
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Entrega reaberta',
                'Pedido ' . $order['code'] . ' voltou para rota — resolva a contestação'
            );
        }
        return $order ?? [];
    }

    /**
     * Atualiza endereço/coordenadas de entrega (admin).
     * Bloqueado quando a origem for GPS do celular do cliente.
     * Recalcula taxa se ainda não saiu para entrega.
     */
    public function updateDestination(int $orderId, string $address, float $lat, float $lng): void
    {
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        if (($order['delivery_geo_source'] ?? '') === 'gps') {
            throw new \InvalidArgumentException(
                'Destino bloqueado: coordenadas coletadas pelo GPS do celular do cliente. Não é possível reposicionar.'
            );
        }
        $address = trim($address);
        if ($address === '' || abs($lat) < 0.01 || abs($lng) < 0.01) {
            throw new \InvalidArgumentException('Endereço ou coordenadas inválidos');
        }

        $quote = $this->fees->quote($lat, $lng, (int) ($order['store_id'] ?? 1));
        if (!$quote['within_range']) {
            throw new \InvalidArgumentException(
                (string) ($quote['message'] ?? 'Endereço fora da área de entrega (município da loja).')
            );
        }

        $fee = (float) $quote['fee'];
        $subtotal = (float) $order['subtotal'];
        $this->fees->assertFeeWithinProductTriple($subtotal, $fee);
        $recalc = in_array((string) $order['status'], ['aguardando_pagamento', 'pago', 'preparando'], true);
        $hasSourceCol = $this->hasGeoSourceColumn();

        if ($recalc) {
            if ($hasSourceCol) {
                $this->pdo->prepare(
                    'UPDATE orders SET delivery_address=?, delivery_lat=?, delivery_lng=?, delivery_fee=?, total=?, delivery_geo_source=?, updated_at=NOW() WHERE id=?'
                )->execute([$address, $lat, $lng, $fee, $subtotal + $fee, 'admin', $orderId]);
            } else {
                $this->pdo->prepare(
                    'UPDATE orders SET delivery_address=?, delivery_lat=?, delivery_lng=?, delivery_fee=?, total=?, updated_at=NOW() WHERE id=?'
                )->execute([$address, $lat, $lng, $fee, $subtotal + $fee, $orderId]);
            }
        } else {
            if ($hasSourceCol) {
                $this->pdo->prepare(
                    'UPDATE orders SET delivery_address=?, delivery_lat=?, delivery_lng=?, delivery_geo_source=?, updated_at=NOW() WHERE id=?'
                )->execute([$address, $lat, $lng, 'admin', $orderId]);
            } else {
                $this->pdo->prepare(
                    'UPDATE orders SET delivery_address=?, delivery_lat=?, delivery_lng=?, updated_at=NOW() WHERE id=?'
                )->execute([$address, $lat, $lng, $orderId]);
            }
        }

        if (!empty($order['address_id'])) {
            try {
                $this->pdo->prepare(
                    'UPDATE customer_addresses SET lat=?, lng=? WHERE id=?'
                )->execute([$lat, $lng, (int) $order['address_id']]);
            } catch (\Throwable) {
            }
        }

        $this->events->add($orderId, 'destino', 'Destino reposicionado: ' . $address, [
            'lat' => $lat,
            'lng' => $lng,
            'geo_source' => 'admin',
        ]);
    }

    public function isDestinationLocked(?array $order): bool
    {
        return ($order['delivery_geo_source'] ?? '') === 'gps';
    }

    private function hasGeoSourceColumn(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $this->pdo->query('SELECT delivery_geo_source FROM orders LIMIT 1');
            $has = true;
        } catch (\Throwable) {
            $has = false;
        }
        return $has;
    }

    /** @return list<array> */
    public function listRecent(int $limit = 50, ?string $status = null, ?int $storeId = null, ?array $storeIds = null): array
    {
        $this->ensureProntoStatusEnum();
        $allowed = [
            'rascunho', 'aguardando_pagamento', 'pago', 'preparando', 'pronto',
            'saiu_entrega', 'entrega_informada', 'entrega_contestada', 'entregue', 'cancelado',
        ];
        if ($status !== null && $status !== '' && !in_array($status, $allowed, true)) {
            $status = null;
        }

        $sql = 'SELECT o.*, u.name AS customer_name, s.name AS store_name
                FROM orders o
                JOIN users u ON u.id=o.customer_id
                LEFT JOIN stores s ON s.id=o.store_id
                WHERE 1=1';
        $params = [];
        if ($storeIds !== null) {
            $storeIds = array_values(array_filter(array_map('intval', $storeIds), static fn ($id) => $id > 0));
            if ($storeIds === []) {
                return [];
            }
            $in = implode(',', array_fill(0, count($storeIds), '?'));
            $sql .= " AND o.store_id IN ($in)";
            foreach ($storeIds as $sid) {
                $params[] = $sid;
            }
        } elseif ($storeId !== null && $storeId > 0) {
            $sql .= ' AND o.store_id=?';
            $params[] = $storeId;
        }
        if ($status) {
            $sql .= ' AND o.status=?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY o.id DESC LIMIT ' . (int) $limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$o) {
            $o['status_label'] = StatusLabels::order((string) $o['status']);
        }
        return $rows;
    }

    /**
     * Contagens por status na loja (para filtros do painel).
     *
     * @return array<string,int>
     */
    public function countByStatus(?int $storeId = null): array
    {
        $this->ensureProntoStatusEnum();
        $sql = 'SELECT status, COUNT(*) AS c FROM orders WHERE 1=1';
        $params = [];
        if ($storeId !== null && $storeId > 0) {
            $sql .= ' AND store_id=?';
            $params[] = $storeId;
        }
        $sql .= ' GROUP BY status';
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $out = [];
        $total = 0;
        foreach ($st->fetchAll() ?: [] as $row) {
            $key = (string) ($row['status'] ?? '');
            $n = (int) ($row['c'] ?? 0);
            $out[$key] = $n;
            $total += $n;
        }
        $out['_all'] = $total;
        return $out;
    }

    /**
     * Cliente cancela o próprio pedido (antes de sair para entrega).
     *
     * @return array<string,mixed>
     */
    public function cancelByCliente(int $orderId, int $customerId, string $reason = ''): array
    {
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        if ((int) $order['customer_id'] !== $customerId) {
            throw new \RuntimeException('Pedido de outro cliente.');
        }
        $st = (string) $order['status'];
        if (in_array($st, ['saiu_entrega', 'entrega_informada', 'entrega_contestada'], true)) {
            throw new \RuntimeException('Pedido já saiu para entrega. Fale com a loja para cancelar.');
        }
        StatusTransition::assertCanTransition($st, 'cancelado', 'cliente');
        $reason = trim($reason);
        if (mb_strlen($reason) > 300) {
            $reason = mb_substr($reason, 0, 300);
        }
        $this->pdo->prepare(
            "UPDATE orders SET status='cancelado', updated_at=NOW() WHERE id=? AND customer_id=?"
        )->execute([$orderId, $customerId]);
        $msg = 'Cliente cancelou o pedido' . ($reason !== '' ? ': ' . $reason : '');
        $this->events->add($orderId, 'cancelado_cliente', $msg, [
            'customer_id' => $customerId,
            'reason' => $reason,
        ]);
        $order = $this->find($orderId);
        if ($order) {
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Pedido cancelado',
                'Pedido ' . $order['code'] . ' foi cancelado pelo cliente'
            );
        }
        return $order ?? [];
    }

    /**
     * Tempo médio histórico até a entrega (minutos), com fallback configurável.
     *
     * @return array{avg_minutes:int,sample:int,label:string,source:string}
     */
    public function averageDeliveryEta(): array
    {
        $fallback = (int) delivery_env('DELIVERY_AVG_MINUTES', '45');
        if ($fallback < 15) {
            $fallback = 15;
        }
        if ($fallback > 180) {
            $fallback = 180;
        }
        $sample = 0;
        $avg = null;
        try {
            // Preferência: delivered_at - created_at
            $row = $this->pdo->query(
                "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, COALESCE(delivered_at, updated_at))) AS avg_m,
                        COUNT(*) AS n
                 FROM orders
                 WHERE status='entregue'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                   AND TIMESTAMPDIFF(MINUTE, created_at, COALESCE(delivered_at, updated_at)) BETWEEN 10 AND 240"
            )->fetch();
            if ($row && (int) ($row['n'] ?? 0) >= 3 && $row['avg_m'] !== null) {
                $avg = (int) round((float) $row['avg_m']);
                $sample = (int) $row['n'];
            }
        } catch (\Throwable) {
            // delivered_at pode não existir
            try {
                $row = $this->pdo->query(
                    "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) AS avg_m, COUNT(*) AS n
                     FROM orders
                     WHERE status='entregue'
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                       AND TIMESTAMPDIFF(MINUTE, created_at, updated_at) BETWEEN 10 AND 240"
                )->fetch();
                if ($row && (int) ($row['n'] ?? 0) >= 3 && $row['avg_m'] !== null) {
                    $avg = (int) round((float) $row['avg_m']);
                    $sample = (int) $row['n'];
                }
            } catch (\Throwable) {
            }
        }

        $minutes = $avg ?? $fallback;
        $low = max(10, $minutes - 10);
        $high = $minutes + 10;
        return [
            'avg_minutes' => $minutes,
            'range_min' => $low,
            'range_max' => $high,
            'sample' => $sample,
            'source' => $avg !== null ? 'historico' : 'padrao',
            'label' => sprintf('Tempo médio de entrega: %d–%d min', $low, $high),
        ];
    }

    /**
     * Pedidos em andamento que ultrapassaram o tempo médio esperado.
     *
     * @return array{
     *   count:int,
     *   threshold_minutes:int,
     *   orders:list<array<string,mixed>>,
     *   label:string
     * }
     */
    public function delayedOpenOrders(int $limit = 30, ?int $storeId = null): array
    {
        $eta = $this->averageDeliveryEta();
        $threshold = (int) $eta['avg_minutes'];
        $grace = (int) delivery_env('DELIVERY_DELAY_GRACE_MINUTES', '5');
        if ($grace < 0) {
            $grace = 0;
        }
        $limitMin = $threshold + $grace;
        $storeSql = ($storeId !== null && $storeId > 0) ? ' AND o.store_id = ?' : '';
        $params = [$limitMin];
        if ($storeSql !== '') {
            $params[] = $storeId;
        }

        $sql = "SELECT o.id, o.code, o.status, o.total, o.created_at, o.paid_at, o.updated_at, o.store_id,
                       u.name AS customer_name,
                       TIMESTAMPDIFF(MINUTE, COALESCE(o.paid_at, o.created_at), NOW()) AS age_minutes
                FROM orders o
                JOIN users u ON u.id = o.customer_id
                WHERE o.status IN ('pago','preparando','pronto','saiu_entrega','entrega_informada','entrega_contestada')
                  AND TIMESTAMPDIFF(MINUTE, COALESCE(o.paid_at, o.created_at), NOW()) > ?"
                . $storeSql . "
                ORDER BY age_minutes DESC
                LIMIT " . (int) $limit;

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll() ?: [];
        } catch (\Throwable) {
            // paid_at pode não existir
            $fallbackParams = [$limitMin];
            if ($storeSql !== '') {
                $fallbackParams[] = $storeId;
            }
            $st = $this->pdo->prepare(
                "SELECT o.id, o.code, o.status, o.total, o.created_at, o.updated_at, o.store_id,
                        u.name AS customer_name,
                        TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS age_minutes
                 FROM orders o
                 JOIN users u ON u.id = o.customer_id
                 WHERE o.status IN ('pago','preparando','pronto','saiu_entrega','entrega_informada','entrega_contestada')
                   AND TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) > ?"
                 . $storeSql . "
                 ORDER BY age_minutes DESC
                 LIMIT " . (int) $limit
            );
            $st->execute($fallbackParams);
            $rows = $st->fetchAll() ?: [];
        }

        $out = [];
        foreach ($rows as $r) {
            $age = (int) ($r['age_minutes'] ?? 0);
            $lateBy = max(0, $age - $threshold);
            $level = $lateBy >= max(20, (int) floor($threshold * 0.5)) ? 'critical' : 'warning';
            $out[] = [
                'id' => (int) $r['id'],
                'code' => (string) $r['code'],
                'status' => (string) $r['status'],
                'status_label' => StatusLabels::order((string) $r['status']),
                'customer_name' => (string) ($r['customer_name'] ?? ''),
                'total' => (float) ($r['total'] ?? 0),
                'store_id' => (int) ($r['store_id'] ?? 0),
                'age_minutes' => $age,
                'late_by_minutes' => $lateBy,
                'threshold_minutes' => $threshold,
                'level' => $level,
                'created_at' => (string) ($r['created_at'] ?? ''),
            ];
        }

        $count = count($out);
        return [
            'count' => $count,
            'threshold_minutes' => $threshold,
            'grace_minutes' => $grace,
            'orders' => $out,
            'label' => $count === 0
                ? 'Nenhum pedido em atraso'
                : ($count === 1
                    ? '1 pedido em atraso'
                    : $count . ' pedidos em atraso'),
        ];
    }

    /**
     * Fila aberta para motoboys: somente pedidos que a cozinha marcou como "pronto"
     * e ainda sem motoboy atribuído.
     *
     * @return list<array<string,mixed>>
     */
    public function listAvailableForMotoboy(?float $fromLat = null, ?float $fromLng = null, ?int $storeId = null): array
    {
        $this->ensureProntoStatusEnum();
        $params = [];
        $storeSql = '';
        if ($storeId !== null && $storeId > 0) {
            $storeSql = ' AND o.store_id = ?';
            $params[] = $storeId;
        }
        $sql = "SELECT o.id, o.code, o.status, o.payment_status, o.payment_method, o.store_id,
                       o.delivery_address, o.delivery_lat, o.delivery_lng, o.total, o.created_at,
                       o.notes, u.name AS customer_name, u.phone AS customer_phone,
                       s.name AS store_name, s.address AS store_address, s.lat AS store_lat, s.lng AS store_lng
                FROM orders o
                LEFT JOIN users u ON u.id = o.customer_id
                LEFT JOIN stores s ON s.id = o.store_id
                WHERE o.motoboy_id IS NULL
                  AND o.status = 'pronto'"
                . $storeSql . "
                ORDER BY o.created_at ASC
                LIMIT 80";
        if ($params !== []) {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll() ?: [];
        } else {
            $rows = $this->pdo->query($sql)->fetchAll() ?: [];
        }
        $out = [];
        foreach ($rows as $o) {
            $o['customer_name'] = (string) ($o['customer_name'] ?? '');
            $o['customer_phone'] = (string) ($o['customer_phone'] ?? '');
            $o['status_label'] = StatusLabels::order((string) $o['status']);
            $o['payment_status_label'] = StatusLabels::payment((string) ($o['payment_status'] ?? ''));
            $o['store'] = [
                'id' => (int) ($o['store_id'] ?? 0),
                'name' => (string) ($o['store_name'] ?? ''),
                'address' => $o['store_address'] ?? null,
                'lat' => $o['store_lat'] ?? null,
                'lng' => $o['store_lng'] ?? null,
            ];
            $lat = isset($o['delivery_lat']) && $o['delivery_lat'] !== null && $o['delivery_lat'] !== ''
                ? (float) $o['delivery_lat'] : null;
            $lng = isset($o['delivery_lng']) && $o['delivery_lng'] !== null && $o['delivery_lng'] !== ''
                ? (float) $o['delivery_lng'] : null;
            if ($fromLat !== null && $fromLng !== null && $lat !== null && $lng !== null) {
                $o['distance_km'] = round($this->haversineKm($fromLat, $fromLng, $lat, $lng), 2);
            } else {
                $o['distance_km'] = null;
            }
            $created = strtotime((string) ($o['created_at'] ?? ''));
            $o['wait_minutes'] = $created ? (int) floor((time() - $created) / 60) : 0;
            $o['pay_on_delivery'] = ((string) ($o['payment_status'] ?? '')) === 'pendente';
            $out[] = $o;
        }
        if ($fromLat !== null && $fromLng !== null) {
            usort($out, static function ($a, $b) {
                $da = $a['distance_km'];
                $db = $b['distance_km'];
                if ($da === null && $db === null) {
                    return 0;
                }
                if ($da === null) {
                    return 1;
                }
                if ($db === null) {
                    return -1;
                }
                return $da <=> $db;
            });
        }
        return $out;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Motoboy seleciona pedidos da fila e sai para entrega.
     * Clientes são notificados. Pedidos já pegos por outro motoboy são ignorados.
     *
     * @param list<int> $orderIds
     * @return array{claimed:list<array>, skipped:list<array{id:int,reason:string}>, motoboy_name:string}
     */
    public function claimOrdersForDelivery(int $motoboyId, array $orderIds, ?int $storeId = null): array
    {
        $ids = [];
        foreach ($orderIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            throw new \InvalidArgumentException('Selecione ao menos um pedido.');
        }

        $nameStmt = $this->pdo->prepare('SELECT name FROM users WHERE id=? AND role=? AND active=1');
        $nameStmt->execute([$motoboyId, 'motoboy']);
        $motoName = (string) ($nameStmt->fetchColumn() ?: '');
        if ($motoName === '') {
            throw new \RuntimeException('Motoboy inválido ou inativo.');
        }

        $this->ensureProntoStatusEnum();
        $claimed = [];
        $skipped = [];
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare(
                "SELECT id, code, status, motoboy_id, customer_id, payment_status, payment_method, store_id
                 FROM orders WHERE id=? FOR UPDATE"
            );
            $take = $this->pdo->prepare(
                "UPDATE orders SET status='saiu_entrega', motoboy_id=?, updated_at=NOW()
                 WHERE id=? AND motoboy_id IS NULL AND status='pronto'"
            );
            foreach ($ids as $oid) {
                $lock->execute([$oid]);
                $row = $lock->fetch();
                if (!$row) {
                    $skipped[] = ['id' => $oid, 'reason' => 'Pedido não encontrado'];
                    continue;
                }
                if ($storeId !== null && $storeId > 0 && (int) ($row['store_id'] ?? 0) !== $storeId) {
                    $skipped[] = ['id' => $oid, 'code' => (string) $row['code'], 'reason' => 'Pedido de outra loja'];
                    continue;
                }
                if (!empty($row['motoboy_id'])) {
                    $skipped[] = ['id' => $oid, 'code' => $row['code'], 'reason' => 'Já atribuído a outro motoboy'];
                    continue;
                }
                if ((string) $row['status'] !== 'pronto') {
                    $skipped[] = ['id' => $oid, 'code' => $row['code'], 'reason' => 'Aguarde a cozinha marcar como pronto'];
                    continue;
                }
                if (!StatusTransition::canTransition((string) $row['status'], 'saiu_entrega', 'motoboy')) {
                    $skipped[] = ['id' => $oid, 'code' => $row['code'], 'reason' => 'Status não permite saída'];
                    continue;
                }
                $take->execute([$motoboyId, $oid]);
                if ($take->rowCount() === 0) {
                    $skipped[] = ['id' => $oid, 'code' => $row['code'], 'reason' => 'Não foi possível pegar (concorrência)'];
                    continue;
                }
                $this->events->add($oid, 'saiu_entrega', 'Motoboy ' . $motoName . ' saiu para entrega', [
                    'motoboy_id' => $motoboyId,
                    'claimed' => true,
                ]);
                $order = $this->find($oid);
                if ($order) {
                    $claimed[] = $order;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        foreach ($claimed as $order) {
            $code = (string) ($order['code'] ?? '');
            $this->push->notifyOrderActorsDeferred(
                $order,
                'Saiu para entrega',
                'O motoboy ' . $motoName . ' saiu para entregar o pedido ' . $code . '.'
            );
        }

        return [
            'claimed' => $claimed,
            'skipped' => $skipped,
            'motoboy_name' => $motoName,
        ];
    }

    /** Garante coluna de ocultação no app do cliente (não apaga o pedido no painel). */
    public function ensureHiddenFromCustomerColumn(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        try {
            $db = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=? AND TABLE_NAME=\'orders\' AND COLUMN_NAME=\'hidden_from_customer_at\''
            );
            $stmt->execute([$db]);
            if ((int) $stmt->fetchColumn() === 0) {
                $this->pdo->exec(
                    'ALTER TABLE orders ADD COLUMN hidden_from_customer_at DATETIME NULL
                     COMMENT \'Oculto só no app do cliente; painel admin continua vendo\''
                );
            }
            $done = true;
        } catch (\Throwable) {
            // ambiente sem permissão ALTER: endpoints falham com mensagem clara
        }
    }

    /** Status que o cliente pode remover do histórico do app. */
    public function canHideFromCustomerApp(string $status): bool
    {
        return in_array($status, ['entregue', 'cancelado'], true);
    }

    /**
     * Oculta um pedido no app do cliente. NÃO apaga o registro (painel admin intacto).
     */
    public function hideFromCustomerApp(int $orderId, int $customerId): array
    {
        $this->ensureHiddenFromCustomerColumn();
        $order = $this->find($orderId);
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado');
        }
        if ((int) $order['customer_id'] !== $customerId) {
            throw new \RuntimeException('Pedido de outro cliente.');
        }
        $st = (string) $order['status'];
        if (!$this->canHideFromCustomerApp($st)) {
            throw new \RuntimeException('Só é possível excluir do histórico pedidos entregues ou cancelados.');
        }
        $this->pdo->prepare(
            'UPDATE orders SET hidden_from_customer_at=NOW(), updated_at=NOW()
             WHERE id=? AND customer_id=? AND status IN (\'entregue\',\'cancelado\')'
        )->execute([$orderId, $customerId]);
        $this->events->add($orderId, 'oculto_app_cliente', 'Cliente removeu o pedido do histórico do app', [
            'customer_id' => $customerId,
        ]);
        return $this->find($orderId) ?? [];
    }

    /**
     * Oculta todos os pedidos finalizados do cliente no app. Painel admin permanece intacto.
     * @return array{hidden:int}
     */
    public function hideFinishedHistoryFromCustomerApp(int $customerId): array
    {
        $this->ensureHiddenFromCustomerColumn();
        $stmt = $this->pdo->prepare(
            "UPDATE orders SET hidden_from_customer_at=NOW(), updated_at=NOW()
             WHERE customer_id=?
               AND status IN ('entregue','cancelado')
               AND hidden_from_customer_at IS NULL"
        );
        $stmt->execute([$customerId]);
        return ['hidden' => $stmt->rowCount()];
    }
}
