<?php
declare(strict_types=1);

namespace Delivery\Services;

/**
 * Geocodificação via Nominatim (OpenStreetMap).
 * Preferir o endereço digitado às coordenadas GPS quando houver divergência.
 */
final class GeocodeService
{
    /** @return array{lat:float,lng:float,display?:string}|null */
    public function search(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query,
            'format' => 'json',
            'limit' => 1,
            'countrycodes' => 'br',
        ]);
        $json = $this->httpGet($url);
        if (!is_array($json) || $json === []) {
            return null;
        }
        $row = $json[0];
        if (!isset($row['lat'], $row['lon'])) {
            return null;
        }
        return [
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lon'],
            'display' => (string) ($row['display_name'] ?? ''),
        ];
    }

    /**
     * Resolve coordenadas finais: endereço digitado tem prioridade se o GPS estiver longe.
     *
     * @return array{lat:float,lng:float,source:string}
     */
    public function resolveDeliveryCoords(
        string $street,
        string $number,
        string $neighborhood,
        string $city,
        ?float $gpsLat,
        ?float $gpsLng
    ): array {
        $parts = array_filter([
            trim($street . ($number !== '' ? ', ' . $number : '')),
            $neighborhood !== '' ? $neighborhood : null,
            $city !== '' ? $city : 'Itamogi',
            'MG',
            'Brasil',
        ]);
        // Corrige grafias comuns que quebram o geocode
        $normalized = $this->normalizeStreetQuery(implode(', ', $parts));
        $geo = $this->search($normalized);
        if ($geo === null && $street !== '') {
            $geo = $this->search($this->normalizeStreetQuery(
                $street . ', ' . ($city !== '' ? $city : 'Itamogi') . ', MG, Brasil'
            ));
        }

        if ($geo !== null && $gpsLat !== null && $gpsLng !== null) {
            $distKm = $this->haversineKm($geo['lat'], $geo['lng'], $gpsLat, $gpsLng);
            // GPS perto do endereço → usa GPS (mais preciso no número)
            if ($distKm <= 0.45) {
                return ['lat' => $gpsLat, 'lng' => $gpsLng, 'source' => 'gps'];
            }
            // GPS longe → endereço digitado vence (evita rota no bairro errado)
            return ['lat' => $geo['lat'], 'lng' => $geo['lng'], 'source' => 'address'];
        }
        if ($geo !== null) {
            return ['lat' => $geo['lat'], 'lng' => $geo['lng'], 'source' => 'address'];
        }
        if ($gpsLat !== null && $gpsLng !== null) {
            return ['lat' => $gpsLat, 'lng' => $gpsLng, 'source' => 'gps'];
        }
        throw new \RuntimeException('Não foi possível localizar o endereço no mapa.');
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

    /**
     * Reverse geocode → município (cidade) do ponto.
     *
     * @return array{city:?string,state:?string,display?:string}|null
     */
    public function reverse(float $lat, float $lng): ?array
    {
        $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
            'lat' => $lat,
            'lon' => $lng,
            'format' => 'jsonv2',
            'addressdetails' => 1,
            'zoom' => 14,
        ]);
        $json = $this->httpGet($url);
        if (!is_array($json) || $json === []) {
            return null;
        }
        $addr = is_array($json['address'] ?? null) ? $json['address'] : [];
        $city = $this->extractCityFromAddress($addr);

        return [
            'city' => $city,
            'state' => isset($addr['state']) ? (string) $addr['state'] : null,
            'display' => (string) ($json['display_name'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $addr */
    public function extractCityFromAddress(array $addr): ?string
    {
        foreach (['city', 'town', 'municipality', 'village', 'city_district'] as $key) {
            $v = trim((string) ($addr[$key] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }

        return null;
    }

    /** Normaliza nome de município para comparação (sem acento/case). */
    public static function normalizeCity(string $city): string
    {
        $city = trim(mb_strtolower($city, 'UTF-8'));
        if ($city === '') {
            return '';
        }
        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ];
        $city = strtr($city, $map);
        $city = preg_replace('/\s+/', ' ', $city) ?? $city;
        // remove prefixos comuns
        $city = preg_replace('/^(municipio|cidade)\s+(de\s+)?/u', '', $city) ?? $city;

        return trim($city);
    }

    public static function citiesMatch(string $a, string $b): bool
    {
        $na = self::normalizeCity($a);
        $nb = self::normalizeCity($b);
        if ($na === '' || $nb === '') {
            return false;
        }
        if ($na === $nb) {
            return true;
        }
        // "Itamogi" vs "Itamogi - MG" / contém
        return str_contains($na, $nb) || str_contains($nb, $na);
    }

    private function normalizeStreetQuery(string $q): string
    {
        $q = preg_replace('/\bouvideo\b/iu', 'Ovídio', $q) ?? $q;
        $q = preg_replace('/\bcolhab\b/iu', 'Itamogi', $q) ?? $q;
        return $q;
    }

    /** @return mixed */
    private function httpGet(string $url): mixed
    {
        $ctx = stream_context_create([
            'http' => [
                'header' => "User-Agent: DeliveryItamogi/1.1 (contato: app.integrasoft.eng.br)\r\nAccept: application/json\r\n",
                'timeout' => 10,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false || $raw === '') {
            return null;
        }
        return json_decode($raw, true);
    }
}
