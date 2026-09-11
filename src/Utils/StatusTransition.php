<?php
declare(strict_types=1);

namespace Delivery\Utils;

/**
 * Grafo de transições de status do pedido.
 * Quem muda o status passa por aqui (setStatus, claim, cancel, entrega…).
 */
final class StatusTransition
{
    public const ALL = [
        'rascunho',
        'aguardando_pagamento',
        'pago',
        'preparando',
        'pronto',
        'saiu_entrega',
        'entrega_informada',
        'entrega_contestada',
        'entregue',
        'cancelado',
    ];

    /**
     * from → tos permitidos (fluxo operacional).
     *
     * @var array<string, list<string>>
     */
    public const GRAPH = [
        'rascunho' => ['aguardando_pagamento', 'cancelado'],
        'aguardando_pagamento' => ['pago', 'preparando', 'cancelado'],
        'pago' => ['preparando', 'pronto', 'cancelado'],
        'preparando' => ['pronto', 'cancelado'],
        'pronto' => ['saiu_entrega', 'cancelado'],
        'saiu_entrega' => ['entrega_informada', 'entregue'],
        'entrega_informada' => ['entregue', 'entrega_contestada'],
        'entrega_contestada' => ['saiu_entrega', 'entregue'],
        'entregue' => [],
        'cancelado' => [],
    ];

    /** Status em que o cliente ainda pode cancelar. */
    public const CLIENTE_CANCEL_FROM = [
        'aguardando_pagamento',
        'pago',
        'preparando',
        'pronto',
    ];

    public static function isValid(string $status): bool
    {
        return in_array($status, self::ALL, true);
    }

    /** @return list<string> */
    public static function allowedFrom(string $from, string $actor = 'ops'): array
    {
        $tos = self::GRAPH[$from] ?? [];
        if ($actor === 'cliente') {
            return in_array($from, self::CLIENTE_CANCEL_FROM, true) ? ['cancelado'] : [];
        }
        if ($actor === 'motoboy') {
            // Saída operacional é via /orders/claim (não setStatus genérico).
            return match ($from) {
                'pronto' => ['saiu_entrega'],
                default => [],
            };
        }

        return $tos;
    }

    public static function canTransition(string $from, string $to, string $actor = 'ops'): bool
    {
        if ($from === $to) {
            return true;
        }
        if (!self::isValid($from) || !self::isValid($to)) {
            return false;
        }

        return in_array($to, self::allowedFrom($from, $actor), true);
    }

    public static function assertCanTransition(string $from, string $to, string $actor = 'ops'): void
    {
        if (self::canTransition($from, $to, $actor)) {
            return;
        }
        $fromLabel = StatusLabels::order($from);
        $toLabel = StatusLabels::order($to);
        throw new \RuntimeException(
            'Transição de status não permitida: ' . $fromLabel . ' → ' . $toLabel . '.'
        );
    }
}
