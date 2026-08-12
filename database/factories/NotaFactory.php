<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A nota padrão da fábrica é a de nutrição, que é a única que a plataforma
 * emite hoje: tomador pessoa jurídica em Vitória, atendimento em Vitória,
 * ISS devido em Santa Maria de Jetibá.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Nota>
 */
class NotaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'origem' => 'manual',
            'referencia_externa' => null,
            'perfil' => 'nutricao',

            'tomador_tipo' => 'pj',
            'tomador_documento' => '11222333000181',
            'tomador_nome' => 'Clínica Exemplo Ltda',
            'tomador_email' => 'financeiro@exemplo.test',
            'tomador_cep' => '29055450',
            'tomador_logradouro' => 'Rua Exemplo',
            'tomador_numero' => '78',
            'tomador_bairro' => 'Praia do Canto',
            'tomador_cidade' => 'Vitória',
            'tomador_uf' => 'ES',
            'tomador_ibge' => '3205309',

            'local_prestacao_ibge' => '3205309',
            'local_prestacao_nome' => 'Vitória',

            'descricao' => 'Atendimentos nutricionais',
            'valor' => 300.00,
            'competencia' => now()->format('Y-m'),
            'status' => 'processando',
        ];
    }

    public function emitida(): static
    {
        return $this->state(fn () => [
            'status' => 'emitida',
            'numero' => '32045592258063432000121000000000000126089909776545',
            'chave_acesso' => '32045592258063432000121000000000000126089909776545',
            'emitida_em' => now(),
        ]);
    }
}
