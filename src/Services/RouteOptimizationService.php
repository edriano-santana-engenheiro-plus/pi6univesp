<?php
declare(strict_types=1);

namespace Delivery\Services;

use PDO;

/**
 * Ordenação profissional de rotas de motoboy via teoria dos grafos.
 *
 * Modelo:
 *   G = (V, E) grafo completo métrico
 *   V = {depósito} ∪ {paradas de entrega}
 *   w(u,v) = distância Haversine (km) + penalidades suaves (idade/status)
 *
 * Problema: Path-TSP (caminho aberto a partir do depósito visitando todas as paradas).
 *
 * Algoritmos:
 *   n ≤ 11 → Held–Karp (programação dinâmica / conjunto de vértices) — ótimo
 *   n > 11 → construção por inserção mais barata + busca local 2-opt e Or-opt
 *
 * Referências clássicas: TSP em grafos métricos, Held–Karp (1962), Lin–Kernighan/2-opt.
 */
final class RouteOptimizationService
{
    private const AVG_SPEED_KMH = 22.0;
    private const STOP_MINUTES = 4;
    private const EXACT_MAX_N = 11;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @return array{
     *   orders: list<array<string,mixed>>,
     *   route: array{
     *     total_km: float,
     *     stops: int,
     *     skips: int,
     *     origin: array{lat:float,lng:float,source:string},
     *     algorithm: string,
     *     graph: array{vertices:int,edges:int,metric:string},
     *     optimal: bool
     *   }
     * }
     */
    public function optimizeForMotoboy(array $orders, ?float $fromLat = null, ?float $fromLng = null): array
    {
        $origin = $this->resolveOrigin($fromLat, $fromLng, $orders);
        $withCoords = [];
        $without = [];
        foreach ($orders as $o) {
            $lat = isset($o['delivery_lat']) && $o['delivery_lat'] !== null && $o['delivery_lat'] !== ''
                ? (float) $o['delivery_lat'] : null;
            $lng = isset($o['delivery_lng']) && $o['delivery_lng'] !== null && $o['delivery_lng'] !== ''
                ? (float) $o['delivery_lng'] : null;
            if ($lat === null || $lng === null || (abs($lat) < 0.01 && abs($lng) < 0.01)) {
                $without[] = $o;
                continue;
            }
            $o['_lat'] = $lat;
            $o['_lng'] = $lng;
            $withCoords[] = $o;
        }

        $n = count($withCoords);
        $algorithm = 'identity';
        $optimal = true;
        $seq = $withCoords;

        if ($n >= 2) {
            $graph = $this->buildCompleteGraph($withCoords, $origin['lat'], $origin['lng']);
            if ($n <= self::EXACT_MAX_N) {
                $orderIdx = $this->heldKarpOpenPath($graph['w'], $n);
                $algorithm = 'held_karp_exact';
                $optimal = true;
            } else {
                $orderIdx = $this->cheapestInsertion($graph['w'], $n);
                $orderIdx = $this->twoOptOnIndices($orderIdx, $graph['w']);
                $orderIdx = $this->orOptOnIndices($orderIdx, $graph['w']);
                $algorithm = 'cheapest_insertion+2opt+or_opt';
                $optimal = false;
            }
            $seq = [];
            foreach ($orderIdx as $i) {
                $seq[] = $withCoords[$i];
            }
        }

        $now = time();
        $curLat = $origin['lat'];
        $curLng = $origin['lng'];
        $totalKm = 0.0;
        $out = [];
        $stop = 1;
        foreach ($seq as $o) {
            $leg = $this->haversineKm($curLat, $curLng, $o['_lat'], $o['_lng']);
            $totalKm += $leg;
            $ageMin = $this->ageMinutes($o['created_at'] ?? null, $now);
            unset($o['_lat'], $o['_lng']);
            $o['route_stop'] = $stop;
            $o['distance_from_prev_km'] = round($leg, 2);
            $o['distance_from_origin_km'] = round(
                $this->haversineKm($origin['lat'], $origin['lng'], (float) $o['delivery_lat'], (float) $o['delivery_lng']),
                2
            );
            $o['eta_minutes_est'] = (int) max(1, round(($totalKm / self::AVG_SPEED_KMH) * 60 + ($stop - 1) * self::STOP_MINUTES));
            $o['wait_minutes'] = $ageMin;
            $out[] = $o;
            $curLat = (float) $o['delivery_lat'];
            $curLng = (float) $o['delivery_lng'];
            $stop++;
        }

        usort($without, static function ($a, $b) {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });
        foreach ($without as $o) {
            $o['route_stop'] = $stop++;
            $o['distance_from_prev_km'] = null;
            $o['distance_from_origin_km'] = null;
            $o['eta_minutes_est'] = null;
            $o['wait_minutes'] = $this->ageMinutes($o['created_at'] ?? null, $now);
            $o['route_note'] = 'Sem coordenadas — entregue após a rota principal ou georreferencie o endereço.';
            $out[] = $o;
        }

        $vertices = $n + 1; // depósito + paradas
        $edges = $vertices * ($vertices - 1) / 2;

        return [
            'orders' => $out,
            'route' => [
                'total_km' => round($totalKm, 2),
                'stops' => count($seq),
                'skips' => count($without),
                'origin' => $origin,
                'algorithm' => $algorithm,
                'optimal' => $optimal,
                'graph' => [
                    'vertices' => $vertices,
                    'edges' => (int) $edges,
                    'metric' => 'haversine_km+soft_priority',
                    'problem' => 'open_path_tsp',
                ],
            ],
        ];
    }

