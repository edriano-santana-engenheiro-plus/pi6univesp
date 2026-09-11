<?php
declare(strict_types=1);

namespace Delivery\Utils;

/** Validação e normalização de chave PIX (Bacen). */
final class PixKey
{
    /**
     * @return array{ok:bool,key:string,type:?string,message:?string}
     */
    public static function validate(string $raw): array
    {
        $key = trim($raw);
        if ($key === '') {
            return ['ok' => false, 'key' => '', 'type' => null, 'message' => 'Informe a chave PIX.'];
        }

        // E-mail
        if (str_contains($key, '@')) {
            $email = mb_strtolower($key);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['ok' => false, 'key' => $key, 'type' => 'email', 'message' => 'E-mail PIX inválido.'];
            }
            if (strlen($email) > 77) {
                return ['ok' => false, 'key' => $key, 'type' => 'email', 'message' => 'E-mail PIX muito longo.'];
            }
            return ['ok' => true, 'key' => $email, 'type' => 'email', 'message' => null];
        }

        // Chave aleatória (EVP) — UUID
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return ['ok' => true, 'key' => strtolower($key), 'type' => 'evp', 'message' => null];
        }

        $digits = preg_replace('/\D+/', '', $key) ?? '';

        // Telefone (+55…)
        if (str_starts_with($key, '+') || (strlen($digits) >= 10 && strlen($digits) <= 13 && !self::looksLikeCpfCnpj($digits))) {
            $phone = self::normalizePhone($key, $digits);
            if ($phone === null) {
                return ['ok' => false, 'key' => $key, 'type' => 'phone', 'message' => 'Telefone PIX inválido. Use +55DDDNÚMERO.'];
            }
            return ['ok' => true, 'key' => $phone, 'type' => 'phone', 'message' => null];
        }

        if (strlen($digits) === 11 && self::isValidCpf($digits)) {
            return ['ok' => true, 'key' => $digits, 'type' => 'cpf', 'message' => null];
        }
        if (strlen($digits) === 14 && self::isValidCnpj($digits)) {
            return ['ok' => true, 'key' => $digits, 'type' => 'cnpj', 'message' => null];
        }
        if (strlen($digits) === 11) {
            return ['ok' => false, 'key' => $key, 'type' => 'cpf', 'message' => 'CPF inválido.'];
        }
        if (strlen($digits) === 14) {
            return ['ok' => false, 'key' => $key, 'type' => 'cnpj', 'message' => 'CNPJ inválido.'];
        }

        return [
            'ok' => false,
            'key' => $key,
            'type' => null,
            'message' => 'Chave PIX inválida. Use CPF, CNPJ, e-mail, telefone (+55…) ou chave aleatória.',
        ];
    }

    private static function looksLikeCpfCnpj(string $digits): bool
    {
        return strlen($digits) === 11 || strlen($digits) === 14;
    }

    private static function normalizePhone(string $raw, string $digits): ?string
    {
        if (str_starts_with(trim($raw), '+')) {
            $d = preg_replace('/\D+/', '', $raw) ?? '';
            if (strlen($d) < 12 || strlen($d) > 13) {
                return null;
            }
            return '+' . $d;
        }
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '+55' . $digits;
        }
        if (strlen($digits) === 12 || strlen($digits) === 13) {
            if (!str_starts_with($digits, '55')) {
                return null;
            }
            return '+' . $digits;
        }
        return null;
    }

    private static function isValidCpf(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $d = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $d) {
                return false;
            }
        }
        return true;
    }

    private static function isValidCnpj(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        $w1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $w2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $cnpj[$i] * $w1[$i];
        }
        $d1 = ($sum % 11 < 2) ? 0 : 11 - ($sum % 11);
        if ((int) $cnpj[12] !== $d1) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $cnpj[$i] * $w2[$i];
        }
        $d2 = ($sum % 11 < 2) ? 0 : 11 - ($sum % 11);
        return (int) $cnpj[13] === $d2;
    }

    /** Nome do recebedor no EMV (máx. 25, sem acento). */
    public static function merchantName(string $name): string
    {
        $n = self::asciiUpper($name);
        $n = preg_replace('/[^A-Z0-9 ]+/', '', $n) ?? $n;
        $n = trim(preg_replace('/\s+/', ' ', $n) ?? $n);
        return substr($n !== '' ? $n : 'LOJA', 0, 25);
    }

    /** Cidade no EMV (máx. 15). */
    public static function merchantCity(string $city): string
    {
        $c = self::asciiUpper($city);
        $c = preg_replace('/[^A-Z0-9 ]+/', '', $c) ?? $c;
        $c = trim(preg_replace('/\s+/', ' ', $c) ?? $c);
        return substr($c !== '' ? $c : 'CIDADE', 0, 15);
    }

    private static function asciiUpper(string $s): string
    {
        $map = [
            'á' => 'A', 'à' => 'A', 'ã' => 'A', 'â' => 'A', 'ä' => 'A',
            'é' => 'E', 'ê' => 'E', 'è' => 'E', 'ë' => 'E',
            'í' => 'I', 'ì' => 'I', 'î' => 'I', 'ï' => 'I',
            'ó' => 'O', 'ò' => 'O', 'õ' => 'O', 'ô' => 'O', 'ö' => 'O',
            'ú' => 'U', 'ù' => 'U', 'û' => 'U', 'ü' => 'U',
            'ç' => 'C', 'ñ' => 'N',
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'É' => 'E', 'Ê' => 'E', 'È' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ];
        return strtoupper(strtr($s, $map));
    }
}
