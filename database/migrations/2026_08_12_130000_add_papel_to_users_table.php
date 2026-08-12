<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dois papéis, e só dois: `admin` vê todas as origens e administra as chaves
 * de API; `emissor` emite e vê as notas manuais.
 *
 * O default é o menor privilégio, para que um usuário criado sem pensar não
 * nasça enxergando o faturamento dos SaaS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('papel', 20)->default('emissor')->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('papel');
        });
    }
};
