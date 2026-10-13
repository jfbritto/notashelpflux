<?php

namespace App\Providers;

use App\Services\Emissor\Emissor;
use App\Services\Emissor\EmissorFalso;
use App\Services\Emissor\NotaasEmissor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->app->bind(Emissor::class, fn () => match (config('fiscal.emissor')) {
            'fake' => new EmissorFalso,
            default => new NotaasEmissor,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Limite da API dos SaaS, por APLICAÇÃO e não por IP: os SaaS rodam
        // na mesma VPS e sairiam pelo mesmo endereço. 120 por minuto folga
        // para a cobrança mensal de um SaaS sair num lote só, e ainda segura
        // um cliente em laço antes que ele vire uma fila de notas na Notaas.
        RateLimiter::for('api', function (Request $request) {
            $aplicacao = $request->attributes->get('aplicacao');

            return Limit::perMinute(120)
                ->by($aplicacao ? 'aplicacao:'.$aplicacao->id : 'ip:'.$request->ip());
        });
    }
}
