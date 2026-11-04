<?php

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Geo\AddressGeocoder;
use App\Services\User\AddressService;
use App\Telegram\Conversations\NewAddressConversation;
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

        // Geokoder ko'cha nomini topmasa — ko'cha yoki mo'ljal MAJBURIY: suhbat boshlanadi (o'tkazib yuborib bo'lmaydi).
        if ($geocoder->describe($location->latitude, $location->longitude)['street'] === null) {
            NewAddressConversation::begin($bot, data: [$location->latitude, $location->longitude]);

            return;
        }

        $label = __('messages.addresses.label', ['n' => $user->addresses()->count() + 1]);

        // Tuman, qishloq/mahalla va ko'cha geokodlanib, address_text yig'iladi (AddressService::createForPoint).
        $address = $addresses->createForPoint($user, $location->latitude, $location->longitude, [
            'label' => $label,
            'is_default' => true,
        ]);
        $bot->sendMessage(
            __('messages.addresses.added', ['address' => $address->displayAddress()]),
            reply_markup: Keyboards::mainMenu(),
        );
    }
}
