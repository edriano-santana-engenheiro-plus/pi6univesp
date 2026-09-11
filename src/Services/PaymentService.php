<?php
declare(strict_types=1);

namespace Delivery\Services;

use Delivery\Utils\PixKey;
use PDO;

/**
 * PIX estático com chave da loja (painel Pagamentos → campo pix_key).
 * Se MP_ACCESS_TOKEN (loja ou .env) estiver definido, usa Mercado Pago.
 * Confirmação do pagamento: painel (PIX) ou motoboy (dinheiro/máquina).
 */
final class PaymentService
{
    public function __construct(private ?PDO $pdo = null)
    {
    }

    public function isMercadoPagoEnabled(?int $storeId = null): bool
    {
        $cfg = $this->resolveConfig($storeId);
        return trim((string) ($cfg['mp_access_token'] ?? '')) !== '';
    }

    public function createPix(
        float $amount,
        string $orderCode,
        int $orderId,
        string $payerEmail = 'cliente@delivery.local',
        ?int $storeId = null
    ): array {
        if ($storeId === null || $storeId <= 0) {
            $storeId = $this->storeIdFromOrder($orderId);
        }
        $cfg = $this->resolveConfig($storeId);
        if (trim((string) ($cfg['mp_access_token'] ?? '')) !== '') {
            return $this->mercadoPagoPix($amount, $orderCode, $orderId, $payerEmail, $cfg);
        }
        $payload = $this->pixPayload($amount, $orderCode, $cfg);
        $payload['provider'] = 'static';
        $payload['qr_code_base64'] = null;
        $payload['instructions'] = 'Pague o PIX. A loja confirma o recebimento no painel administrativo.';
        $this->persistPayment($orderId, 'static', $orderCode, 'pending', $amount, $payload);
        return $payload;
    }

    /**
     * @param array{pix_key?:string,pix_merchant_name?:string,pix_merchant_city?:string}|null $cfg
     */
    public function pixPayload(float $amount, string $txId, ?array $cfg = null): array
    {
        $cfg ??= $this->resolveConfig(null);
        $key = (string) ($cfg['pix_key'] ?? '');
        if ($key === '') {
            $key = 'loja@delivery.local';
        }
        $name = substr((string) ($cfg['pix_merchant_name'] ?? 'DELIVERY'), 0, 25);
        $city = substr((string) ($cfg['pix_merchant_city'] ?? 'ITAMOGI'), 0, 15);
        $valor = number_format($amount, 2, '.', '');
        $gui = 'BR.GOV.BCB.PIX';
        $mai = $this->tlv('00', $gui) . $this->tlv('01', $key);
        $emv = '000201'
            . $this->tlv('26', $mai)
            . '52040000'
            . '5303986'
            . $this->tlv('54', $valor)
            . '5802BR'
            . $this->tlv('59', $name)
            . $this->tlv('60', $city)
            . $this->tlv('62', $this->tlv('05', substr($txId, 0, 25)))
            . '6304';
        $crc = strtoupper(dechex($this->crc16($emv)));
        $crc = str_pad($crc, 4, '0', STR_PAD_LEFT);
        $payload = $emv . $crc;

        return [
            'method' => 'pix',
            'amount' => $amount,
            'pix_key' => $key,
            'copy_paste' => $payload,
            'tx_id' => $txId,
            'provider' => 'static',
            'instructions' => 'Pague o PIX. A loja confirma o recebimento no painel administrativo.',
        ];
    }

