<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Geo\AddressGeocoder;
use App\Services\User\AddressService;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Nutgram;

/**
 * "📍 Yangi manzil" tugmasi yuborgan lokatsiya (suhbat faol emas).
 *
 * Yangi manzil qo'shadi va uni asosiy qiladi — keyingi buyurtma shu manzilga
 * ketadi. Har buyurtmada lokatsiya so'ralmaydi; saqlangan manzillar ishlatiladi.
 */
class NewAddressHandler
{
    public function __invoke(Nutgram $bot, AddressService $addresses, AddressGeocoder $geocoder): void
    {
        $location = $bot->message()?->location;

        if ($location === null) {
            return;
        }

        /** @var User $user */
        $user = $bot->get('user');

        $geo = $geocoder->describe($location->latitude, $location->longitude);
        $label = __('messages.addresses.label', ['n' => $user->addresses()->count() + 1]);

        $address = $addresses->create($user, [
            'label' => $label,
            'district_id' => $geo['district_id'],
            'lat' => $location->latitude,
            'lng' => $location->longitude,
            'address_text' => $geo['address_text'],
            'is_default' => true,
        ]);

        $bot->sendMessage(
            __('messages.addresses.added', ['address' => trim($address->label.' — '.$address->address_text)]),
            reply_markup: Keyboards::mainMenu(),
        );
    }
}
