<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

/**
 * Taxa de entrega por distância; área de cobertura = município da loja
 * (`stores.city`). A geolocalização do dispositivo é a fonte de verdade:
 * cidade digitada não pode “passar” um GPS fora do mapa urbano.
 */
final class DeliveryFeeService
{
    /** Fallback só se reverse geocode falhar e o GPS estiver perto da loja. */
    private const SOFT_URBAN_KM = 12.0;

    public function __construct(
        private PDO $pdo,
        private ?StoreService $stores = null,
        private ?GeocodeService $geo = null,
    ) {
        $this->stores ??= new StoreService($pdo);
        $this->geo ??= new GeocodeService();
    }

    /**
     * @return array{
     *   fee:float,
     *   distance_km:?float,
     *   base:float,
     *   per_km:float,
     *   max_km:?float,
     *   within_range:bool,
     *   store_id:int,
     *   area_mode:string,
     *   store_city:string,
     *   delivery_city:?string,
     *   message:?string
     * }
     */
    public function quote(?float $lat, ?float $lng, int $storeId = 1, ?string $addressCity = null): array
    {
        $store = $this->stores->find($storeId) ?: [];
        if ($store === []) {
            try {
                $store = $this->pdo->query('SELECT * FROM store_settings WHERE id=1')->fetch() ?: [];
            } catch (\Throwable) {
                $store = [];
            }
        }
        $base = (float) ($store['delivery_fee'] ?? 5);
        $perKm = (float) ($store['fee_per_km'] ?? 2.5);
        $maxKm = (float) ($store['max_delivery_km'] ?? 8);
        if ($maxKm <= 0) {
            $maxKm = self::SOFT_URBAN_KM;
        }
        $storeCity = $this->resolveStoreCity($store);
        $slat = isset($store['lat']) && $store['lat'] !== null && $store['lat'] !== ''
            ? (float) $store['lat'] : null;
        $slng = isset($store['lng']) && $store['lng'] !== null && $store['lng'] !== ''
            ? (float) $store['lng'] : null;

        $distanceKm = null;
        if ($lat !== null && $lng !== null && $slat !== null && $slng !== null) {
            $distanceKm = round($this->haversineKm($slat, $slng, $lat, $lng), 2);
        }

        $area = $this->resolveUrbanCoverage($storeCity, $addressCity, $lat, $lng, $distanceKm, $maxKm);

        // Taxa só faz sentido dentro da área; fora, devolve base (pedido será bloqueado)
        $fee = $base;
        if ($area['within'] && $distanceKm !== null) {
            $capKm = min($distanceKm, $maxKm);
            $fee = round($base + ($capKm * $perKm), 2);
            if ($fee < $base) {
                $fee = $base;
            }
            // Teto absoluto pela configuração da loja (evita taxa astronômica)
            $maxFeeCfg = round($base + ($maxKm * $perKm), 2);
            if ($fee > $maxFeeCfg) {
                $fee = $maxFeeCfg;
            }
        }

        return [
            'fee' => $fee,
            'distance_km' => $distanceKm,
            'base' => $base,
            'per_km' => $perKm,
            'max_km' => $maxKm,
            'within_range' => $area['within'],
            'store_id' => $storeId,
            'area_mode' => 'municipality',
            'store_city' => $storeCity,
            'delivery_city' => $area['delivery_city'],
            'message' => $area['message'],
        ];
    }

    /**
     * @param array<string, mixed> $store
     */
    public function resolveStoreCity(array $store): string
    {
        $city = trim((string) ($store['city'] ?? ''));
        if ($city !== '') {
            return $city;
        }
        $fromAddr = $this->guessCityFromAddressText((string) ($store['address'] ?? ''));
        if ($fromAddr !== null) {
            return $fromAddr;
        }

        return 'Itamogi';
    }

