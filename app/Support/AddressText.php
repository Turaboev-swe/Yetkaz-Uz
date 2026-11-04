<?php

namespace App\Support;

/**
 * Manzil matni: ko'cha + qishloq/mahalla + tuman. Geokoder (Nominatim) natijasini ham, mijoz kiritgan ko'chani ham
 * bir xil qoidada yig'adi; yo'l RAQAMI (D137, M-39) ko'cha nomi hisoblanmaydi.
 */
final class AddressText
{
    /**
     * Magistral/yo'l raqami (`ref`): D137, M-39, A373, "D 137", "D137; D143". OSM'da nomsiz yo'lning `ref` qiymati
     * Nominatim'da `road` bo'lib qaytadi — bu ko'cha emas, mijozga/kuryerga hech narsa demaydi.
     */
    public static function isRouteRef(string $value): bool
    {
        $tokens = preg_split('/\s*[;,\/]\s*/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            // 1-2 harf (lotin yoki kirill) + raqam (+ ixtiyoriy harf): D137, M-39, А373, Р-12, 4P... emas.
            if (! preg_match('/^[A-Za-zА-Яа-я]{1,2}[\s-]?\d{1,4}[A-Za-zА-Яа-я]?$/u', $token)) {
                return false;
            }
        }

        return true;
    }

    /** Solishtirish uchun: kichik harf, apostrof variantlari bitta, ortiqcha bo'shliq va " tumani/shahri" olib tashlangan. */
    public static function normalize(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), ['ʻ' => "'", 'ʼ' => "'", 'ʹ' => "'", '‘' => "'", '’' => "'", '`' => "'", '´' => "'"]);
        $value = (string) preg_replace('/\s+/u', ' ', $value);

        return trim((string) preg_replace('/\s+(tumani|shahri|tuman|shahar|district|rayoni|район[а]?)$/u', '', $value));
    }

    /** `$haystack` ichida `$needle` bor-yo'qligi (normallashtirilgan). */
    public static function contains(string $haystack, string $needle): bool
    {
        $needle = self::normalize($needle);

        return $needle !== '' && str_contains(self::normalize($haystack), $needle);
    }

    /**
     * "Bobur ko'chasi 12, Yangi qishloq, Qo'rg'ontepa tumani". Takrorlanmaydi:
     *  - qishloq/mahalla nomi tuman nomi bilan bir xil (yoki ko'cha matnida bor) bo'lsa — qo'shilmaydi;
     *  - tuman ko'cha matnida bor bo'lsa — qayta qo'shilmaydi.
     */
    public static function compose(?string $street, ?string $locality, ?string $district): string
    {
        $street = trim((string) $street);
        $locality = trim((string) $locality);
        $district = trim((string) $district);

        if ($locality !== '' && (
            ($district !== '' && self::normalize($locality) === self::normalize($district))
            || ($street !== '' && self::contains($street, $locality))
        )) {
            $locality = '';
        }

        if ($district !== '' && $street !== '' && self::contains($street, $district)) {
            $district = '';
        }

        return implode(', ', array_filter([$street, $locality, $district], fn (string $part) => $part !== ''));
    }
}
