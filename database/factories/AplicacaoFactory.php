<?php

namespace Database\Factories;

use App\Models\Aplicacao;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A aplicação padrão da fábrica é o Hotel em Dia, o primeiro SaaS a emitir
 * pela API. A chave em texto claro não existe no banco; o teste que precisa
 * dela usa `comChave()`.
 *
 * @extends Factory<Aplicacao>
 */
class AplicacaoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'nome' => 'Hotel em Dia',
            'origem' => 'hotelemdia',
            'perfil' => 'software',
            'chave_hash' => Aplicacao::hashDaChave(bin2hex(random_bytes(32))),
            'callback_url' => 'https://hotelemdia.test/webhooks/notas',
            'segredo_callback' => 'segredo-do-retorno',
            'revogada_em' => null,
        ];
    }

    public function comChave(string $chave): static
    {
        return $this->state(fn () => ['chave_hash' => Aplicacao::hashDaChave($chave)]);
    }

    public function revogada(): static
    {
        return $this->state(fn () => ['revogada_em' => now()]);
    }
}