    /**
     * Cobertura urbana: GPS do dispositivo deve cair no município da loja.
     * Cidade digitada no formulário NÃO autoriza GPS fora do mapa.
     *
     * @return array{within:bool,delivery_city:?string,message:?string}
     */
    private function resolveUrbanCoverage(
        string $storeCity,
        ?string $addressCity,
        ?float $lat,
        ?float $lng,
        ?float $distanceKm,
        float $maxKm,
    ): array {
        $addressCity = trim((string) $addressCity);
        $typedCity = $addressCity !== '' ? $addressCity : null;

        // Sem GPS → não libera pedido (cliente precisa estar no mapa da cidade)
        if ($lat === null || $lng === null || !$this->isPlausibleCoord($lat, $lng)) {
            return [
                'within' => false,
                'delivery_city' => $typedCity,
                'message' => 'Ative a localização do aparelho. Só aceitamos pedidos com GPS '
                    . 'dentro do município de ' . $storeCity . '.',
            ];
        }

        $gpsCity = null;
        try {
            $rev = $this->geo->reverse($lat, $lng);
            if ($rev && !empty($rev['city'])) {
                $gpsCity = (string) $rev['city'];
            }
        } catch (\Throwable) {
            // Nominatim indisponível
        }

        if ($gpsCity !== null) {
            if (GeocodeService::citiesMatch($storeCity, $gpsCity)) {
                return [
                    'within' => true,
                    'delivery_city' => $gpsCity,
                    'message' => null,
                ];
            }

            return [
                'within' => false,
                'delivery_city' => $gpsCity,
                'message' => 'Seu aparelho está fora do município de ' . $storeCity
                    . ' (GPS em ' . $gpsCity . '). Não é possível fazer o pedido.',
            ];
        }

        // Reverse falhou: só aceita se o GPS estiver perto da loja (perímetro urbano)
        $softKm = max($maxKm, self::SOFT_URBAN_KM);
        if ($distanceKm !== null && $distanceKm <= $softKm) {
            // Cidade digitada, se houver, deve bater com a loja
            if ($typedCity !== null && !GeocodeService::citiesMatch($storeCity, $typedCity)) {
                return [
                    'within' => false,
                    'delivery_city' => $typedCity,
                    'message' => 'Entregamos apenas em ' . $storeCity . '.',
                ];
            }

            return [
                'within' => true,
                'delivery_city' => $typedCity ?? $storeCity,
                'message' => null,
            ];
        }

        return [
            'within' => false,
            'delivery_city' => $typedCity,
            'message' => 'Não foi possível confirmar que você está em ' . $storeCity
                . '. Aproxime-se da área urbana da loja e tente de novo.',
        ];
    }

    public function isPlausibleCoord(float $lat, float $lng): bool
    {
        if (!is_finite($lat) || !is_finite($lng)) {
            return false;
        }
        if (abs($lat) < 0.01 && abs($lng) < 0.01) {
            return false;
        }
        // Brasil aproximado (bloqueia GPS de emulador EUA etc.)
        if ($lat < -34.0 || $lat > 6.0 || $lng < -74.0 || $lng > -28.0) {
            return false;
        }

        return true;
    }

    /**
     * Teto: taxa e total não podem ultrapassar 3× o subtotal dos produtos.
     *
     * @throws \InvalidArgumentException
     */
    public function assertFeeWithinProductTriple(float $subtotal, float $fee): void
    {
        if (!is_finite($subtotal) || !is_finite($fee) || $subtotal < 0 || $fee < 0) {
            throw new \InvalidArgumentException('Valores de pedido inválidos.');
        }
        $cap = round($subtotal * 3, 2);
        if ($cap < 0.01) {
            throw new \InvalidArgumentException('Subtotal dos produtos inválido.');
        }
        $total = round($subtotal + $fee, 2);
        if ($fee > $cap + 0.009) {
            throw new \InvalidArgumentException(
                'Taxa de entrega inválida (acima do triplo do valor dos produtos da comanda).'
            );
        }
        if ($total > $cap + 0.009) {
            throw new \InvalidArgumentException(
                'Total do pedido inválido (acima do triplo do valor dos produtos da comanda).'
            );
        }
    }

    private function guessCityFromAddressText(string $address): ?string
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }
        // "... Itamogi/MG" ou "... Itamogi - MG" ou "... Itamogi, MG"
        if (preg_match('/([A-Za-zÀ-ÿ\'\.\- ]+?)\s*[\/,\-]\s*(MG|SP|RJ|ES|PR|SC|RS|BA|GO|DF|MT|MS|PE|CE|MA|PI|PB|RN|AL|SE|TO|PA|AM|RO|AC|RR|AP)\b/iu', $address, $m)) {
            $city = trim($m[1]);
            $city = preg_replace('/^(.*,\s*)+/u', '', $city) ?? $city;
            $parts = preg_split('/,\s*/', $city) ?: [];
            $city = trim((string) end($parts));
            if ($city !== '') {
                return $city;
            }
        }

        return null;
    }

    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}
