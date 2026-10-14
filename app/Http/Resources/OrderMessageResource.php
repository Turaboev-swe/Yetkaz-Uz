<?php

namespace App\Http\Resources;

use App\Models\OrderMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Oshxona paneli uchun. MAXFIYLIK: mijoz faqat "Mijoz", restoran tomoni restoran
 * nomi bilan; sender_id, xodim ismi va mijoz username'i HECH QACHON chiqmaydi.
 *
 * @mixin OrderMessage
 */
class OrderMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'sender' => $this->sender_type,
            'sender_label' => $this->fromCustomer() ? 'Mijoz' : ($this->order?->restaurant?->name ?? 'Restoran'),
            'body' => $this->body,
            'read' => $this->read_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
