<?php

use App\Http\Controllers\Api\NotaApiController;
use App\Http\Middleware\AutenticarAplicacao;
use Illuminate\Support\Facades\Route;

// A API dos SaaS (spec §5). Prefixo /api vem do bootstrap/app.php.
//
// A autenticação vem ANTES do limite, para o limite contar por aplicação (ver
// AppServiceProvider): os SaaS rodam na mesma VPS e sairiam pelo mesmo IP, e
// um limite por IP faria a cobrança de um derrubar a do outro.
Route::middleware([AutenticarAplicacao::class, 'throttle:api'])->group(function () {
    Route::post('/notas', [NotaApiController::class, 'store'])->name('api.notas.store');
    Route::get('/notas', [NotaApiController::class, 'index'])->name('api.notas.index');
    // `.+` para aceitar referência com barra (ex.: "fatura/2026-10").
    Route::get('/notas/{referencia}', [NotaApiController::class, 'show'])
        ->where('referencia', '.+')
        ->name('api.notas.show');
});
