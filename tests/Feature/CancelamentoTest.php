<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\User;
use App\Services\Emissor\EmissorFalso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cancelar nota é ato fiscal, não limpeza de tela.
 *
 * POST /cancelar na Notaas é ASSÍNCRONO (responde 202; o desfecho vem
 * depois), conferido contra https://docs.notaas.com.br/endpoints. O caso
 * comum aqui é "cancelamento solicitado, aguardando confirmação", não
 * "cancelada" na hora — o oposto do que a primeira versão deste arquivo
 * assumia, antes de a documentação real aparecer.
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

    /** O caso comum: o pedido é aceito, a nota permanece emitida até confirmar. */
    public function test_pede_o_cancelamento_e_a_nota_continua_emitida_ate_confirmar(): void
    {
        $nota = $this->emitida();

        $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Valor lançado errado na emissão'])
            ->assertRedirect(route('notas.index'))
            ->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'Cancelamento solicitado'));

        $nota->refresh();
        // Ainda EMITIDA: o documento continua valendo até o emissor confirmar.
        $this->assertSame('emitida', $nota->status);
        $this->assertSame('Valor lançado errado na emissão', $nota->motivo_cancelamento);
        $this->assertNotNull($nota->cancelamento_solicitado_em);
    }

    /** Não se pede cancelamento duas vezes sobre o mesmo pendente. */
    public function test_nao_pede_cancelamento_de_novo_se_ja_esta_pendente(): void
    {
        $nota = $this->emitida(['cancelamento_solicitado_em' => now()]);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Motivo comprido o bastante aqui'])
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'já foi solicitado'));
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

    /** O teto é o do PRÓPRIO EMISSOR (campo `motivo`, max 255): passar disso ele recusaria. */
    public function test_motivo_acima_do_teto_do_emissor_e_recusado(): void
    {
        $nota = $this->emitida();

        $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => str_repeat('a', 256)])
            ->assertSessionHasErrors('motivo');
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

    /** O raro: o emissor já devolve o fechamento na mesma chamada. */
    public function test_quando_o_emissor_fecha_na_hora_a_nota_ja_sai_cancelada(): void
    {
        EmissorFalso::responderCancelamentoCom(['status' => 'cancelada']);
        $nota = $this->emitida();

        $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Motivo comprido o bastante aqui'])
            ->assertSessionHas('sucesso', 'Nota cancelada no emissor.');

        $this->assertSame('cancelada', $nota->refresh()->status);
    }

    public function test_recusa_de_verdade_do_emissor_chega_inteira_e_nada_muda(): void
    {
        EmissorFalso::responderCancelamentoCom(['status' => 'erro', 'erro' => 'prazo de cancelamento vencido']);
        $nota = $this->emitida();

        $resposta = $this->actingAs(User::factory()->create())
            ->post(route('notas.cancelar', $nota), ['motivo' => 'Motivo comprido o bastante aqui']);

        $resposta->assertSessionHas('erro', fn ($m) => str_contains($m, 'prazo de cancelamento vencido')
            && str_contains($m, 'painel da Notaas'));

        $this->assertSame('emitida', $nota->refresh()->status);
        $this->assertNull($nota->motivo_cancelamento);
        $this->assertNull($nota->cancelamento_solicitado_em);
    }

    /**
     * Sem webhook na fase 1, nota cancelada pelo painel da Notaas ficaria
     * "emitida" aqui para sempre se o pedido direto falhasse. O clique
     * consulta antes de desistir e sincroniza, em vez de mostrar erro para
     * quem já conseguiu por fora.
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

    /**
     * A reconciliação é quem confirma o desfecho do caso comum: consulta o
     * cancelamento pendente e fecha quando o emissor confirmar.
     */
    public function test_a_reconciliacao_confirma_o_cancelamento_pendente(): void
    {
        $nota = $this->emitida(['cancelamento_solicitado_em' => now()->subHour()]);
        EmissorFalso::responderConsultaCom(['status' => 'cancelada']);

        $this->artisan('notas:reconciliar')->assertSuccessful();

        $this->assertSame('cancelada', $nota->refresh()->status);
    }

    /** A janela existe para não competir com o webhook. */
    public function test_a_reconciliacao_nao_confere_cancelamento_recem_pedido(): void
    {
        $nota = $this->emitida(['cancelamento_solicitado_em' => now()]);
        EmissorFalso::responderConsultaCom(['status' => 'cancelada']);

        $this->artisan('notas:reconciliar')->assertSuccessful();

        $this->assertSame('emitida', $nota->refresh()->status);
    }
}
