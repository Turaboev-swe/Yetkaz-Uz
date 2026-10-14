<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Services\Chat\OrderChatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class KitchenOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snap = $this->address_snapshot ?? [];
        $chat = app(OrderChatService::class);

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            // Bir nechta restoranli oshxona xodimi kartada qaysi restoran ekanini ko'radi.
            'restaurant' => [
                'id' => $this->restaurant_id,
                'name' => $this->restaurant?->name,
            ],
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'delivery_type' => $this->delivery_type->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'eta_minutes' => $this->eta_minutes,
            'printed' => $this->printed_at !== null,
            'dispatch_failed' => $this->dispatch_failed_at !== null,
            'can_cancel' => in_array($this->status->value, ['accepted', 'preparing'], true),

            'customer' => [
                'name' => $this->user?->full_name,
                // Telegram raqami (request_contact). Mijozning Telegram username'i
                // oshxonaga HECH QACHON berilmaydi (maxfiylik).
                'phone' => $this->user?->phone,
                // Qo'ng'iroq uchun raqam (eski buyurtmalarda Telegram raqamiga teng).
                'contact_phone' => $this->contactPhone(),
                'phones_differ' => $this->hasDistinctContactPhone(),
            ],

            'address' => $this->delivery_type->isPickup() ? null : [
                'text' => trim(($snap['address_text'] ?? '').' · '.($snap['district'] ?? ''), ' ·'),
                'entrance' => $snap['entrance'] ?? null,
                'floor' => $snap['floor'] ?? null,
                'apartment' => $snap['apartment'] ?? null,
                'lat' => $snap['lat'] ?? null,
                'lng' => $snap['lng'] ?? null,
            ],

            // Buyurtma chati: ochiqmi, o'qilmagan mijoz xabarlari, "raqam ishlamayapti" kutilyaptimi.
            // `unread_messages_count` ro'yxatda withCount bilan keladi; boshqa joylarda alohida hisoblanadi.
            'chat' => [
                'open' => $chat->isOpen($this->resource),
                'unread' => $this->unread_messages_count ?? $chat->unreadCount($this->resource),
                'awaiting_phone' => $chat->isAwaitingPhone($this->resource),
            ],

            'items' => $this->items, // [{product_id, name, price, qty, prep, note}]
            'note' => $this->note,
            'courier_type' => $this->courier_type?->value,
            'courier_name' => $this->courier_name,
            'courier_phone' => $this->courier_phone,

            'subtotal' => $this->subtotal,
            'delivery_fee' => $this->delivery_fee,
            // Restoran/platforma taqsimoti ATAYLAB yo'q — oshxona panelida ko'rsatilmaydi
            // (bu JSON Reverb orqali oshxona kanaliga ham ketadi). Faqat hisobotlarda.
            'discount_amount' => $this->discount_amount,
            'total' => $this->total, // mijozdan olinadigan — chegirma ayirilgan
        ];
    }
}