    /**
     * Monta matriz de adjacência do grafo completo.
     * Índice 0 = depósito; 1..n = paradas (mesma ordem de $stops).
     *
     * @param list<array<string,mixed>> $stops
     * @return array{w: list<list<float>>, n: int}
     */
    private function buildCompleteGraph(array $stops, float $depotLat, float $depotLng): array
    {
        $n = count($stops);
        $points = [['lat' => $depotLat, 'lng' => $depotLng]];
        $now = time();
        $priority = [];
        foreach ($stops as $i => $o) {
            $points[] = ['lat' => (float) $o['_lat'], 'lng' => (float) $o['_lng']];
            // Prioridade vira desconto na aresta depósito→v (puxa pedidos antigos / em rota)
            $ageMin = $this->ageMinutes($o['created_at'] ?? null, $now);
            $ageBonus = min(2.5, ($ageMin / 10.0) * 0.2);
            $statusBonus = ((string) ($o['status'] ?? '') === 'saiu_entrega') ? 0.35 : 0.0;
            $priority[$i + 1] = $ageBonus + $statusBonus;
        }

        $m = $n + 1;
        $w = array_fill(0, $m, array_fill(0, $m, 0.0));
        for ($i = 0; $i < $m; $i++) {
            for ($j = $i + 1; $j < $m; $j++) {
                $d = $this->haversineKm(
                    $points[$i]['lat'],
                    $points[$i]['lng'],
                    $points[$j]['lat'],
                    $points[$j]['lng']
                );
                // Soft priority só na saída do depósito (não quebra a métrica entre paradas)
                if ($i === 0 && isset($priority[$j])) {
                    $d = max(0.05, $d - $priority[$j]);
                }
                $w[$i][$j] = $d;
                $w[$j][$i] = $d;
            }
        }

        return ['w' => $w, 'n' => $n];
    }

