<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Services\Reporting\ReportPeriod;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const LANGUAGES = ['uz', 'ru'];

    protected $fillable = [
        'telegram_id',
        'full_name',
        'phone',
        'username',
        'language',
        'profile_completed',
        'last_seen_at',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'telegram_id' => 'integer',
            'profile_completed' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** @return HasMany<Address> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /** @return HasMany<Order> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function defaultAddress(): ?Address
    {
        return $this->addresses->firstWhere('is_default', true)
            ?? $this->addresses->first();
    }

    public function scopeByTelegramId(Builder $query, int $telegramId): Builder
    {
        return $query->where('telegram_id', $telegramId);
    }

    /**
     * "Faol mijoz" — /admin "Mijozlar": so'nggi `$days` kun ichida kamida
     * bitta `delivered` buyurtmasi bor. Bekor qilingan va boshqa statuslar
     * hisobga olinmaydi. Chegara `Asia/Tashkent`dan (ReportPeriod::trailing).
     */
    public function scopeActiveSince(Builder $query, int $days): Builder
    {
        return $query->whereHas('orders', fn (Builder $q) => $q
            ->where('status', OrderStatus::Delivered->value)
            ->where('delivered_at', '>=', ReportPeriod::trailing($days)->fromUtc()));
    }

    /**
     * "Nofaol" — avval kamida bitta `delivered` buyurtmasi bor, lekin
     * so'nggi `$days` kunda yo'q. Umuman buyurtma bermagan mijoz bu yerga
     * kirmaydi (u "nofaol" emas — hali "faol" bo'lib ko'rilmagan).
     */
    public function scopeInactiveFor(Builder $query, int $days): Builder
    {
        return $query
            ->whereHas('orders', fn (Builder $q) => $q->where('status', OrderStatus::Delivered->value))
            ->whereDoesntHave('orders', fn (Builder $q) => $q
                ->where('status', OrderStatus::Delivered->value)
                ->where('delivered_at', '>=', ReportPeriod::trailing($days)->fromUtc()));
    }

    /** "Qaytgan mijoz" — 2 va undan ko'p `delivered` buyurtmasi bor (butun davr). */
    public function scopeReturning(Builder $query): Builder
    {
        return $query->whereHas(
            'orders',
            fn (Builder $q) => $q->where('status', OrderStatus::Delivered->value),
            '>=',
            2,
        );
    }
}