    /** @return array{ok:bool,ref?:string,brand?:string,last4?:string,amount?:float,message?:string,provider?:string} */
    public function chargeCard(float $amount, array $card, int $orderId = 0, ?int $storeId = null): array
    {
        if ($storeId === null || $storeId <= 0) {
            $storeId = $orderId > 0 ? $this->storeIdFromOrder($orderId) : null;
        }
        $cfg = $this->resolveConfig($storeId);
        $mpToken = trim((string) ($cfg['mp_access_token'] ?? ''));
        if ($mpToken !== '' && !empty($card['token'])) {
            return $this->mercadoPagoCard($amount, $card, $orderId, $cfg);
        }

        // Produção: nunca aprovar cartão “sandbox” (número digitado sem gateway).
        if (!$this->isCardSandboxAllowed()) {
            if ($mpToken !== '') {
                return [
                    'ok' => false,
                    'message' => 'Cartão online exige token do Mercado Pago. Use PIX ou pagamento na entrega.',
                ];
            }
            return [
                'ok' => false,
                'message' => 'Cartão online indisponível. Use PIX ou pagamento na entrega (dinheiro/máquina).',
            ];
        }

        $number = preg_replace('/\D+/', '', (string) ($card['number'] ?? ''));
        if (strlen($number) < 13) {
            return ['ok' => false, 'message' => 'Número do cartão inválido'];
        }
        if (($card['cvv'] ?? '') === '' || ($card['holder'] ?? '') === '') {
            return ['ok' => false, 'message' => 'Dados do cartão incompletos'];
        }
        if (str_ends_with($number, '0000')) {
            return ['ok' => false, 'message' => 'Pagamento recusado pelo emissor (simulado)'];
        }
        $ref = 'CARD-' . strtoupper(bin2hex(random_bytes(4)));
        $result = [
            'ok' => true,
            'ref' => $ref,
            'brand' => $this->brand($number),
            'last4' => substr($number, -4),
            'amount' => $amount,
            'provider' => 'sandbox',
        ];
        if ($orderId > 0) {
            $this->persistPayment($orderId, 'sandbox', $ref, 'approved', $amount, $result);
        }
        return $result;
    }

