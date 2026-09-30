<?php

namespace App\Models;

use App\Enums\StaffRole;
use Database\Factories\StaffFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class Staff extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory, Notifiable, HasPushSubscriptions;

    protected $table = 'staff';

    protected $fillable = [
        'restaurant_id',
        'name',
        'email',
        'phone',
        'telegram_chat_id',
        'password',
        'role',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'telegram_chat_id' => 'integer',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * `restaurant_id` — asosiy restoran (/restaurant paneli, RestaurantScope).
     * U doim `restaurants()` ichida bo'ladi: yaratilganda biriktiriladi,
     * o'zgarsa eskisi pivot'dan olinib yangisi qo'shiladi (bir restoranli
     * xodim avvalgidek "ko'chadi"). Qo'shimcha restoranlar — assignRestaurants().
     */
    protected static function booted(): void
    {
        static::created(function (Staff $staff): void {
            if ($staff->restaurant_id !== null) {
                $staff->restaurants()->syncWithoutDetaching([$staff->restaurant_id]);
            }
        });

        static::updated(function (Staff $staff): void {
            if (! $staff->wasChanged('restaurant_id')) {
                return;
            }

            $old = $staff->getOriginal('restaurant_id');
            if ($old !== null) {
                $staff->restaurants()->detach($old);
            }
            if ($staff->restaurant_id !== null) {
                $staff->restaurants()->syncWithoutDetaching([$staff->restaurant_id]);
            }
        });
    }

    /** @return BelongsTo<Restaurant, Staff> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * Oshxona uchun biriktirilgan restoranlar (/kitchen buyurtmalari, Reverb
     * kanallari, bildirishnomalar, kuryer ro'yxati).
     *
     * @return BelongsToMany<Restaurant, Staff>
     */
    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_staff')->withTimestamps();
    }

    /** @return list<int> */
    public function restaurantIds(): array
    {
        return $this->restaurants()
            ->orderBy('restaurants.id')
            ->pluck('restaurants.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Restoranlar to'plamini o'rnatadi (/admin xodim formasi). Asosiy
     * `restaurant_id` ro'yxatda qolsa o'zgarmaydi, aks holda birinchi
     * tanlangani bo'ladi.
     *
     * @param  array<int, int|string>  $restaurantIds
     */
    public function assignRestaurants(array $restaurantIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $restaurantIds)));

        DB::transaction(function () use ($ids): void {
            if (! in_array((int) $this->restaurant_id, $ids, true)) {
                $this->restaurant_id = $ids[0] ?? null;
                $this->save();
            }

            $this->restaurants()->sync($ids);
        });
    }

    /** Shu restoranga biriktirilgan xodimlar (pivot orqali). */
    public function scopeAssignedTo(Builder $query, int $restaurantId): void
    {
        $query->whereHas('restaurants', fn (Builder $q) => $q->whereKey($restaurantId));
    }

    /** Oshxona xabarlarini oladiganlar: shu restoranga biriktirilgan, faol, oshxona xodimi yoki egasi. */
    public function scopeKitchenRecipients(Builder $query, int $restaurantId): void
    {
        $query->assignedTo($restaurantId)
            ->where('is_active', true)
            ->whereIn('role', [StaffRole::KitchenStaff->value, StaffRole::RestaurantOwner->value]);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->role === StaffRole::PlatformAdmin;
    }

    public function isRestaurantOwner(): bool
    {
        return $this->role === StaffRole::RestaurantOwner;
    }

    public function isKitchenStaff(): bool
    {
        return $this->role === StaffRole::KitchenStaff;
    }

    /** Oshxona paneliga (/kitchen) kira oladimi — biriktirilgan restoranlar buyurtmalari. */
    public function canManageKitchen(): bool
    {
        return $this->hasKitchenRole() && $this->restaurants()->exists();
    }

    /**
     * Shu restoran buyurtmasini oshxonada boshqara oladimi: qabul, status,
     * kuryer, bekor qilish, Telegram callback'lari, Reverb kanali.
     * Yagona tekshiruv joyi.
     */
    public function canManageRestaurant(Restaurant|int|null $restaurant): bool
    {
        $id = $restaurant instanceof Restaurant ? $restaurant->id : $restaurant;

        return $id !== null
            && $this->hasKitchenRole()
            && $this->restaurants()->whereKey($id)->exists();
    }

    private function hasKitchenRole(): bool
    {
        return $this->is_active && ($this->isRestaurantOwner() || $this->isKitchenStaff());
    }

    /** Filament: qaysi panelga kira oladi. */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->role === StaffRole::PlatformAdmin,
            'restaurant' => $this->role === StaffRole::RestaurantOwner && $this->restaurant_id !== null,
            default => false,
        };
    }
}
