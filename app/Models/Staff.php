<?php

namespace App\Models;

use App\Enums\StaffRole;
use Database\Factories\StaffFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Staff extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory, Notifiable;

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

    /** @return BelongsTo<Restaurant, Staff> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
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

    /** Oshxona paneliga (/kitchen) kira oladimi — o'z restorani buyurtmalari. */
    public function canManageKitchen(): bool
    {
        return $this->is_active
            && $this->restaurant_id !== null
            && ($this->isRestaurantOwner() || $this->isKitchenStaff());
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
