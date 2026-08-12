<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Services\Emissor\Emissor;
use App\Services\Emissor\EmissorFalso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmissorFalsoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EmissorFalso::esquecer();
        parent::tearDown();
    }

    public function test_em_teste_o_emissor_ligado_e_o_falso(): void
    {
        $this->assertInstanceOf(EmissorFalso::class, app(Emissor::class));
    }

    /**
     * A trava que importa mais que qualquer outra neste projeto: em ambiente de
     * teste, nenhum caminho pode chegar na Notaas de verdade.
     */
    public function test_o_emissor_falso_nao_toca_a_rede(): void
    {
        Http::preventStrayRequests();

        $nota = Nota::factory()->create();

        $this->assertSame('processando', app(Emissor::class)->enviar($nota)['status']);
    }

    /**
     * Sem o id, o webhook chega e não há como saber de que nota ele fala, e a
     * reconciliação não tem o que consultar. O falso precisa gravar igual ao
     * real, senão as tasks seguintes quebram longe da causa.
     */
    public function test_o_emissor_falso_tambem_grava_o_id(): void
    {
        $nota = Nota::factory()->create();

        app(Emissor::class)->enviar($nota);

        $this->assertNotNull($nota->fresh()->notaas_invoice_id);
    }

    public function test_a_proibicao_de_envio_explode_de_proposito(): void
    {
        EmissorFalso::proibirEnvio();

        $this->expectException(\RuntimeException::class);

        app(Emissor::class)->enviar(Nota::factory()->create());
    }
}
