<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\User;
use App\Services\Emissor\EmissorFalso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelar nota é ato fiscal, não limpeza de tela: só nota AUTORIZADA se
 * cancela, sempre com justificativa (ela sai no evento da prefeitura), e a
 * recusa do emissor chega inteira a quem clicou, com o caminho alternativo
 * (painel da Notaas) apontado.
 */
class CancelamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EmissorFalso::esquecer();
        parent::tearDown();
    }

    private function emitida(array $atributos = []): Nota
    {
        return Nota::factory()->emitida()->create($atributos + [
            'notaas_invoice_id' => 'abc-'.fake()->unique()->numberBetween(1, 99999),
        ]);
    }

    public function test_cancela_uma_nota_emitida_e_guarda_o_motivo(): void
    {
        $nota = $this->emitida();

        $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Valor lançado errado na emissão'])
            ->assertRedirect(route('notas.index'))
            ->assertSessionHas('sucesso');

        $nota->refresh();
        $this->assertSame('cancelada', $nota->status);
        $this->assertSame('Valor lançado errado na emissão', $nota->motivo_cancelamento);
    }

    /** Nota processando ainda pode virar emitida: não há cancelar em pleno voo. */
    public function test_nota_em_processamento_nao_se_cancela(): void
    {
        $nota = Nota::factory()->create(['status' => 'processando', 'notaas_invoice_id' => 'abc-1']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Motivo comprido o bastante aqui'])
            ->assertSessionHas('erro');

        $this->assertSame('processando', $nota->refresh()->status);
    }

    /** "teste" não pode virar justificativa em documento fiscal. */
    public function test_motivo_curto_e_recusado(): void
    {
        $nota = $this->emitida();

        $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'teste'])
            ->assertSessionHasErrors('motivo');

        $this->assertSame('emitida', $nota->refresh()->status);
    }

    /** O recorte é o mesmo da lista: emissor só alcança nota manual. */
    public function test_emissor_nao_cancela_nota_de_api(): void
    {
        $nota = $this->emitida(['origem' => 'treinaedu', 'referencia_externa' => 'inv-1']);

        $this->actingAs(User::factory()->create(['papel' => 'emissor']))
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Motivo comprido o bastante aqui'])
            ->assertForbidden();

        $this->actingAs(User::factory()->create(['papel' => 'admin']))
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Cancelamento pedido pelo cliente final'])
            ->assertSessionHas('sucesso');
    }

    public function test_recusa_do_emissor_chega_inteira_e_nada_muda(): void
    {
        EmissorFalso::responderCancelamentoCom(['status' => 'erro', 'erro' => 'prazo de cancelamento vencido']);
        $nota = $this->emitida();

        $resposta = $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Motivo comprido o bastante aqui']);

        $resposta->assertSessionHas('erro', fn ($m) => str_contains($m, 'prazo de cancelamento vencido')
            && str_contains($m, 'painel da Notaas'));

        $this->assertSame('emitida', $nota->refresh()->status);
        $this->assertNull($nota->motivo_cancelamento);
    }

    /**
     * Sem webhook na fase 1, nota cancelada pelo painel da Notaas ficaria
     * "emitida" aqui para sempre. O clique no cancelar consulta antes de
     * desistir e sincroniza, em vez de mostrar erro para quem já conseguiu.
     */
    public function test_nota_ja_cancelada_no_emissor_e_sincronizada(): void
    {
        EmissorFalso::responderCancelamentoCom(['status' => 'erro', 'erro' => 'nota ja cancelada']);
        EmissorFalso::responderConsultaCom(['status' => 'cancelada']);
        $nota = $this->emitida();

        $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Cancelada pelo painel do emissor'])
            ->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'já constava cancelada'));

        $this->assertSame('cancelada', $nota->refresh()->status);
    }
}
