<?php
declare(strict_types=1);

namespace Delivery\Utils;

/** Sessão do cliente na loja pública (separada do painel admin). */
final class StoreAuth
{
    public static function user(): ?array
    {
        return $_SESSION['delivery_customer'] ?? null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function login(array $user): void
    {
        unset($user['password_hash']);
        $_SESSION['delivery_customer'] = $user;
    }

    public static function logout(): void
    {
        unset($_SESSION['delivery_customer']);
    }

    /** @return list<array{product_id:int,qty:int,name?:string,price?:float}> */
    public static function cart(): array
    {
        $c = $_SESSION['shop_cart'] ?? [];
        return is_array($c) ? $c : [];
    }

    public static function cartStoreId(): int
    {
        return (int) ($_SESSION['shop_cart_store_id'] ?? 0);
    }

    public static function setCartStoreId(int $storeId): void
    {
        $prev = self::cartStoreId();
        if ($prev > 0 && $storeId > 0 && $prev !== $storeId) {
            self::clearCart();
        }
        $_SESSION['shop_cart_store_id'] = $storeId;
    }

    public static function setCart(array $items): void
    {
        $_SESSION['shop_cart'] = array_values($items);
    }

    public static function clearCart(): void
    {
        unset($_SESSION['shop_cart'], $_SESSION['shop_cart_store_id']);
    }

    /** Garante carrinho vazio após pedido criado/pago (idempotente). */
    public static function ensureCartClearedAfterOrder(): void
    {
        self::clearCart();
        unset($_SESSION['shop_checkout_token']);
    }
}
