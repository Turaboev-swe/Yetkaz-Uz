<?php

namespace App\Services\User;

use App\Exceptions\StreetRequiredException;
use App\Models\Address;
use App\Models\User;
use App\Services\Geo\AddressGeocoder;
use App\Support\AddressText;
use Illuminate\Support\Facades\DB;

/**
 * Manzil CRUD mantiqi.
 *
 * Biznes qoidalari (Claude.md):
 * - foydalanuvchida bir nechta manzil ("Uy", "Ish"), aynan bittasi is_default
 * - birinchi manzil avtomat default
 *
 * `addresses_one_default_per_user` qisman unique indeksi bir vaqtda ikkita
 * default'ga yo'l qo'ymaydi — shuning uchun bayroqlar tranzaksiyada almashtiriladi.
 */
class AddressService
{
    public function __construct(private readonly AddressGeocoder $geocoder) {}

    /**
     * Nuqtadan manzil yaratadi: tuman, qishloq/mahalla va ko'cha geokodlanadi, `address_text` = ko'cha + qishloq + tuman.
     * Mijoz kiritgan ko'cha (`street`) geokoder topganidan ustun. Geokoder ko'cha topmasa va ko'cha ham, mo'ljal (`note`)
     * ham bo'lmasa — StreetRequiredException (Mini App va bot ko'cha yoki mo'ljalni so'raydi).
     *
     * @param  array<string,mixed>  $data  label, street, note, entrance, floor, apartment, is_default
     */
    public function createForPoint(User $user, float $lat, float $lng, array $data = []): Address
    {
        $place = $this->resolvePlace($lat, $lng, $data['street'] ?? null, $data['note'] ?? null);

        return $this->create($user, [
            ...array_diff_key($data, array_flip(['address_text', 'resolved_address', 'district_id'])),
            'lat' => $lat,
            'lng' => $lng,
            'district_id' => $place['district_id'],
            'street' => $place['street'],
            'address_text' => $place['address_text'],
            'resolved_address' => $place['address_text'],
        ]);
    }

    /**
     * Nuqta + mijoz kiritgan ko'cha/mo'ljal -> yakuniy ko'cha, tuman va matn.
     * Ko'cha: mijoz kiritgan, bo'lmasa geokoder topgan. Ikkalasi ham yo'q va mo'ljal ham yo'q — StreetRequiredException.
     *
     * @return array{district_id: int|null, street: string|null, address_text: string, geocoded_street: string|null}
     */
    public function resolvePlace(float $lat, float $lng, ?string $street, ?string $landmark): array
    {
        $geo = $this->geocoder->describe($lat, $lng);

        $street = $this->clean($street) ?? $geo['street'];

        if ($street === null && $this->clean($landmark) === null) {
            throw StreetRequiredException::make();
        }

        return [
            'district_id' => $geo['district_id'],
            'street' => $street,
            'address_text' => mb_substr(AddressText::compose($street, $geo['locality'], $geo['district_name']), 0, 250),
            'geocoded_street' => $geo['street'],
        ];
    }

    /** @param array<string,mixed> $data */
    public function create(User $user, array $data): Address
    {
        // Manzil matni koordinatadan BIR MARTA aniqlanadi va keshlanadi.
        if (! array_key_exists('resolved_address', $data) && isset($data['lat'], $data['lng'])) {
            $data['resolved_address'] = $this->geocoder->resolve((float) $data['lat'], (float) $data['lng']);
        }

        return DB::transaction(function () use ($user, $data) {
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $user->addresses()->exists();

            if ($makeDefault) {
                $this->clearDefault($user);
            }

            return $user->addresses()->create([
                ...$data,
                'is_default' => $makeDefault,
            ]);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(Address $address, array $data): Address
    {
        // Ko'cha yoki nuqta o'zgardi — matn qayta yig'iladi (yangi geokodlash + majburiylik). Faqat shu holda; boshqa
        // maydonlar (yorliq, kirish, qavat ...) mavjud (ham eski, ko'chasiz) manzilga tegmaydi.
        if (array_key_exists('street', $data) || array_key_exists('lat', $data) || array_key_exists('lng', $data)) {
            $place = $this->resolvePlace(
                (float) ($data['lat'] ?? $address->lat),
                (float) ($data['lng'] ?? $address->lng),
                array_key_exists('street', $data) ? $data['street'] : $address->street,
                array_key_exists('note', $data) ? $data['note'] : $address->note,
            );

            $data = [
                ...array_diff_key($data, array_flip(['address_text', 'resolved_address', 'district_id'])),
                'district_id' => $place['district_id'],
                'street' => $place['street'],
                'address_text' => $place['address_text'],
                'resolved_address' => $place['address_text'],
            ];
        }

        return DB::transaction(function () use ($address, $data) {
            if (array_key_exists('is_default', $data) && $data['is_default'] && ! $address->is_default) {
                $this->clearDefault($address->user);
            }

            // Oxirgi default'ni o'chirib bo'lmaydi — hech bo'lmasa bittasi qolishi kerak.
            if (array_key_exists('is_default', $data) && ! $data['is_default'] && $address->is_default) {
                unset($data['is_default']);
            }

            $address->update($data);

            return $address->refresh();
        });
    }

    public function delete(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $wasDefault = $address->is_default;
            $userId = $address->user_id;

            $address->delete();

            if ($wasDefault) {
                $next = Address::where('user_id', $userId)->latest('id')->first();
                $next?->update(['is_default' => true]);
            }
        });
    }

    /** Bo'shliqlarni yig'ib, bo'sh bo'lsa null. */
    private function clean(?string $value): ?string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : $value;
    }

    private function clearDefault(User $user): void
    {
        $user->addresses()->where('is_default', true)->update(['is_default' => false]);
    }
}
