<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Chave crua na tela.
 *
 * Ao trocar o locale para pt_BR sem publicar os arquivos de tradução, e com o
 * fallback também em pt_BR, o Laravel não tinha para onde cair e mostrava a
 * própria chave: quem errava a senha lia "auth.failed" na tela.
 *
 * Duas defesas: as traduções existem, e o fallback é o inglês, de modo que uma
 * chave nova sem tradução mostre texto em inglês (feio, mas legível) em vez de
 * um identificador de código.
 */
class MensagensEmPortuguesTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_errado_mostra_mensagem_e_nao_a_chave(): void
    {
        User::factory()->create(['email' => 'alguem@teste.test']);

        $this->from('/login')
            ->post('/login', ['email' => 'alguem@teste.test', 'password' => 'errada'])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha não conferem.']);
    }

    public function test_campo_obrigatorio_fala_o_nome_do_campo_da_tela(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('notas.store'), [])
            ->assertSessionHasErrors(['tomador_nome' => 'o nome do cliente é obrigatório.']);
    }

    public function test_o_fallback_e_o_ingles_para_nunca_vazar_chave(): void
    {
        $this->assertSame('pt_BR', config('app.locale'));
        $this->assertSame('en', config('app.fallback_locale'));
    }

    /**
     * Varredura: nenhuma mensagem devolvida por um formulário pode parecer
     * identificador de código (grupo.chave, sem espaço).
     */
    public function test_nenhuma_mensagem_de_erro_parece_chave_de_traducao(): void
    {
        $resposta = $this->actingAs(User::factory()->create())
            ->post(route('notas.store'), ['valor' => 'abc']);

        // A sessão devolve ora o objeto de erros, ora um array simples,
        // dependendo de como a validação foi flasheada.
        $erros = $resposta->getSession()->get('errors');
        $mensagens = is_array($erros) ? Arr::flatten($erros) : $erros->all();

        $this->assertNotEmpty($mensagens, 'A varredura precisa de erros para varrer.');

        foreach ($mensagens as $mensagem) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[a-z_]+\.[a-z_.]+$/',
                $mensagem,
                "A mensagem \"{$mensagem}\" é uma chave de tradução, não um texto.",
            );
        }
    }
}
