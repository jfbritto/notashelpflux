<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Retorno da Notaas. Fora do CSRF porque é chamada de servidor para servidor,
// e protegido pela assinatura HMAC conferida no controller.
//
// A exclusão parece errada porque o grupo `web` aplica ValidateCsrfToken, mas
// ela funciona: ValidateCsrfToken estende VerifyCsrfToken e o Router compara
// com isSubclassOf. É a mesma linha que segura os webhooks do TreinaEdu em
// produção. O teste de assinatura inválida prova de qualquer forma: com CSRF
// ativo a resposta seria 419, não 401.
Route::post('/webhooks/notaas', [\App\Http\Controllers\NotaasWebhookController::class, 'handle'])
    ->name('webhooks.notaas')
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

Route::middleware('auth')->group(function () {
    Route::get('/notas', [\App\Http\Controllers\NotaController::class, 'index'])->name('notas.index');
    Route::get('/notas/nova', [\App\Http\Controllers\NotaController::class, 'create'])->name('notas.create');
    Route::post('/notas', [\App\Http\Controllers\NotaController::class, 'store'])->name('notas.store');

    // Conveniência do formulário. Autenticadas para não virarem proxy aberto
    // de consulta a serviço de terceiro em nome do nosso IP.
    Route::get('/consultas/cep/{cep}', [\App\Http\Controllers\ConsultaController::class, 'cep'])->name('consultas.cep');
    Route::get('/consultas/cnpj/{cnpj}', [\App\Http\Controllers\ConsultaController::class, 'cnpj'])->name('consultas.cnpj');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
