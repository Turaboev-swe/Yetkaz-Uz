<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Oshxona paneli kirishi (/kitchen/login) — Filament EMAS.
 *
 * `staff` guard + `yetkaz_staff_session` cookie (restoran paneli bilan bir xil).
 * Ruxsat: `Staff::canManageKitchen()` — restaurant_owner YOKI kitchen_staff,
 * faol, restoraniga bog'langan. `restaurant_owner` odatда /restaurant orqali
 * keladi (sessiya umumiy), lekin kitchen_staff faqat shu yerdan kiradi.
 */
class KitchenAuthController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if ($this->authorized()) {
            return redirect()->route('kitchen');
        }

        return view('kitchen-login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('staff')->attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => __('messages.kitchen.invalid')]);
        }

        if (! $this->authorized()) {
            Auth::guard('staff')->logout();

            throw ValidationException::withMessages(['email' => __('messages.kitchen.no_access')]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('kitchen'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('staff')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('kitchen.login');
    }

    private function authorized(): bool
    {
        $staff = Auth::guard('staff')->user();

        return $staff instanceof Staff && $staff->canManageKitchen();
    }
}
