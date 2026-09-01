<?php

use App\Broadcasting\KitchenChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Oshxona paneli — restoran buyurtmalari real-time. Faqat o'sha restoranга
 * tegishli staff (restaurant_owner yoki kitchen_staff). `staff` guard'дан.
 */
Broadcast::channel('kitchen.{restaurantId}', KitchenChannel::class, ['guards' => ['staff']]);
