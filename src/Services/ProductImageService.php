<?php
declare(strict_types=1);

namespace Delivery\Services;

/**
 * Busca imagens na web, baixa, recorta/redimensiona e grava em assets/products/.
 * Tamanho padrão do sistema (cardápio, loja e app): 320×320 px (quadrado, JPEG leve).
 */
final class ProductImageService
{
    /** Lado do quadrado salvo no disco (adequado a lista/loja/app). */
    public const SIZE = 320;
    /** Qualidade JPEG (0–100). */
    public const JPEG_QUALITY = 82;
    public const REL_DIR = 'products';
    public const DEFAULT_FILE = 'default.png';
    /** Limite do arquivo de entrada antes do processamento. */
    public const MAX_INPUT_BYTES = 8388608; // 8 MB

    public function diskDir(): string
    {
        $dir = DELIVERY_ROOT . '/public_html/assets/' . self::REL_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public function ensureDefault(): string
    {
        $path = $this->diskDir() . '/' . self::DEFAULT_FILE;
        if (!is_file($path)) {
            $this->writePlaceholderPng($path, 'Produto');
        }
        return self::REL_DIR . '/' . self::DEFAULT_FILE;
    }

    /** URL pública relativa ao site (via View::asset). */
    public function relativeAsset(?string $stored): string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return $this->ensureDefault();
        }
        if (preg_match('#^https?://#i', $stored)) {
            return $stored;
        }
        $stored = ltrim(str_replace('\\', '/', $stored), '/');
        if (str_starts_with($stored, 'assets/')) {
            $stored = substr($stored, strlen('assets/'));
        }
        if (str_starts_with($stored, 'public_html/assets/')) {
            $stored = substr($stored, strlen('public_html/assets/'));
        }
        $disk = DELIVERY_ROOT . '/public_html/assets/' . $stored;
        if (!is_file($disk)) {
            return $this->ensureDefault();
        }
        return $stored;
    }

    /**
     * Busca imagens no Bing Imagens (pt-BR), sem Google Cloud / sem API key.
     * @return list<array{id:string,thumb:string,full:string,title:string,source:string,credit:string}>
     */
    public function search(string $query, int $limit = 24): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $limit = max(1, min(40, $limit));
        $out = [];

        // 1) Bing Imagens — fotos de produto embalado (não fruto/planta)
        $isDrink = $this->isDrinkQuery($query);
        $variants = $this->searchQueryVariants($query);
        foreach ($variants as $qi => $q) {
            if (count($out) >= $limit * 2) {
                break;
            }
            if ($qi >= 4) {
                break;
            }
            $batch = $this->searchBingImages($q, max($limit, 16));
            $out = array_merge($out, $batch);
        }
        $out = $this->rankRelevantImages($out, $query);

        // 2) Fallback Wikimedia só se NÃO for bebida (Wikimedia traz fruto/botânica)
        if (!$isDrink && count($out) < 4) {
            foreach ($this->searchQueryVariants($query) as $q) {
                if (count($out) >= $limit) {
                    break;
                }
                $out = array_merge($out, $this->rankRelevantImages(
                    $this->searchWikimedia($q, $limit - count($out)),
                    $query
                ));
            }
        }

        $seen = [];
        $unique = [];
        foreach ($out as $row) {
            $k = $row['full'];
            if ($k === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $unique[] = $row;
            if (count($unique) >= $limit) {
                break;
            }
        }
        return $unique;
    }

    private function isDrinkQuery(string $query): bool
    {
        $q = mb_strtolower($query);
        return (bool) preg_match(
            '/coca|guaran|fanta|refrigerante|água|agua|suco|mineral|antarctica|sprite|pepsi|schweppes|h2oh|sukita/u',
            $q
        );
    }

    /**
     * Extrai resultados do Bing Imagens (HTML público, locale pt-BR).
     * @return list<array{id:string,thumb:string,full:string,title:string,source:string,credit:string}>
     */
    private function searchBingImages(string $query, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $url = 'https://www.bing.com/images/search?' . http_build_query([
            'q' => $query,
            'form' => 'HDRSC2',
            'first' => 1,
            'tsc' => 'ImageHoverTitle',
            'setlang' => 'pt-br',
            'cc' => 'BR',
            'mkt' => 'pt-BR',
            'adlt' => 'strict',
        ], '', '&', PHP_QUERY_RFC3986);

        $html = $this->httpGetBinary($url, [
            'Accept: text/html,application/xhtml+xml',
            'Accept-Language: pt-BR,pt;q=0.9,en;q=0.8',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Cookie: SRCHHPGUSR=ADLT=STRICT',
        ]);
        if ($html === null || $html === '') {
            return [];
        }

        $rows = [];
        $seen = [];

        // Tiles .iusc: atributo m= com JSON HTML-escaped (&quot;murl&quot;:...)
        if (preg_match_all('/class="[^"]*iusc[^"]*"[^>]*\sm="([^"]+)"/i', $html, $m1)
            || preg_match_all('/\sm="([^"]+)"[^>]*class="[^"]*iusc[^"]*"/i', $html, $m1)
        ) {
            foreach ($m1[1] as $enc) {
                $json = html_entity_decode($enc, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $data = json_decode($json, true);
                if (!is_array($data)) {
                    continue;
                }
                $full = (string) ($data['murl'] ?? '');
                $thumb = (string) ($data['turl'] ?? $data['thumb'] ?? $full);
                $title = (string) ($data['t'] ?? $data['title'] ?? $query);
                if ($full === '' || !preg_match('#^https?://#i', $full)) {
                    continue;
                }
                if ($this->isBlockedImageHost($full) || isset($seen[$full])) {
                    continue;
                }
                $seen[$full] = true;
                $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // Prefere miniatura segura (não explicit.bing.net)
                if ($thumb !== '' && stripos($thumb, 'explicit.bing.net') !== false) {
                    $thumb = $full;
                }
                $rows[] = [
                    'id' => 'bing-' . md5($full),
                    'thumb' => $thumb !== '' ? $thumb : $full,
                    'full' => $full,
                    'title' => $title !== '' ? $title : $query,
                    'source' => 'Bing Imagens',
                    'credit' => 'bing.com',
                ];
                if (count($rows) >= max($limit * 2, 24)) {
                    break;
                }
            }
        }

        // Fallback: &quot;murl&quot; escapado no HTML
        if (count($rows) < $limit
            && preg_match_all('/&quot;murl&quot;:&quot;(https?:[^&]+)&quot;/i', $html, $mEsc)
        ) {
            foreach ($mEsc[1] as $fullRaw) {
                $full = html_entity_decode($fullRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($full === '' || !preg_match('#^https?://#i', $full) || isset($seen[$full])) {
                    continue;
                }
                if ($this->isBlockedImageHost($full)) {
                    continue;
                }
                $seen[$full] = true;
                $rows[] = [
                    'id' => 'bing-' . md5($full),
                    'thumb' => $full,
                    'full' => $full,
                    'title' => $query,
                    'source' => 'Bing Imagens',
                    'credit' => 'bing.com',
                ];
                if (count($rows) >= max($limit * 2, 24)) {
                    break;
                }
            }
        }

        return array_slice($rows, 0, max($limit * 2, 24));
    }

    /** Domínios/URLs que não devem ir para o cardápio (NSFW + SSRF). */
    private function isBlockedImageHost(string $url): bool
    {
        return !$this->isSafeRemoteUrl($url);
    }

    /** Bloqueia localhost, IPs privados, metadata cloud e esquemas não HTTP(S). */
    private function isSafeRemoteUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }
        $blocked = [
            'scrolller.com', 'reddit.com', 'redd.it', 'pornhub', 'xvideos', 'xhamster',
            'explicit.bing.net', 'onlyfans', 'chaturbate',
            'localhost', 'metadata.google.internal', '169.254.169.254',
        ];
        foreach ($blocked as $b) {
            if ($host === $b || str_contains($host, $b)) {
                return false;
            }
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return !$this->isPrivateOrReservedIp($host);
        }
        $ips = @gethostbynamel($host) ?: [];
        if ($ips === []) {
            // IPv6
            $packed = @dns_get_record($host, DNS_AAAA) ?: [];
            foreach ($packed as $rec) {
                $ip = (string) ($rec['ipv6'] ?? '');
                if ($ip !== '' && $this->isPrivateOrReservedIp($ip)) {
                    return false;
                }
            }
            return true;
        }
        foreach ($ips as $ip) {
            if ($this->isPrivateOrReservedIp($ip)) {
                return false;
            }
        }
        return true;
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return !filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
        }
        return true;
    }

    /**
     * Prioriza fotos de produto embalado (loja/cardápio), não fruto/planta/receita.
     * @param list<array{id:string,thumb:string,full:string,title:string,source:string,credit:string}> $rows
     * @return list<array{id:string,thumb:string,full:string,title:string,source:string,credit:string}>
     */
    private function rankRelevantImages(array $rows, string $query): array
    {
        $q = mb_strtolower($query);
        $isDrink = $this->isDrinkQuery($query);
        $tokens = preg_split('/[\s\-_,.]+/u', $q) ?: [];
        $tokens = array_values(array_filter($tokens, static function ($t) {
            $t = (string) $t;
            // ignora unidades soltas e palavras vazias
            if (in_array($t, ['para', 'com', 'uma', 'dos', 'das', 'the', 'and', 'ml', 'litros', 'litro'], true)) {
                return false;
            }
            return mb_strlen($t) >= 3;
        }));

        $scored = [];
        foreach ($rows as $row) {
            $title = mb_strtolower((string) ($row['title'] ?? ''));
            $url = mb_strtolower((string) ($row['full'] ?? ''));
            $hay = $title . ' ' . $url;
            $score = 0;

            foreach ($tokens as $t) {
                if (str_contains($hay, $t)) {
                    $score += 3;
                }
            }

            // Embalagem / e-commerce (ex.: cdn.dooca.store/.../fanta-2l.jpg)
            foreach (['lata', 'garrafa', 'pet', 'embalagem', '2l', '2 litros', '350ml', 'pizza'] as $hint) {
                if (str_contains($hay, $hint)) {
                    $score += 4;
                }
            }
            // "refrigerante" sozinho no Bing = fluido HVAC; só conta com marca/embalagem
            if (str_contains($hay, 'refrigerante') && preg_match('/fanta|coca|guaran|antarctica|lata|pet|garrafa|bebida/u', $hay)) {
                $score += 3;
            }
            foreach ([
                'dooca.store', 'vtex', 'vtexassets', 'mlcdn.com.br', 'casasbahia',
                'magazineluiza', 'americanas', '/products/', '/arquivos/',
                'tcdn.com.br', 'susercontent.com', 'wongfood',
            ] as $shop) {
                if (str_contains($url, $shop)) {
                    $score += 8;
                }
            }
            if (str_contains($url, '.com.br')) {
                $score += 3;
            }

            // Bebida: rejeitar fruto/planta e fluido de ar-condicionado
            if ($isDrink) {
                $pack = preg_match('/lata|garrafa|pet|can|bottle|2l|350ml|embalagem|dooca|\/products\//u', $hay);
                $botany = preg_match(
                    '/fruta|fruto|planta|semente|árvore|arvore|folha|colheita|botanic|seed|plant|berry|harvest/u',
                    $hay
                );
                $hvac = preg_match(
                    '/fluido|freon|chiller|manifold|ar-condicionado|ar condicionado|r-22|r22|hfo|gás refrigerante|gas refrigerante/u',
                    $hay
                );
                if ($botany || $hvac) {
                    $score -= 40;
                }
                if ($pack) {
                    $score += 6;
                }
                foreach (['antarctica', 'coca', 'fanta', 'guaran', 'sprite', 'pepsi'] as $brand) {
                    if (str_contains($q, $brand) && str_contains($hay, $brand)) {
                        $score += 5;
                    }
                }
                // Pediu 2 litros → prioriza PET/2L, não lata peq. / fl oz
                if (preg_match('/\b(2|dois)\s*(l|litros?)\b|\b2l\b/u', $q)) {
                    if (preg_match('/2\s*l|2l|2 litros|pet|litros/u', $hay)) {
                        $score += 8;
                    }
                    if (preg_match('/fl oz|330ml|355ml|12 pack|variety pack/u', $hay)) {
                        $score -= 6;
                    }
                }
                // Guaraná: só aceita lata da marca (não fruto da Amazônia)
                if (str_contains($q, 'guaran')) {
                    if (!str_contains($hay, 'antarctica') && !preg_match('/lata|350ml|vtex|dooca/u', $hay)) {
                        $score -= 50;
                    }
                    if (preg_match('/amaz[oô]nia|lenda|origem|paullinia|benefício|beneficio|em p[oó]|superalimento/u', $hay)) {
                        $score -= 50;
                    }
                }
            }

            foreach (['xxx', 'nude', 'sexy', 'comando', 'commandment', 'barco', 'vela', 'karat', 'keto'] as $bad) {
                if (str_contains($hay, $bad)) {
                    $score -= 15;
                }
            }

            if ($score < 1) {
                continue;
            }
            $scored[] = ['s' => $score, 'row' => $row];
        }
        usort($scored, static fn ($a, $b) => $b['s'] <=> $a['s']);
        if ($scored === []) {
            return $isDrink ? [] : $rows;
        }
        return array_map(static fn ($x) => $x['row'], $scored);
    }

    /**
     * Baixa imagem remota OU processa bytes enviados → JPEG 320×320.
     * @return array{ok:bool,path?:string,url?:string,message?:string}
     */
    public function importFromUrl(string $sourceUrl, ?string $preferredName = null): array
    {
        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl === '' || !preg_match('#^https?://#i', $sourceUrl)) {
            return ['ok' => false, 'message' => 'URL inválida.'];
        }
        // Link do Bing Imagens (detailV2) → extrai mediaurl (ex.: cdn.dooca.store/.../fanta-2l.jpg)
        $sourceUrl = $this->unwrapBingImageUrl($sourceUrl);
        $bin = $this->httpGetBinary($sourceUrl);
        if ($bin === null || $bin === '') {
            return ['ok' => false, 'message' => 'Não foi possível baixar a imagem.'];
        }
        return $this->saveProcessed($bin, $preferredName);
    }

    /** Se for URL do Bing detail/serp, devolve a mediaurl original do produto. */
    private function unwrapBingImageUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || (!str_contains($host, 'bing.com') && !str_contains($host, 'bing.net'))) {
            return $url;
        }
        $qs = [];
        parse_str((string) ($parts['query'] ?? ''), $qs);
        foreach (['mediaurl', 'imgurl', 'rurl'] as $key) {
            if (!empty($qs[$key]) && is_string($qs[$key])) {
                $inner = rawurldecode($qs[$key]);
                if (preg_match('#^https?://#i', $inner)) {
                    return $inner;
                }
            }
        }
        return $url;
    }

    /**
     * @return array{ok:bool,path?:string,url?:string,message?:string}
     */
    public function importFromUpload(array $file, ?string $preferredName = null): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Falha no upload.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'Arquivo inválido.'];
        }
        $bin = (string) file_get_contents($tmp);
        if ($bin === '') {
            return ['ok' => false, 'message' => 'Arquivo vazio.'];
        }
        return $this->saveProcessed($bin, $preferredName);
    }

    /**
     * Reduz/recorta qualquer imagem para o tamanho padrão do sistema (quadrado JPEG).
     * @return array{ok:bool,path?:string,url?:string,message?:string,width?:int,height?:int,bytes?:int}
     */
    public function saveProcessed(string $binary, ?string $preferredName = null): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return ['ok' => false, 'message' => 'Extensão GD do PHP não está habilitada.'];
        }
        if (strlen($binary) > self::MAX_INPUT_BYTES) {
            return ['ok' => false, 'message' => 'Imagem muito grande (máx. 8 MB). Escolha outra ou reduza antes.'];
        }
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return ['ok' => false, 'message' => 'Formato de imagem não suportado.'];
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 32 || $h < 32) {
            imagedestroy($src);
            return ['ok' => false, 'message' => 'Imagem muito pequena.'];
        }

        // Crop central 1:1 (se já for quase quadrada, usa a área toda)
        $ratio = $w / max(1, $h);
        if ($ratio > 0.92 && $ratio < 1.08) {
            $side = min($w, $h);
            $sx = (int) (($w - $side) / 2);
            $sy = (int) (($h - $side) / 2);
        } else {
            $side = min($w, $h);
            $sx = (int) (($w - $side) / 2);
            $sy = (int) (($h - $side) / 2);
        }

        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        if ($dst === false) {
            imagedestroy($src);
            return ['ok' => false, 'message' => 'Falha ao criar canvas.'];
        }
        imagealphablending($dst, true);
        imagesavealpha($dst, false);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, self::SIZE, self::SIZE, $white);

        // Sempre reduz (ou amplia levemente) para SIZE×SIZE — nunca grava o original gigante
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);

        $slug = $this->slug($preferredName ?: 'produto');
        $filename = $slug . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.jpg';
        $full = $this->diskDir() . '/' . $filename;

        $ok = imagejpeg($dst, $full, self::JPEG_QUALITY);
        imagedestroy($dst);
        if (!$ok || !is_file($full)) {
            return ['ok' => false, 'message' => 'Não foi possível gravar o arquivo.'];
        }

        // Se ainda ficou grande (>180 KB), regrava com qualidade menor
        $bytes = (int) filesize($full);
        if ($bytes > 180000) {
            $again = @imagecreatefromjpeg($full);
            if ($again !== false) {
                imagejpeg($again, $full, 72);
                imagedestroy($again);
                $bytes = (int) filesize($full);
            }
        }

        $rel = self::REL_DIR . '/' . $filename;
        return [
            'ok' => true,
            'path' => $rel,
            'url' => \Delivery\Utils\View::asset($rel),
            'width' => self::SIZE,
            'height' => self::SIZE,
            'bytes' => $bytes,
        ];
    }

    /**
     * Reprocessa um arquivo já existente no disco para o tamanho padrão.
     * @return array{ok:bool,path?:string,message?:string}
     */
    public function reprocessFile(string $absolutePath, ?string $preferredName = null): array
    {
        if (!is_file($absolutePath)) {
            return ['ok' => false, 'message' => 'Arquivo não encontrado.'];
        }
        $bin = (string) file_get_contents($absolutePath);
        if ($bin === '') {
            return ['ok' => false, 'message' => 'Arquivo vazio.'];
        }
        $res = $this->saveProcessed($bin, $preferredName ?: pathinfo($absolutePath, PATHINFO_FILENAME));
        if ($res['ok'] ?? false) {
            // Remove o original se for diferente do novo
            $newAbs = $this->diskDir() . '/' . basename((string) $res['path']);
            if (realpath($absolutePath) !== realpath($newAbs) && is_file($absolutePath)) {
                $base = basename($absolutePath);
                if ($base !== self::DEFAULT_FILE) {
                    @unlink($absolutePath);
                }
            }
        }
        return $res;
    }

    /** @return list<string> variantes de busca em português (pt-BR). */
    private function searchQueryVariants(string $query): array
    {
        $q = mb_strtolower(trim($query));
        $variants = [];

        // ——— Bebidas: foto de embalagem (estilo dooca …/fanta-2l.jpg) ———
        // Bing responde melhor sem acento (guarana) e SEM a palavra isolada "refrigerante" (HVAC).
        if ($this->isDrinkQuery($query)) {
            $neg = ' -fruta -fruto -planta -semente -fluido -freon';
            $ascii = $this->asciiQuery($query);
            $variants = [];

            if (preg_match('/guaran/u', $q)) {
                $variants[] = 'guarana antarctica lata' . $neg;
                $variants[] = 'guarana antarctica lata 350ml' . $neg;
                $variants[] = 'guarana antarctica lata 350ml site:com.br';
            } elseif (preg_match('/\b(2|dois)\s*(l|litros?)\b/u', $q) || preg_match('/\b2l\b/u', $q)) {
                // Igual ao Bing do usuário: "fanta 2 litros"
                $variants[] = $ascii;
                if (str_contains($q, 'fanta')) {
                    $variants[] = 'fanta 2 litros';
                    $variants[] = 'fanta laranja 2 litros';
                    $variants[] = 'fanta 2l pet';
                } elseif (str_contains($q, 'coca')) {
                    $variants[] = 'coca cola 2 litros pet';
                    $variants[] = 'coca-cola 2 litros';
                } else {
                    $variants[] = $ascii . ' pet';
                }
                $variants[] = $ascii . ' site:com.br';
            } elseif (preg_match('/lata|350|330|310/u', $q)) {
                $variants[] = $ascii . $neg;
                $variants[] = $ascii . ' lata 350ml';
                $variants[] = $ascii . ' site:com.br';
            } else {
                $variants[] = $ascii;
                if (str_contains($q, 'fanta')) {
                    $variants[] = 'fanta laranja lata';
                } elseif (str_contains($q, 'coca')) {
                    $variants[] = 'coca cola lata';
                }
            }

            if (preg_match('/água|agua|mineral/u', $q)) {
                $variants = ['agua mineral garrafa', 'agua mineral 500ml'];
            }

            return $this->uniqueQueries($variants);
        }

        // ——— Pizzas ———
        $variants = [$query];
        $pizzaHints = [
            'calabresa', 'mussarela', 'muçarela', 'portuguesa', 'frango', 'catupiry',
            'quatro queijos', '4 queijos', 'pepperoni', 'bacon', 'atum', 'baiana',
            'bauru', 'strogonoff', 'costela', 'lombo', 'moda da casa', 'gaúcha', 'gaucha', 'mito',
        ];
        $isPizza = false;
        foreach ($pizzaHints as $h) {
            if (str_contains($q, $h)) {
                $isPizza = true;
                break;
            }
        }
        if ($isPizza && !str_contains($q, 'pizza')) {
            $variants[] = 'pizza de ' . $query . ' produto';
            $variants[] = 'pizza ' . $query . ' brasil';
        } elseif (str_contains($q, 'pizza')) {
            $variants[] = $query . ' produto cardápio';
        }

        return $this->uniqueQueries($variants);
    }

    /** @param list<string> $variants @return list<string> */
    private function uniqueQueries(array $variants): array
    {
        $out = [];
        $seen = [];
        foreach ($variants as $v) {
            $v = trim((string) preg_replace('/\s+/u', ' ', $v));
            if ($v === '' || isset($seen[mb_strtolower($v)])) {
                continue;
            }
            $seen[mb_strtolower($v)] = true;
            $out[] = $v;
        }
        return $out;
    }

    /** Remove acentos para o Bing (guaraná → guarana). */
    private function asciiQuery(string $query): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ï' => 'i',
            'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ü' => 'u',
            'ç' => 'c',
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A',
            'É' => 'E', 'Ê' => 'E',
            'Í' => 'I',
            'Ó' => 'O', 'Õ' => 'O', 'Ô' => 'O',
            'Ú' => 'U',
            'Ç' => 'C',
        ];
        return strtr($query, $map);
    }

    /**
     * Wikimedia Commons — busca priorizando pt-BR (sem API key / sem Google Cloud).
     * @return list<array{id:string,thumb:string,full:string,title:string,source:string,credit:string}>
     */
    private function searchWikimedia(string $query, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        // Prefere títulos/descrições em português quando existirem
        $search = $query;

        $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
            'action' => 'query',
            'format' => 'json',
            'origin' => '*',
            'uselang' => 'pt-br',
            'generator' => 'search',
            'gsrnamespace' => 6,
            'gsrsearch' => $search,
            'gsrlimit' => max($limit * 2, 16),
            'prop' => 'imageinfo|info',
            'inprop' => 'url',
            'iiprop' => 'url|mime|size|extmetadata',
            'iiextmetadatafilter' => 'ObjectName|ImageDescription|Categories',
            'iiextmetadatalanguage' => 'pt',
            'iiurlwidth' => 400,
        ]);
        $json = $this->httpGetJson($url);
        if ($json === null) {
            // Fallback sem metadados extras
            $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
                'action' => 'query',
                'format' => 'json',
                'origin' => '*',
                'uselang' => 'pt-br',
                'generator' => 'search',
                'gsrnamespace' => 6,
                'gsrsearch' => $search,
                'gsrlimit' => max($limit, 12),
                'prop' => 'imageinfo',
                'iiprop' => 'url|mime|size',
                'iiurlwidth' => 400,
            ]);
            $json = $this->httpGetJson($url);
        }
        if ($json === null) {
            return [];
        }
        $pages = $json['query']['pages'] ?? [];
        if (!is_array($pages)) {
            return [];
        }

        $scored = [];
        foreach ($pages as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (!is_array($info)) {
                continue;
            }
            $mime = (string) ($info['mime'] ?? '');
            if ($mime !== '' && !str_starts_with($mime, 'image/')) {
                continue;
            }
            $full = (string) ($info['url'] ?? '');
            $thumb = (string) ($info['thumburl'] ?? $full);
            if ($full === '' || str_ends_with(strtolower($full), '.svg')) {
                continue;
            }
            $title = (string) ($page['title'] ?? 'Commons');
            $title = preg_replace('/^File:/i', '', $title) ?? $title;

            $metaBlob = mb_strtolower($title . ' ' . json_encode($info['extmetadata'] ?? [], JSON_UNESCAPED_UNICODE));
            $score = 0;
            // Prioriza sinais de português / Brasil
            if (preg_match('/[áàâãéêíóôõúç]/u', $metaBlob)) {
                $score += 3;
            }
            if (preg_match('/\b(brasil|brazil|brasileir|portugu|são paulo|rio de janeiro|itamogi|minas)\b/u', $metaBlob)) {
                $score += 5;
            }
            foreach (preg_split('/\s+/u', mb_strtolower($query)) as $tok) {
                if (mb_strlen($tok) >= 3 && str_contains($metaBlob, $tok)) {
                    $score += 2;
                }
            }
            // Penaliza legendas claramente em inglês puro sem termo da busca
            if (preg_match('/\b(the|with|and|chicken|cheese|bottle)\b/', $metaBlob) && !preg_match('/[áàâãéêíóôõúç]/u', $metaBlob)) {
                $score -= 1;
            }

            $scored[] = [
                'score' => $score,
                'row' => [
                    'id' => 'wm-' . (string) ($page['pageid'] ?? count($scored)),
                    'thumb' => $thumb !== '' ? $thumb : $full,
                    'full' => $full,
                    'title' => $title,
                    'source' => 'Wikimedia',
                    'credit' => 'Wikimedia Commons',
                ],
            ];
        }

        usort($scored, static fn ($a, $b) => $b['score'] <=> $a['score']);
        $rows = [];
        foreach ($scored as $item) {
            $rows[] = $item['row'];
            if (count($rows) >= $limit) {
                break;
            }
        }
        return $rows;
    }

    /** @return list<array{id:string,thumb:string,full:string,title:string,source:string,credit:string}> */
    private function searchOpenverse(string $query, int $limit): array
    {
        $url = 'https://api.openverse.org/v1/images/?' . http_build_query([
            'q' => $query,
            'page_size' => $limit,
            'mature' => 'false',
        ]);
        $json = $this->httpGetJson($url);
        if ($json === null || !isset($json['results']) || !is_array($json['results'])) {
            return [];
        }
        $rows = [];
        foreach ($json['results'] as $i => $item) {
            $full = (string) ($item['url'] ?? '');
            $thumb = (string) ($item['thumbnail'] ?? $full);
            if ($full === '') {
                continue;
            }
            $rows[] = [
                'id' => 'ov-' . (string) ($item['id'] ?? $i),
                'thumb' => $thumb,
                'full' => $full,
                'title' => (string) ($item['title'] ?? 'Imagem'),
                'source' => 'Openverse',
                'credit' => trim((string) ($item['creator'] ?? '') . ' · ' . (string) ($item['license'] ?? 'CC')),
            ];
        }
        return $rows;
    }

    /** @return list<array{id:string,thumb:string,full:string,title:string,source:string,credit:string}> */
    private function searchPexels(string $query, int $limit, string $apiKey): array
    {
        $url = 'https://api.pexels.com/v1/search?' . http_build_query([
            'query' => $query,
            'per_page' => $limit,
            'orientation' => 'square',
        ]);
        $json = $this->httpGetJson($url, [
            'Authorization: ' . $apiKey,
        ]);
        if ($json === null) {
            return [];
        }
        $rows = [];
        foreach (($json['photos'] ?? []) as $item) {
            $src = $item['src'] ?? [];
            $full = (string) ($src['large2x'] ?? $src['large'] ?? $src['original'] ?? '');
            $thumb = (string) ($src['medium'] ?? $src['small'] ?? $full);
            if ($full === '') {
                continue;
            }
            $rows[] = [
                'id' => 'px-' . (string) ($item['id'] ?? ''),
                'thumb' => $thumb,
                'full' => $full,
                'title' => (string) ($item['alt'] ?? 'Foto Pexels'),
                'source' => 'Pexels',
                'credit' => (string) (($item['photographer'] ?? '') . ' / Pexels'),
            ];
        }
        return $rows;
    }

    /** Baixa bytes de URL (público — proxy de preview/recorte). */
    public function fetchBinary(string $url): ?string
    {
        if (!preg_match('#^https?://#i', trim($url))) {
            return null;
        }
        return $this->httpGetBinary(trim($url));
    }

    /** @param list<string> $headers */
    private function httpGetJson(string $url, array $headers = []): ?array
    {
        $raw = $this->httpGetBinary($url, $headers);
        if ($raw === null || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param list<string> $headers */
    private function httpGetBinary(string $url, array $headers = []): ?string
    {
        if (!$this->isSafeRemoteUrl($url)) {
            return null;
        }
        $defaults = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => '*/*',
        ];
        foreach ($headers as $h) {
            if (str_contains($h, ':')) {
                [$k, $v] = array_map('trim', explode(':', $h, 2));
                $defaults[$k] = $v;
            }
        }
        $hdrs = [];
        foreach ($defaults as $k => $v) {
            $hdrs[] = $k . ': ' . $v;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_ENCODING => '',
            ];
            if (defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
                $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
                $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                return null;
            }
            if ($finalUrl !== '' && !$this->isSafeRemoteUrl($finalUrl)) {
                return null;
            }
            return (string) $body;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 25,
                'header' => implode("\r\n", $hdrs),
                'follow_location' => 0,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : (string) $body;
    }

    private function slug(string $name): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $s = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $s));
        $s = trim($s, '-');
        return $s !== '' ? substr($s, 0, 40) : 'produto';
    }

    private function writePlaceholderPng(string $path, string $label): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            // PNG 1×1 mínimo se GD não existir
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
            if ($png !== false) {
                @file_put_contents($path, $png);
            }
            return;
        }
        $img = imagecreatetruecolor(self::SIZE, self::SIZE);
        if ($img === false) {
            return;
        }
        $bg = imagecolorallocate($img, 183, 28, 28); // #B71C1C
        $cream = imagecolorallocate($img, 255, 248, 231);
        $dark = imagecolorallocate($img, 62, 39, 35);
        imagefilledrectangle($img, 0, 0, self::SIZE, self::SIZE, $bg);

        // "pizza" simplificada
        $cx = (int) (self::SIZE / 2);
        $cy = (int) (self::SIZE / 2) - 20;
        imagefilledellipse($img, $cx, $cy, 280, 280, $cream);
        imagefilledellipse($img, $cx, $cy, 240, 240, imagecolorallocate($img, 255, 224, 178));
        imagefilledellipse($img, $cx - 40, $cy - 30, 28, 28, $bg);
        imagefilledellipse($img, $cx + 50, $cy + 10, 24, 24, $bg);
        imagefilledellipse($img, $cx - 10, $cy + 50, 22, 22, $bg);

        $text = 'Sem foto';
        imagestring($img, 5, (int) (($cx - strlen($text) * 4.5)), self::SIZE - 70, $text, $cream);
        imagestring($img, 3, (int) (($cx - strlen($label) * 3.5)), self::SIZE - 48, substr($label, 0, 28), $dark);

        imagepng($img, $path);
        imagedestroy($img);
    }
}
