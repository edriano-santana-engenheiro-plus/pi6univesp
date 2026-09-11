<?php
declare(strict_types=1);

namespace Delivery\Utils;

final class StatusLabels
{
    /** Labels operacionais (painel / cozinha / motoboy). */
    public const ORDER = [
        'rascunho' => 'Rascunho',
        'aguardando_pagamento' => 'Aguardando pagamento',
        'pago' => 'Pago',
        'preparando' => 'Preparando',
        'pronto' => 'Pronto (fila motoboy)',
        'saiu_entrega' => 'Saiu para entrega',
        'entrega_informada' => 'Entrega informada (aguardando cliente)',
        'entrega_contestada' => 'Entrega contestada',
        'entregue' => 'Entregue',
        'cancelado' => 'Cancelado',
    ];

    /** Labels amigáveis para o cliente (app / loja online). */
    public const ORDER_CUSTOMER = [
        'rascunho' => 'Rascunho',
        'aguardando_pagamento' => 'Aguardando pagamento',
        'pago' => 'Pago — a loja vai preparar',
        'preparando' => 'Preparando seu pedido',
        'pronto' => 'Pronto — aguardando entregador',
        'saiu_entrega' => 'Saiu para entrega',
        'entrega_informada' => 'Entregador informou a entrega — confirme',
        'entrega_contestada' => 'Entrega em análise pela loja',
        'entregue' => 'Entregue',
        'cancelado' => 'Cancelado',
    ];

    public const PAYMENT = [
        'pendente' => 'Pendente',
        'aprovado' => 'Aprovado',
        'recusado' => 'Recusado',
        'estornado' => 'Estornado',
    ];

    public static function order(string $status, string $audience = 'ops'): string
    {
        if ($audience === 'customer' || $audience === 'cliente') {
            return self::ORDER_CUSTOMER[$status] ?? self::ORDER[$status] ?? $status;
        }

        return self::ORDER[$status] ?? $status;
    }

    /**
     * Label de status para o painel, evitando "Pago" em dinheiro/máquina
     * enquanto o motoboy ainda não confirmou o recebimento.
     *
     * @param array<string,mixed> $order
     */
    public static function orderForOrder(array $order, string $audience = 'ops'): string
    {
        return self::order(self::effectiveStatus($order), $audience);
    }

    /**
     * Status efetivo para UI: dinheiro/máquina com pagamento pendente
     * não aparece como "pago" mesmo se o banco ainda tiver status=pago (legado).
     *
     * @param array<string,mixed> $order
     */
    public static function effectiveStatus(array $order): string
    {
        $status = (string) ($order['status'] ?? '');
        $paySt = (string) ($order['payment_status'] ?? '');
        $method = mb_strtolower((string) ($order['payment_method'] ?? ''));
        $onDelivery = in_array($method, ['dinheiro', 'cartao_maquina'], true)
            || str_contains($method, 'dinheiro')
            || str_contains($method, 'maquina')
            || str_contains($method, 'vale');

        if ($onDelivery && $paySt === 'pendente' && $status === 'pago') {
            return 'preparando';
        }

        return $status;
    }

    public static function payment(string $status): string
    {
        return self::PAYMENT[$status] ?? $status;
    }
}
