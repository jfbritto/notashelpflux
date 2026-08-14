<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O cancelamento na Notaas é ASSÍNCRONO (POST /cancelar responde 202, o
 * desfecho vem depois). Sem marcar a hora do pedido, a nota fica "emitida"
 * indefinidamente enquanto o cancelamento está pendente, e ninguém sabe que
 * há algo em andamento: o mesmo silêncio que a reconciliação de emissão
 * existe para evitar, agora do lado do cancelamento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->timestamp('cancelamento_solicitado_em')->nullable()->after('motivo_cancelamento');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn('cancelamento_solicitado_em');
        });
    }
};
