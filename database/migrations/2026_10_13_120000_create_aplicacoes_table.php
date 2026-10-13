<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada SaaS que emite pela API é uma linha: quem é e o que pode emitir.
 *
 * Dois segredos, com regras opostas, e confundi-los quebra um dos lados:
 *
 *   - a CHAVE DE API só é conferida, então guardamos o hash (sha256) e a
 *     mostramos uma única vez, na criação;
 *   - o SEGREDO DO RETORNO precisa ser lido para assinar o POST que vai ao
 *     SaaS, então é guardado cifrado (cast `encrypted`), não hasheado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicacoes', function (Blueprint $table) {
            $table->id();

            $table->string('nome');
            // O valor que vai para notas.origem (ver config/fiscal.php, 'origens').
            $table->string('origem', 20)->index();
            // Perfil de serviço FIXO da aplicação: o SaaS não escolhe código fiscal.
            $table->string('perfil', 20);

            // Busca-se pelo hash da chave recebida, por isso o índice único.
            $table->char('chave_hash', 64)->unique();

            $table->string('callback_url');
            // Texto e não string: o valor cifrado é bem maior que o segredo.
            $table->text('segredo_callback');

            // Revogar sem apagar: as notas da origem continuam com histórico.
            $table->timestamp('revogada_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicacoes');
    }
};
