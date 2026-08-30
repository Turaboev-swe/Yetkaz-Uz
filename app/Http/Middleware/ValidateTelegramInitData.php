<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidInitDataException;
use App\Services\Telegram\InitDataValidator;
use App\Services\User\ProfileService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mini App API'ning yagona autentifikatsiya nuqtasi.
 *
 * initData quyidagilardan olinadi (shu tartibda):
 *   - Authorization: tma <initData>
 *   - X-Telegram-Init-Data: <initData>
 *
 * Imzo to'g'ri bo'lsa: foydalanuvchi topiladi/yaratiladi va so'rovga bog'lanadi
 * ($request->user()). Aks holda 401.
 */
class ValidateTelegramInitData
{
    public function __construct(
        private readonly InitDataValidator $validator,
        private readonly ProfileService $profiles,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $initData = $this->extractInitData($request);

        if ($initData === null) {
            return $this->unauthorized('initData berilmagan.');
        }

        try {
            $result = $this->validator->validate($initData);
        } catch (InvalidInitDataException $e) {
            return $this->unauthorized($e->getMessage());
        }

        $tgUser = $result['user'];

        $user = $this->profiles->findOrCreateFromTelegram(
            telegramId: (int) $tgUser['id'],
            languageCode: $tgUser['language_code'] ?? null,
        );
        $this->profiles->touch($user);

        $request->setUserResolver(fn () => $user);
        app()->setLocale($user->language);

        return $next($request);
    }

    private function extractInitData(Request $request): ?string
    {
        $auth = $request->header('Authorization', '');
        if (str_starts_with($auth, 'tma ')) {
            return trim(substr($auth, 4)) ?: null;
        }

        return $request->header('X-Telegram-Init-Data') ?: null;
    }

    private function unauthorized(string $message): Response
    {
        return response()->json([
            'message' => 'Telegram initData tekshiruvidan o\'tmadi.',
            'reason' => $message,
        ], Response::HTTP_UNAUTHORIZED);
    }
}
