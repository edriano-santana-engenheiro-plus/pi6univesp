<?php
declare(strict_types=1);

namespace Delivery\Utils;

/** Validação de assinatura de webhooks Mercado Pago (x-signature). */
final class MercadoPagoWebhook
{
    /**
     * @see https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
     */
    public static function verify(string $xSignature, string $xRequestId, string $dataId, string $secret): bool
    {
        $secret = trim($secret);
        $xSignature = trim($xSignature);
        $xRequestId = trim($xRequestId);
        $dataId = trim($dataId);
        if ($secret === '' || $xSignature === '' || $dataId === '') {
            return false;
        }

        $ts = null;
        $hash = null;
        foreach (explode(',', $xSignature) as $part) {
            $part = trim($part);
            if (!str_contains($part, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $part, 2);
            $k = trim($k);
            $v = trim($v);
            if ($k === 'ts') {
                $ts = $v;
            } elseif ($k === 'v1') {
                $hash = $v;
            }
        }
        if ($ts === null || $hash === null || $hash === '') {
            return false;
        }

        // data.id alfanumérico deve ir em minúsculas no manifesto
        if (preg_match('/[a-zA-Z]/', $dataId)) {
            $dataId = strtolower($dataId);
        }

        $manifest = 'id:' . $dataId . ';request-id:' . $xRequestId . ';ts:' . $ts . ';';
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $hash);
    }
}