    /**
     * Held–Karp: caminho ótimo depósito → todas as paradas (sem retorno).
     * dp[S][j] = custo mínimo de visitar o conjunto S terminando em j.
     *
     * @param list<list<float>> $w matriz (n+1)×(n+1), índice 0 = depósito
     * @return list<int> índices 0..n-1 das paradas na ordem ótima
     */
    private function heldKarpOpenPath(array $w, int $n): array
    {
        if ($n === 1) {
            return [0];
        }

        $full = (1 << $n) - 1;
        /** @var array<int, array<int, float>> $dp */
        $dp = [];
        /** @var array<int, array<int, int>> $parent */
        $parent = [];

        for ($j = 0; $j < $n; $j++) {
            $mask = 1 << $j;
            $dp[$mask][$j] = $w[0][$j + 1];
            $parent[$mask][$j] = -1;
        }

        for ($mask = 1; $mask <= $full; $mask++) {
            for ($j = 0; $j < $n; $j++) {
                if (($mask & (1 << $j)) === 0) {
                    continue;
                }
                if (!isset($dp[$mask][$j])) {
                    continue;
                }
                $costJ = $dp[$mask][$j];
                for ($k = 0; $k < $n; $k++) {
                    if (($mask & (1 << $k)) !== 0) {
                        continue;
                    }
                    $next = $mask | (1 << $k);
                    $cand = $costJ + $w[$j + 1][$k + 1];
                    if (!isset($dp[$next][$k]) || $cand < $dp[$next][$k]) {
                        $dp[$next][$k] = $cand;
                        $parent[$next][$k] = $j;
                    }
                }
            }
        }

        $bestEnd = 0;
        $bestCost = PHP_FLOAT_MAX;
        for ($j = 0; $j < $n; $j++) {
            if (isset($dp[$full][$j]) && $dp[$full][$j] < $bestCost) {
                $bestCost = $dp[$full][$j];
                $bestEnd = $j;
            }
        }

        // Reconstrói o caminho (fim → início) e inverte
        $path = [];
        $mask = $full;
        $cur = $bestEnd;
        while ($cur >= 0) {
            $path[] = $cur;
            $prev = $parent[$mask][$cur] ?? -1;
            if ($prev < 0) {
                break;
            }
            $mask ^= (1 << $cur);
            $cur = $prev;
        }

        return array_reverse($path);
    }

    /**
     * Construção gulosa: inserção mais barata no tour parcial (clássico TSP).
     *
     * @param list<list<float>> $w
     * @return list<int>
     */
    private function cheapestInsertion(array $w, int $n): array
    {
        // Começa pela parada mais próxima do depósito
        $first = 0;
        $best = $w[0][1];
        for ($j = 1; $j < $n; $j++) {
            if ($w[0][$j + 1] < $best) {
                $best = $w[0][$j + 1];
                $first = $j;
            }
        }
        $tour = [$first];
        $used = array_fill(0, $n, false);
        $used[$first] = true;

        while (count($tour) < $n) {
            $bestDelta = PHP_FLOAT_MAX;
            $bestCity = -1;
            $bestPos = 0;
            for ($city = 0; $city < $n; $city++) {
                if ($used[$city]) {
                    continue;
                }
                for ($pos = 0; $pos <= count($tour); $pos++) {
                    $delta = $this->insertionCost($w, $tour, $city, $pos);
                    if ($delta < $bestDelta) {
                        $bestDelta = $delta;
                        $bestCity = $city;
                        $bestPos = $pos;
                    }
                }
            }
            if ($bestCity < 0) {
                break;
            }
            array_splice($tour, $bestPos, 0, [$bestCity]);
            $used[$bestCity] = true;
        }

        return $tour;
    }

    /**
     * Custo de inserir a parada $city na posição $pos do caminho aberto.
     *
     * @param list<list<float>> $w
     * @param list<int> $tour
     */
    private function insertionCost(array $w, array $tour, int $city, int $pos): float
    {
        $c = $city + 1; // índice na matriz
        if ($tour === []) {
            return $w[0][$c];
        }
        if ($pos === 0) {
            $first = $tour[0] + 1;
            return $w[0][$c] + $w[$c][$first] - $w[0][$first];
        }
        if ($pos === count($tour)) {
            $last = $tour[count($tour) - 1] + 1;
            return $w[$last][$c];
        }
        $a = $tour[$pos - 1] + 1;
        $b = $tour[$pos] + 1;
        return $w[$a][$c] + $w[$c][$b] - $w[$a][$b];
    }

    /**
     * Busca local 2-opt no caminho (remove cruzamentos de arestas).
     *
     * @param list<int> $tour
     * @param list<list<float>> $w
     * @return list<int>
     */
    private function twoOptOnIndices(array $tour, array $w): array
    {
        $n = count($tour);
        if ($n < 4) {
            return $tour;
        }
        $improved = true;
        $guard = 0;
        while ($improved && $guard < 60) {
            $improved = false;
            $guard++;
            $base = $this->pathCost($tour, $w);
            for ($i = 0; $i < $n - 1; $i++) {
                for ($k = $i + 1; $k < $n; $k++) {
                    $candidate = array_merge(
                        array_slice($tour, 0, $i),
                        array_reverse(array_slice($tour, $i, $k - $i + 1)),
                        array_slice($tour, $k + 1)
                    );
                    $cost = $this->pathCost($candidate, $w);
                    if ($cost + 0.0005 < $base) {
                        $tour = $candidate;
                        $base = $cost;
                        $improved = true;
                    }
                }
            }
        }

        return $tour;
    }

