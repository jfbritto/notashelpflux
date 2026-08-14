<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\User;
use App\Services\Emissor\EmissorFalso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * O botão "Verificar agora": mesma consulta que a reconciliação faz a cada
 * 5 min, só que sob demanda, pra quem está olhando a tela e não quer esperar.
 */
class VerificacaoManualTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EmissorFalso::esquecer();
        // O cache 'array' não se limpa sozinho entre testes (RefreshDatabase
        // só cuida do banco). Sem isto, o freio anti-clique-repetido de um
        // teste vazaria pro próximo.
        Cache::flush();
        parent::tearDown();
    }

    public function test_confirma_emissao_pendente_na_hora(): void
    {
        $nota = Nota::factory()->create(['status' => 'processando', 'notaas_invoice_id' => 'abc-1']);
        EmissorFalso::responderConsultaCom(['status' => 'emitida', 'numero' => '123']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.verificar', $nota))
            ->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'autorizada'));

        $this->assertSame('emitida', $nota->refresh()->status);
    }

    public function test_emissao_ainda_sem_desfecho_avisa_e_nao_muda_nada(): void
    {
        $nota = Nota::factory()->create(['status' => 'processando', 'notaas_invoice_id' => 'abc-1']);
        EmissorFalso::responderConsultaCom(['status' => 'processando']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.verificar', $nota))
            ->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'Ainda sem confirmação'));

        $this->assertSame('processando', $nota->refresh()->status);
    }

    public function test_recusa_na_emissao_tambem_fecha_na_hora(): void
    {
        $nota = Nota::factory()->create(['status' => 'processando', 'notaas_invoice_id' => 'abc-1']);
        EmissorFalso::responderConsultaCom(['status' => 'erro', 'erro' => 'E0120 inscricao do prestador']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.verificar', $nota))
            ->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'E0120 inscricao do prestador'));

        $this->assertSame('erro', $nota->refresh()->status);
    }

    public function test_confirma_cancelamento_pendente_na_hora(): void
    {
        $nota = Nota::factory()->emitida()->create([
            'notaas_invoice_id' => 'abc-1', 'cancelamento_solicitado_em' => now()->subMinutes(3),
        ]);
        EmissorFalso::responderConsultaCom(['status' => 'cancelada']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.verificar', $nota))
            ->assertSessionHas('sucesso', 'Cancelamento confirmado.');

        $this->assertSame('cancelada', $nota->refresh()->status);
    }

    public function test_cancelamento_ainda_sem_desfecho_avisa_e_nao_muda_nada(): void
    {
        $nota = Nota::factory()->emitida()->create([
            'notaas_invoice_id' => 'abc-1', 'cancelamento_solicitado_em' => now()->subMinutes(3),
        ]);
        EmissorFalso::responderConsultaCom(['status' => 'processando']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.verificar', $nota))
            ->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'Ainda sem confirmação'));

        $this->assertSame('emitida', $nota->refresh()->status);
    }

    /** Nota sem nada pendente não tem o que verificar: nem chega a consultar o emissor. */
    public function test_nota_sem_nada_pendente_e_recusada(): void
    {
        $nota = Nota::factory()->emitida()->create(['notaas_invoice_id' => 'abc-1']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.verificar', $nota))
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'nada pendente'));

        $this->assertSame('emitida', $nota->refresh()->status);
    }

    /**
     * O freio: sem ele, um clique impaciente vira uma consulta por segundo
     * na Notaas. A segunda tentativa nem chega a consultar de novo, por isso
     * o teste muda a resposta entre as duas chamadas e confirma que a
     * segunda não pegou o novo valor.
     */
    public function test_verificacoes_muito_seguidas_sao_bloqueadas(): void
    {
        $nota = Nota::factory()->create(['status' => 'processando', 'notaas_invoice_id' => 'abc-1']);
        EmissorFalso::responderConsultaCom(['status' => 'processando']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.verificar', $nota))
            ->assertSessionHas('sucesso', fn ($m) => str_contains($m, 'Ainda sem confirmação'));

        EmissorFalso::responderConsultaCom(['status' => 'emitida']);

        $this->actingAs(User::factory()->create())
            ->post(route('notas.verificar', $nota))
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'Aguarde'));

        $this->assertSame('processando', $nota->refresh()->status);
    }

    /** O mesmo recorte do cancelamento: quem não é admin só alcança nota manual. */
    public function test_emissor_nao_verifica_nota_de_api(): void
    {
        $nota = Nota::factory()->create([
            'status' => 'processando', 'notaas_invoice_id' => 'abc-1',
            'origem' => 'treinaedu', 'referencia_externa' => 'inv-1',
        ]);

        $this->actingAs(User::factory()->create(['papel' => 'emissor']))
            ->post(route('notas.verificar', $nota))
            ->assertForbidden();
    }
}
