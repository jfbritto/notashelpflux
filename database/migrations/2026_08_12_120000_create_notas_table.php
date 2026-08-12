<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uma tabela para as três origens. O que muda entre elas é o valor de
 * `origem`, não o formato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas', function (Blueprint $table) {
            $table->id();

            $table->string('origem', 20);                     // treinaedu | helpdiet | manual
            $table->string('referencia_externa')->nullable();  // do SaaS de origem; nula na manual
            $table->string('perfil', 20);                      // software | nutricao

            $table->string('tomador_tipo', 2);                 // pf | pj
            $table->string('tomador_documento', 14);
            $table->string('tomador_nome');
            $table->string('tomador_email')->nullable();
            $table->string('tomador_cep', 8)->nullable();
            $table->string('tomador_logradouro')->nullable();
            $table->string('tomador_numero', 20)->nullable();
            $table->string('tomador_complemento')->nullable();
            $table->string('tomador_bairro')->nullable();
            $table->string('tomador_cidade')->nullable();
            $table->string('tomador_uf', 2)->nullable();
            $table->string('tomador_ibge', 7)->nullable();

            // Onde o serviço foi prestado. NÃO é o município do prestador: numa
            // nota de nutrição real o atendimento foi em Vitória e o ISS ficou
            // em Santa Maria de Jetibá. São dois municípios na mesma nota.
            $table->string('local_prestacao_ibge', 7);
            $table->string('local_prestacao_nome');

            $table->text('descricao');
            $table->decimal('valor', 10, 2);
            $table->string('competencia', 7);                  // AAAA-MM

            $table->string('status', 20)->default('processando'); // processando | emitida | erro | cancelada
            // O elo entre a nossa linha e a nota do emissor. Único porque um id
            // do emissor é uma nota: no TreinaEdu, a falta desse índice deixou
            // passar uma duplicata que só apareceu na tela do cliente.
            $table->string('notaas_invoice_id')->nullable()->unique();
            $table->string('chave_acesso', 60)->nullable();
            $table->string('numero', 60)->nullable();
            $table->string('pdf_url')->nullable();
            $table->string('xml_url')->nullable();
            $table->text('erro')->nullable();

            $table->timestamp('emitida_em')->nullable();
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Idempotência sem código: o mesmo pagamento reenviado não vira
            // duas notas. Nulos são distintos entre si no MySQL, então a
            // emissão manual (sem referência) não esbarra aqui.
            $table->unique(['origem', 'referencia_externa']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notas');
    }
};
