<?php

namespace Tests\Feature;

use App\Models\Nota;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A idempotência vem do banco, não de código: o mesmo pagamento reenviado
     * pela API não pode virar duas notas.
     */
    public function test_a_mesma_referencia_da_mesma_origem_nao_entra_duas_vezes(): void
    {
        Nota::factory()->create(['origem' => 'treinaedu', 'referencia_externa' => 'inv-1']);

        $this->expectException(QueryException::class);

        Nota::factory()->create(['origem' => 'treinaedu', 'referencia_externa' => 'inv-1']);
    }

    /**
     * Nota manual não tem referência de origem. O MySQL trata nulos como
     * distintos entre si, então o índice único não atrapalha a emissão avulsa.
     * Em SQLite isto se comporta diferente, e é por isso que a suíte roda em
     * MySQL.
     */
    public function test_varias_notas_manuais_convivem_sem_referencia(): void
    {
        Nota::factory()->count(3)->create(['origem' => 'manual', 'referencia_externa' => null]);

        $this->assertSame(3, Nota::count());
    }

    /** Um id do emissor é uma nota. Duas linhas com o mesmo id é duplicata. */
    public function test_o_id_do_emissor_nao_se_repete(): void
    {
        Nota::factory()->create(['notaas_invoice_id' => 'abc-123']);

        $this->expectException(QueryException::class);

        Nota::factory()->create(['notaas_invoice_id' => 'abc-123']);
    }

    public function test_a_nota_sabe_qual_perfil_de_servico_usou(): void
    {
        $nota = Nota::factory()->create(['perfil' => 'nutricao']);

        $this->assertSame('041001', $nota->perfilDeServico()['codigo_tributacao_nacional']);
    }

    public function test_nota_fechada_e_a_que_nao_espera_mais_nada(): void
    {
        $this->assertFalse(Nota::factory()->create(['status' => 'processando'])->estaFechada());
        $this->assertTrue(Nota::factory()->create(['status' => 'emitida'])->estaFechada());
        $this->assertTrue(Nota::factory()->create(['status' => 'erro'])->estaFechada());
    }
}
