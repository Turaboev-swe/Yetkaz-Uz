<?php

namespace App\Services\User;

use App\Models\Address;
use App\Models\User;
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
    /** @param array<string,mixed> $data */
    public function create(User $user, array $data): Address
    {
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

    private function clearDefault(User $user): void
    {
        $user->addresses()->where('is_default', true)->update(['is_default' => false]);
    }
}