    /** Sandbox de cartão só em APP_ENV local/dev ou ALLOW_CARD_SANDBOX=1. */
    private function isCardSandboxAllowed(): bool
    {
        if (filter_var(delivery_env('ALLOW_CARD_SANDBOX', '0'), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        $env = strtolower(trim((string) delivery_env('APP_ENV', 'production')));
        return in_array($env, ['local', 'dev', 'development'], true);
    }

    /** Consulta pagamento MP e retorna status normalizado. */
    public function fetchMercadoPagoPayment(string $paymentId, ?int $storeId = null): ?array
    {
        $cfg = $this->resolveConfig($storeId);
        if (trim((string) ($cfg['mp_access_token'] ?? '')) === '') {
            return null;
        }
        $res = $this->mpRequest('GET', '/v1/payments/' . rawurlencode($paymentId), null, [], $cfg);
        if ($res === null) {
            return null;
        }
        return $res;
    }

    /**
     * Config efetiva da loja (painel) com fallback .env.
     *
     * @return array{mp_access_token:string,pix_key:string,pix_merchant_name:string,pix_merchant_city:string,store_id:?int}
     */
    public function resolveConfig(?int $storeId): array
    {
        $cfg = [
            'mp_access_token' => trim((string) delivery_env('MP_ACCESS_TOKEN', '')),
            'pix_key' => trim((string) delivery_env('PIX_KEY', '')),
            'pix_merchant_name' => PixKey::merchantName((string) delivery_env('PIX_MERCHANT_NAME', 'DELIVERY')),
            'pix_merchant_city' => PixKey::merchantCity((string) delivery_env('PIX_MERCHANT_CITY', 'ITAMOGI')),
            'store_id' => $storeId,
        ];
        if ($storeId && $storeId > 0 && $this->pdo) {
            try {
                $st = $this->pdo->prepare(
                    'SELECT mp_access_token, pix_key, pix_merchant_name, pix_merchant_city, name, city
                     FROM stores WHERE id=? LIMIT 1'
                );
                $st->execute([$storeId]);
                $row = $st->fetch() ?: null;
                if (is_array($row)) {
                    $storeTok = trim((string) ($row['mp_access_token'] ?? ''));
                    if ($storeTok !== '') {
                        $cfg['mp_access_token'] = $storeTok;
                    }
                    $storeKey = trim((string) ($row['pix_key'] ?? ''));
                    if ($storeKey !== '') {
                        $cfg['pix_key'] = $storeKey;
                    }
                    $storeName = trim((string) ($row['pix_merchant_name'] ?? ''));
                    if ($storeName !== '') {
                        $cfg['pix_merchant_name'] = PixKey::merchantName($storeName);
                    } elseif (!empty($row['name'])) {
                        $cfg['pix_merchant_name'] = PixKey::merchantName((string) $row['name']);
                    }
                    $storeCity = trim((string) ($row['pix_merchant_city'] ?? ''));
                    if ($storeCity !== '') {
                        $cfg['pix_merchant_city'] = PixKey::merchantCity($storeCity);
                    } elseif (!empty($row['city'])) {
                        $cfg['pix_merchant_city'] = PixKey::merchantCity((string) $row['city']);
                    }
                }
            } catch (\Throwable) {
                // colunas ainda não migradas — usa .env
            }
        }
        if ($cfg['pix_key'] === '') {
            $cfg['pix_key'] = 'loja@delivery.local';
        }
        return $cfg;
    }

    private function storeIdFromOrder(int $orderId): ?int
    {
        if (!$this->pdo || $orderId <= 0) {
            return null;
        }
        try {
            $st = $this->pdo->prepare('SELECT store_id FROM orders WHERE id=?');
            $st->execute([$orderId]);
            $id = (int) $st->fetchColumn();
            return $id > 0 ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array{mp_access_token?:string} $cfg */
    private function mercadoPagoPix(float $amount, string $orderCode, int $orderId, string $payerEmail, array $cfg): array
    {
        $body = [
            'transaction_amount' => round($amount, 2),
            'description' => 'Pedido ' . $orderCode . ' — Delivery',
            'payment_method_id' => 'pix',
            'payer' => [
                'email' => $payerEmail !== '' ? $payerEmail : 'cliente@delivery.local',
            ],
            'external_reference' => (string) $orderId,
            'notification_url' => rtrim((string) delivery_env('APP_URL'), '/') . '/api/webhooks/mercadopago',
        ];
        $res = $this->mpRequest('POST', '/v1/payments', $body, [
            'X-Idempotency-Key: ' . $orderCode . '-' . $orderId,
        ], $cfg);
        if ($res === null || empty($res['id'])) {
            $fallback = $this->pixPayload($amount, $orderCode, $cfg);
            $fallback['provider'] = 'static';
            $fallback['instructions'] = 'Mercado Pago indisponível — PIX estático da loja. Confirme no painel. '
                . ($res['message'] ?? '');
            $this->persistPayment($orderId, 'static', $orderCode, 'pending', $amount, ['error' => $res, 'fallback' => true]);
            return $fallback;
        }

        $tx = $res['point_of_interaction']['transaction_data'] ?? [];
        $out = [
            'method' => 'pix',
            'provider' => 'mercadopago',
            'amount' => $amount,
            'tx_id' => (string) $res['id'],
            'copy_paste' => (string) ($tx['qr_code'] ?? ''),
            'qr_code_base64' => $tx['qr_code_base64'] ?? null,
            'ticket_url' => $tx['ticket_url'] ?? null,
            'status' => (string) ($res['status'] ?? 'pending'),
            'instructions' => 'Pague o PIX. A loja ou o webhook confirma o recebimento.',
        ];
        $this->persistPayment($orderId, 'mercadopago', (string) $res['id'], (string) ($res['status'] ?? 'pending'), $amount, $res);
        return $out;
    }

    /** @param array{token?:string,payment_method_id?:string,installments?:int,email?:string} $card */
    private function mercadoPagoCard(float $amount, array $card, int $orderId, array $cfg): array
    {
        $body = [
            'transaction_amount' => round($amount, 2),
            'token' => (string) $card['token'],
            'description' => 'Pedido #' . $orderId,
            'installments' => (int) ($card['installments'] ?? 1),
            'payment_method_id' => (string) ($card['payment_method_id'] ?? 'visa'),
            'payer' => ['email' => (string) ($card['email'] ?? 'cliente@delivery.local')],
            'external_reference' => (string) $orderId,
        ];
        $res = $this->mpRequest('POST', '/v1/payments', $body, [
            'X-Idempotency-Key: card-' . $orderId . '-' . substr(md5((string) ($card['token'] ?? 'x')), 0, 12),
        ], $cfg);
        if ($res === null) {
            return ['ok' => false, 'message' => 'Falha ao cobrar no Mercado Pago'];
        }
        $status = (string) ($res['status'] ?? '');
        $ok = in_array($status, ['approved', 'authorized'], true);
        $this->persistPayment($orderId, 'mercadopago', (string) ($res['id'] ?? ''), $status, $amount, $res);
        if (!$ok) {
            return ['ok' => false, 'message' => 'Pagamento ' . $status, 'provider' => 'mercadopago'];
        }
        return [
            'ok' => true,
            'ref' => (string) $res['id'],
            'amount' => $amount,
            'provider' => 'mercadopago',
            'status' => $status,
        ];
    }

    /** @param list<string> $extraHeaders */
    private function mpRequest(string $method, string $path, ?array $body = null, array $extraHeaders = [], ?array $cfg = null): ?array
    {
        $cfg ??= $this->resolveConfig(null);
        $token = trim((string) ($cfg['mp_access_token'] ?? ''));
        if ($token === '') {
            return null;
        }
        $url = 'https://api.mercadopago.com' . $path;
        $headers = array_merge([
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 25,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            return ['message' => 'Resposta vazia do Mercado Pago', 'http' => $code];
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return ['message' => 'JSON inválido', 'http' => $code, 'raw' => $raw];
        }
        if ($code >= 400) {
            $json['message'] = $json['message'] ?? ('HTTP ' . $code);
            $json['http'] = $code;
        }
        return $json;
    }

    private function persistPayment(int $orderId, string $provider, string $ref, string $status, float $amount, mixed $raw): void
    {
        if (!$this->pdo) {
            return;
        }
        try {
            $this->pdo->prepare(
                'INSERT INTO payments (order_id, provider, provider_ref, status, amount, raw_json, created_at)
                 VALUES (?,?,?,?,?,?,NOW())'
            )->execute([
                $orderId,
                $provider,
                $ref !== '' ? $ref : null,
                $status,
                $amount,
                json_encode($raw, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable) {
            // tabela ainda não migrada
        }
    }

    public function updatePaymentStatus(string $providerRef, string $status, ?array $raw = null): void
    {
        if (!$this->pdo) {
            return;
        }
        try {
            $this->pdo->prepare(
                'UPDATE payments SET status=?, raw_json=COALESCE(?, raw_json), updated_at=NOW() WHERE provider_ref=?'
            )->execute([$status, $raw ? json_encode($raw, JSON_UNESCAPED_UNICODE) : null, $providerRef]);
        } catch (\Throwable) {
        }
    }

    public function findOrderIdByProviderRef(string $ref): ?int
    {
        if (!$this->pdo) {
            return null;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT order_id FROM payments WHERE provider_ref=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$ref]);
            $id = (int) $stmt->fetchColumn();
            return $id > 0 ? $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Recupera ou regenera dados PIX de um pedido pendente. */
    public function recoverPixForOrder(array $order, string $payerEmail = 'cliente@delivery.local'): array
    {
        $orderId = (int) ($order['id'] ?? 0);
        $amount = (float) ($order['total'] ?? 0);
        $code = (string) ($order['code'] ?? ('ORD' . $orderId));
        $storeId = (int) ($order['store_id'] ?? 0) ?: null;

        if ($this->pdo && $orderId > 0) {
            try {
                $st = $this->pdo->prepare(
                    "SELECT raw_json, provider, status FROM payments WHERE order_id=? ORDER BY id DESC LIMIT 5"
                );
                $st->execute([$orderId]);
                foreach ($st->fetchAll() as $row) {
                    $raw = json_decode((string) ($row['raw_json'] ?? ''), true);
                    if (!is_array($raw)) {
                        continue;
                    }
                    $copy = (string) ($raw['copy_paste'] ?? $raw['qr_code'] ?? '');
                    if ($copy === '' && isset($raw['point_of_interaction']['transaction_data']['qr_code'])) {
                        $copy = (string) $raw['point_of_interaction']['transaction_data']['qr_code'];
                    }
                    $b64 = $raw['qr_code_base64']
                        ?? ($raw['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null);
                    if ($copy !== '') {
                        return [
                            'ok' => true,
                            'recovered' => true,
                            'method' => 'pix',
                            'provider' => (string) ($row['provider'] ?? $raw['provider'] ?? 'static'),
                            'amount' => $amount,
                            'copy_paste' => $copy,
                            'qr_code_base64' => $b64,
                            'tx_id' => (string) ($raw['tx_id'] ?? $raw['id'] ?? $code),
                            'instructions' => (string) ($raw['instructions'] ?? 'Use o código PIX abaixo.'),
                        ];
                    }
                }
            } catch (\Throwable) {
            }
        }

        $fresh = $this->createPix($amount, $code, $orderId, $payerEmail, $storeId);
        $fresh['ok'] = true;
        $fresh['recovered'] = false;
        $fresh['regenerated'] = true;
        return $fresh;
    }

    private function brand(string $n): string
    {
        if (str_starts_with($n, '4')) {
            return 'visa';
        }
        if (preg_match('/^5[1-5]/', $n)) {
            return 'mastercard';
        }
        return 'outro';
    }

    private function tlv(string $id, string $value): string
    {
        $len = str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT);
        return $id . $len . $value;
    }

    private function crc16(string $payload): int
    {
        $crc = 0xFFFF;
        $len = strlen($payload);
        for ($i = 0; $i < $len; $i++) {
            $crc ^= (ord($payload[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return $crc & 0xFFFF;
    }
}
