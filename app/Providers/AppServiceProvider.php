<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
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

        Model::preventLazyLoading(! $this->app->isProduction());

        // GET /up answers 500 when any listener throws, so a dead database or Redis fails the health check.
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');
            Redis::connection()->ping();
        });
    }
}