    /**
     * Or-opt: relocaciona segmentos de 1–3 vértices (bom para entregas urbanas).
     *
     * @param list<int> $tour
     * @param list<list<float>> $w
     * @return list<int>
     */
    private function orOptOnIndices(array $tour, array $w): array
    {
        $n = count($tour);
        if ($n < 3) {
            return $tour;
        }
        $improved = true;
        $guard = 0;
        while ($improved && $guard < 40) {
            $improved = false;
            $guard++;
            $base = $this->pathCost($tour, $w);
            for ($len = 1; $len <= 3; $len++) {
                for ($i = 0; $i <= $n - $len; $i++) {
                    $segment = array_slice($tour, $i, $len);
                    $rest = array_merge(array_slice($tour, 0, $i), array_slice($tour, $i + $len));
                    $m = count($rest);
                    for ($pos = 0; $pos <= $m; $pos++) {
                        if ($pos === $i) {
                            continue;
                        }
                        $candidate = array_merge(
                            array_slice($rest, 0, $pos),
                            $segment,
                            array_slice($rest, $pos)
                        );
                        $cost = $this->pathCost($candidate, $w);
                        if ($cost + 0.0005 < $base) {
                            $tour = $candidate;
                            $base = $cost;
                            $improved = true;
                            break 3;
                        }
                    }
                }
            }
        }

        return $tour;
    }

    /**
     * Custo do caminho aberto: depósito → tour[0] → … → tour[last].
     *
     * @param list<int> $tour
     * @param list<list<float>> $w
     */
    private function pathCost(array $tour, array $w): float
    {
        if ($tour === []) {
            return 0.0;
        }
        $sum = $w[0][$tour[0] + 1];
        for ($i = 0; $i < count($tour) - 1; $i++) {
            $sum += $w[$tour[$i] + 1][$tour[$i + 1] + 1];
        }

        return $sum;
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @return array{lat:float,lng:float,source:string}
     */
    private function resolveOrigin(?float $lat, ?float $lng, array $orders = []): array
    {
        if ($lat !== null && $lng !== null && abs($lat) > 0.01 && abs($lng) > 0.01) {
            return ['lat' => $lat, 'lng' => $lng, 'source' => 'motoboy_gps'];
        }

        // Preferência: coordenadas da loja do primeiro pedido da leva
        foreach ($orders as $o) {
            $slat = isset($o['store_lat']) && $o['store_lat'] !== null && $o['store_lat'] !== ''
                ? (float) $o['store_lat'] : null;
            $slng = isset($o['store_lng']) && $o['store_lng'] !== null && $o['store_lng'] !== ''
                ? (float) $o['store_lng'] : null;
            if ($slat !== null && $slng !== null && abs($slat) > 0.01 && abs($slng) > 0.01) {
                return ['lat' => $slat, 'lng' => $slng, 'source' => 'store_order'];
            }
        }

        try {
            $store = $this->pdo->query('SELECT lat, lng FROM stores WHERE active=1 ORDER BY id ASC LIMIT 1')->fetch()
                ?: ($this->pdo->query('SELECT lat, lng FROM store_settings WHERE id=1')->fetch() ?: []);
        } catch (\Throwable) {
            $store = [];
        }
        $slat = isset($store['lat']) ? (float) $store['lat'] : -21.0775075;
        $slng = isset($store['lng']) ? (float) $store['lng'] : -47.0533919;

        return ['lat' => $slat, 'lng' => $slng, 'source' => 'store'];
    }

    private function ageMinutes(mixed $createdAt, int $now): int
    {
        if ($createdAt === null || $createdAt === '') {
            return 0;
        }
        $ts = strtotime((string) $createdAt);
        if ($ts === false) {
            return 0;
        }

        return (int) max(0, floor(($now - $ts) / 60));
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
