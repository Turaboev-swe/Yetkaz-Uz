<?php

namespace App\Enums;

/**
 * Bepul zonadan tashqarida yetkazish narxi qanday olinadi (restoran sozlamasi).
 *
 *  - per_km: ilovada masofaga qarab narx (hozirgi xatti-harakat, standart).
 *  - taxi:   ilovada yetkazish narxi 0; restoran Royal Taxi bilan yuboradi,
 *            mijoz taksi haqini haydovchiga alohida to'laydi.
 */
enum BeyondFreeZoneDelivery: string
{
    case PerKm = 'per_km';
    case Taxi = 'taxi';

    public function label(): string
    {
        return match ($this) {
            self::PerKm => 'Km bo‘yicha narx (ilovada)',
            self::Taxi => 'Royal Taxi (mijoz haydovchiga alohida to‘laydi)',
        };
    }
}
