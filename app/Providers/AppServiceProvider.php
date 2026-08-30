<?php

namespace App\Providers;

use App\Services\Telegram\InitDataValidator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InitDataValidator::class, fn () => new InitDataValidator(
            botToken: (string) config('nutgram.token'),
            ttlSeconds: (int) config('telegram.init_data_ttl', 86400),
        ));
    }

    public function boot(): void
    {
        //
    }
}
