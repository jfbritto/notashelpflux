<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcessoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Cadastro público num sistema que emite documento fiscal em nome da
     * empresa seria porta destrancada.
     */
    public function test_nao_existe_cadastro_publico(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_usuario_criado_por_comando_consegue_entrar(): void
    {
        $this->artisan('usuario:criar', [
            'nome' => 'Emissora', 'email' => 'emissora@teste.test', '--papel' => 'emissor',
        ])->assertSuccessful();

        $usuario = User::where('email', 'emissora@teste.test')->first();

        $this->assertNotNull($usuario);
        $this->assertSame('emissor', $usuario->papel);
    }

    /** O default é o menor privilégio. */
    public function test_usuario_nasce_como_emissor_quando_ninguem_diz_o_contrario(): void
    {
        $this->assertSame('emissor', User::factory()->create()->papel);
    }

    public function test_papel_invalido_e_recusado(): void
    {
        $this->artisan('usuario:criar', [
            'nome' => 'Alguem', 'email' => 'alguem@teste.test', '--papel' => 'superusuario',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'alguem@teste.test']);
    }

    public function test_email_repetido_e_recusado(): void
    {
        User::factory()->create(['email' => 'repetido@teste.test']);

        $this->artisan('usuario:criar', [
            'nome' => 'Outro', 'email' => 'repetido@teste.test',
        ])->assertFailed();
    }
}
