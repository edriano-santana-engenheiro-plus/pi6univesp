<?php
declare(strict_types=1);

namespace Delivery\Utils;

/** Normaliza telefones BR para links wa.me (sempre com DDI 55). */
final class WhatsApp
{
    /** Digitos apenas; adiciona 55 se for celular/fix BR sem DDI. */
    public static function digits(?string $raw): string
    {
        $n = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($n === '') {
            return '';
        }
        // Já tem DDI Brasil
        if (str_starts_with($n, '55') && strlen($n) >= 12) {
            return $n;
        }
        // 0800 / especiais — não força 55
        if (str_starts_with($n, '0800') || str_starts_with($n, '0300')) {
            return $n;
        }
        // DDD + número (10 ou 11 dígitos) → prefixa 55
        if (strlen($n) === 10 || strlen($n) === 11) {
            return '55' . $n;
        }
        // 12+ sem 55: assume já internacional incompleto; se começar com 35 (MG) e tiver 11 chars, ainda assim prefixa
        if (strlen($n) === 11 && str_starts_with($n, '35')) {
            return '55' . $n;
        }
        // Caso comum bugado: "35999990000" (11 dígitos começando com DDD) já coberto acima
        // Se tiver 12+ e NÃO começar com 55, e parecer BR (começa com DDD 11-99), prefixa
        if (strlen($n) >= 10 && strlen($n) <= 11) {
            return '55' . $n;
        }
        return $n;
    }

    public static function link(?string $raw, ?string $text = null): string
    {
        $n = self::digits($raw);
        $url = $n !== '' ? 'https://wa.me/' . $n : 'https://wa.me/';
        if ($text !== null && $text !== '') {
            $url .= '?text=' . rawurlencode($text);
        }
        return $url;
    }

    /**
     * Número da loja: usa o campo WhatsApp das configurações.
     * Só cai no telefone se WhatsApp estiver vazio.
     *
     * @param array<string,mixed> $store
     */
    public static function storeNumber(array $store): string
    {
        $wa = trim((string) ($store['whatsapp'] ?? ''));
        if ($wa !== '') {
            return self::digits($wa);
        }
        return self::digits((string) ($store['phone'] ?? ''));
    }

    /** @param array<string,mixed> $store */
    public static function storeLink(array $store, ?string $text = null): string
    {
        $n = self::storeNumber($store);
        if ($n === '') {
            return 'https://wa.me/' . ($text ? ('?text=' . rawurlencode($text)) : '');
        }
        return self::link($n, $text);
    }
}
