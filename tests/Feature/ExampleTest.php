<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A plataforma tem um trabalho só: emitir nota. Não há página de apresentação
 * nem painel, então a raiz leva direto para onde a pessoa trabalha.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_raiz_leva_o_visitante_para_o_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_a_raiz_leva_quem_ja_entrou_para_as_notas(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('notas.index'));
    }

    public function test_o_dashboard_do_esqueleto_tambem_cai_nas_notas(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertRedirect(route('notas.index'));
    }
}
