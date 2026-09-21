<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(function () {
            $rule = Password::min(8)
                ->max(64)
                ->letters()
                ->mixedCase()
                ->numbers()
                ->symbols();

            return $this->app->environment('testing')
                ? $rule
                : $rule->uncompromised();
        });

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
    }
}
