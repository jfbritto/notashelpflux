<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Emissor plugável. O falso é ligado em teste e no E2E pelo
        // FISCAL_EMISSOR; trocar de emissor um dia é mudar essa variável.
        $this->app->bind(\App\Services\Emissor\Emissor::class, fn () => match (config('fiscal.emissor')) {
            'fake' => new \App\Services\Emissor\EmissorFalso(),
            default => new \App\Services\Emissor\NotaasEmissor(),
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
