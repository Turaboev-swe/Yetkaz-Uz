<?php

namespace App\Models;

use Database\Factories\OrderMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Buyurtma chatidagi bitta xabar (mijoz yoki restoran tomonidan). */
class OrderMessage extends Model
{
    /** @use HasFactory<OrderMessageFactory> */
    use HasFactory;

    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_RESTAURANT = 'restaurant';

    /** Faqat created_at bor — xabar tahrirlanmaydi. */
    public $timestamps = false;

    protected $fillable = ['order_id', 'sender_type', 'sender_id', 'body', 'read_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Order, OrderMessage> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    public function fromCustomer(): bool
    {
        return $this->sender_type === self::SENDER_CUSTOMER;
    }
}
