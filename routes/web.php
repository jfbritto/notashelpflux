<?php

use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\NotaasWebhookController;
use App\Http\Controllers\NotaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

// A plataforma tem um trabalho só: emitir nota. Não há página de apresentação
// nem painel; quem entra vai direto para onde trabalha.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'notas.index' : 'login'));

Route::get('/dashboard', fn () => redirect()->route('notas.index'))
    ->middleware(['auth'])
    ->name('dashboard');

// Retorno da Notaas. Fora do CSRF porque é chamada de servidor para servidor,
// e protegido pela assinatura HMAC conferida no controller.
//
// A exclusão parece errada porque o grupo `web` aplica ValidateCsrfToken, mas
// ela funciona: ValidateCsrfToken estende VerifyCsrfToken e o Router compara
// com isSubclassOf. É a mesma linha que segura os webhooks do TreinaEdu em
// produção. O teste de assinatura inválida prova de qualquer forma: com CSRF
// ativo a resposta seria 419, não 401.
Route::post('/webhooks/notaas', [NotaasWebhookController::class, 'handle'])
    ->name('webhooks.notaas')
    ->withoutMiddleware(VerifyCsrfToken::class);

Route::middleware('auth')->group(function () {
    Route::get('/notas', [NotaController::class, 'index'])->name('notas.index');
    Route::get('/notas/nova', [NotaController::class, 'create'])->name('notas.create');
    Route::post('/notas', [NotaController::class, 'store'])->name('notas.store');
    Route::post('/notas/{nota}/cancelar', [NotaController::class, 'cancelar'])->name('notas.cancelar');
    Route::post('/notas/{nota}/verificar', [NotaController::class, 'verificar'])->name('notas.verificar');

    // Conveniência do formulário. Autenticadas para não virarem proxy aberto
    // de consulta a serviço de terceiro em nome do nosso IP.
    Route::get('/consultas/cep/{cep}', [ConsultaController::class, 'cep'])->name('consultas.cep');
    Route::get('/consultas/cnpj/{cnpj}', [ConsultaController::class, 'cnpj'])->name('consultas.cnpj');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
