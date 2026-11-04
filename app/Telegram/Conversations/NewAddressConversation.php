<?php

namespace App\Telegram\Conversations;

use App\Exceptions\StreetRequiredException;
use App\Models\User;
use App\Services\Geo\AddressGeocoder;
use App\Services\User\AddressService;
use App\Telegram\Conversations\Concerns\AsksForStreet;
use App\Telegram\Support\Keyboards;
use SergiX44\Nutgram\Conversations\Conversation;
use SergiX44\Nutgram\Nutgram;

/**
 * «📍 Yangi manzil» lokatsiyasi: geokoder ko'cha nomini TOPMAGAN bo'lsa mijozdan ko'cha yoki mo'ljal so'raladi
 * (ko'cha topilganda NewAddressHandler manzilni darhol saqlaydi va bu suhbat boshlanmaydi).
 */
class NewAddressConversation extends Conversation
{
    use AsksForStreet;

    public function start(Nutgram $bot, float $lat, float $lng): void
    {
        $this->lat = $lat;
        $this->lng = $lng;

        $this->askStreet($bot);
    }

    protected function handleNewPoint(Nutgram $bot, float $lat, float $lng): void
    {
        $this->lat = $lat;
        $this->lng = $lng;

        // Yangi nuqtada ko'cha topilsa — savolsiz saqlanadi.
        if (app(AddressGeocoder::class)->describe($lat, $lng)['street'] !== null) {
            $this->finishAddress($bot, null, null);

            return;
        }

        $this->askStreet($bot);
    }

    protected function finishAddress(Nutgram $bot, ?string $street, ?string $landmark): void
    {
        /** @var User $user */
        $user = $bot->get('user');

        try {
            $address = app(AddressService::class)->createForPoint($user, (float) $this->lat, (float) $this->lng, [
                'label' => __('messages.addresses.label', ['n' => $user->addresses()->count() + 1]),
                'street' => $street,
                'note' => $landmark,
                'is_default' => true,
            ]);
        } catch (StreetRequiredException) {
            // Ko'cha ham, mo'ljal ham yo'q — qayta so'raymiz (bu yerga odatda kelinmaydi).
            $this->askStreet($bot);

            return;
        }

        $this->end();
        $bot->sendMessage(
            __('messages.addresses.added', ['address' => $address->displayAddress()]),
            reply_markup: Keyboards::mainMenu(),
        );
    }
}
