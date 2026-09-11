<?php
declare(strict_types=1);

namespace Delivery\Services;

/**
 * Upload e resolução de logomarca da loja (assets/stores/).
 * Quadrado JPEG 320×320 — mesmo padrão visual do cardápio.
 */
final class StoreLogoService
{
    public const SIZE = 320;
    public const JPEG_QUALITY = 85;
    public const REL_DIR = 'stores';
    public const MAX_INPUT_BYTES = 8388608;

    public function diskDir(): string
    {
        $dir = DELIVERY_ROOT . '/public_html/assets/' . self::REL_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * @param array{tmp_name?:string,error?:int,size?:int,name?:string} $file $_FILES['logo']
     * @return array{ok:bool,message?:string,path?:string}
     */
    public function saveUpload(int $storeId, array $file): array
    {
        if ($storeId <= 0) {
            return ['ok' => false, 'message' => 'Loja inválida.'];
        }
        $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            return ['ok' => false, 'message' => 'Nenhum arquivo enviado.'];
        }
        if ($err !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Falha no upload.'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return ['ok' => false, 'message' => 'Arquivo inválido.'];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_INPUT_BYTES) {
            return ['ok' => false, 'message' => 'Imagem muito grande (máx. 8 MB).'];
        }
        $binary = @file_get_contents($tmp);
        if ($binary === false || $binary === '') {
            return ['ok' => false, 'message' => 'Não foi possível ler o arquivo.'];
        }
        return $this->saveBinary($storeId, $binary);
    }

    /** @return array{ok:bool,message?:string,path?:string} */
    public function saveBinary(int $storeId, string $binary): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return ['ok' => false, 'message' => 'Extensão GD não disponível no servidor.'];
        }
        $src = @imagecreatefromstring($binary);
        if ($src === false) {
            return ['ok' => false, 'message' => 'Formato de imagem não suportado (use JPG, PNG ou WEBP).'];
        }
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 32 || $h < 32) {
            imagedestroy($src);
            return ['ok' => false, 'message' => 'Imagem muito pequena.'];
        }
        $side = min($w, $h);
        $sx = (int) max(0, ($w - $side) / 2);
        $sy = (int) max(0, ($h - $side) / 2);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        if ($dst === false) {
            imagedestroy($src);
            return ['ok' => false, 'message' => 'Falha ao processar imagem.'];
        }
        imagealphablending($dst, true);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, self::SIZE, self::SIZE, $white);
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);

        $name = 'store-' . $storeId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.jpg';
        $full = $this->diskDir() . '/' . $name;
        $ok = imagejpeg($dst, $full, self::JPEG_QUALITY);
        imagedestroy($dst);
        if (!$ok || !is_file($full)) {
            return ['ok' => false, 'message' => 'Não foi possível gravar a logo.'];
        }
        return ['ok' => true, 'path' => self::REL_DIR . '/' . $name];
    }

    /** Caminho relativo em assets/ ou URL absoluta; string vazia = sem logo (app usa padrão). */
    public function publicPath(?string $stored): string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $stored)) {
            return $stored;
        }
        $stored = ltrim(str_replace('\\', '/', $stored), '/');
        if (str_starts_with($stored, 'assets/')) {
            $stored = substr($stored, strlen('assets/'));
        }
        $disk = DELIVERY_ROOT . '/public_html/assets/' . $stored;
        if (!is_file($disk)) {
            return '';
        }
        return $stored;
    }

    public function absoluteUrl(?string $stored): ?string
    {
        $rel = $this->publicPath($stored);
        if ($rel === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $rel)) {
            return $rel;
        }
        $asset = \Delivery\Utils\View::asset($rel);
        if (preg_match('#^https?://#i', $asset)) {
            return $asset;
        }
        $appUrl = rtrim((string) delivery_env('APP_URL', ''), '/');
        return $appUrl . $asset;
    }
}
