<?php

namespace App\Providers;

use App\Services\api\Translation\Contracts\TranslationProvider;
use App\Services\api\Translation\Providers\FakeTranslationProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TranslationProvider::class, function ($app) {
            return match (config('content_translation.provider', 'fake')) {
                'fake' => $app->make(FakeTranslationProvider::class),
                default => $app->make(FakeTranslationProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
