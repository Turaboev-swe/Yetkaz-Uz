<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel bo'yicha alohida sessiya cookie'si.
 *
 * `EncryptCookies` va `StartSession` dan OLDIN ishlashi shart — ular
 * `config('session.cookie')` ni o'sha paytda o'qiydi. Shu bilan `/admin`
 * (yetkaz_admin_session) va `/restaurant` + `/kitchen` (yetkaz_staff_session)
 * sessiyalari to'liq ajratiladi — bir brauzerda bir vaqtda kirish mumkin.
 *
 * Middleware alias: `panel.session` (bootstrap/app.php).
 */
class UsePanelSession
{
    public function handle(Request $request, Closure $next, string $cookie): Response
    {
        config(['session.cookie' => $cookie]);

        return $next($request);
    }
}
