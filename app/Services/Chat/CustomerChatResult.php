<?php

namespace App\Services\Chat;

use App\Models\OrderMessage;

/** Mijoz xabari natijasi: saqlangan xabar + "raqam ishlamayapti" oqimi natijasi. */
final class CustomerChatResult
{
    public function __construct(
        public readonly OrderMessage $message,
        /** Yangi aloqa raqami qabul qilindi (+998…). */
        public readonly ?string $updatedPhone = null,
        /** Raqam kutilayotgan edi, lekin matn yaroqli raqam emas. */
        public readonly bool $phoneRejected = false,
    ) {}
}
