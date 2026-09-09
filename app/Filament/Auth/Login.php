<?php

namespace App\Filament\Auth;

use Filament\Forms\Components\Component;
use Filament\Pages\Auth\Login as BaseLogin;

/**
 * "Eslab qolish" katakchasi oldindan belgilangan Filament login sahifasi.
 *
 * Restoran egasi / oshxona xodimi panelni butun ish kuni ochiq qoldiradi;
 * sessiya (SESSION_LIFETIME) tugasa ham, remember_token cookie ularni avtomat
 * qayta kiritsin. Xodim xohlasa katakchani o'zi olib tashlashi mumkin.
 *
 * /admin (platform_admin) bu sahifani ISHLATMAYDI — u yerda "Eslab qolish"
 * ataylab ixtiyoriy (yuqori imtiyozli hisob).
 */
class Login extends BaseLogin
{
    protected function getRememberFormComponent(): Component
    {
        return parent::getRememberFormComponent()->default(true);
    }
}
