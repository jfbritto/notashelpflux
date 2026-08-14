<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O cancelamento no padrão nacional é um evento com justificativa, e a
 * justificativa é dado fiscal: fica na nota, não num log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->text('motivo_cancelamento')->nullable()->after('erro');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table) {
            $table->dropColumn('motivo_cancelamento');
        });
    }
};
