<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Services\EmitirNota;
use App\Services\Emissor\EmissorFalso;
use App\Services\FecharNota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmitirEFecharNotaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EmissorFalso::esquecer();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return array_merge(Nota::factory()->definition(), $extra);
    }

    public function test_emitir_grava_a_nota_e_manda_para_o_emissor(): void
    {
        $nota = app(EmitirNota::class)->emitir($this->dados());

        $this->assertSame('processando', $nota->status);
        $this->assertNotNull($nota->notaas_invoice_id);
    }

    /**
     * A idempotência que evita nota duplicada quando o SaaS reenvia o mesmo
     * pagamento. Devolve a que existe, sem tocar no emissor.
     */
    public function test_a_mesma_referencia_da_mesma_origem_devolve_a_nota_existente(): void
    {
        $primeira = app(EmitirNota::class)->emitir($this->dados([
            'origem' => 'treinaedu', 'referencia_externa' => 'inv-1',
        ]));

        EmissorFalso::proibirEnvio(); // reenviar aqui seria a duplicata

        $segunda = app(EmitirNota::class)->emitir($this->dados([
            'origem' => 'treinaedu', 'referencia_externa' => 'inv-1',
        ]));

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, Nota::count());
    }

    /** Nota manual não tem referência, então duas seguidas são duas notas. */
    public function test_notas_manuais_seguidas_nao_se_confundem(): void
    {
        app(EmitirNota::class)->emitir($this->dados(['referencia_externa' => null]));
        app(EmitirNota::class)->emitir($this->dados(['referencia_externa' => null]));

        $this->assertSame(2, Nota::count());
    }

    public function test_fechar_marca_emitida_com_numero_e_data(): void
    {
        $nota = Nota::factory()->create();

        $houveTransicao = app(FecharNota::class)->aplicar($nota, [
            'status' => 'emitida', 'numero' => '123', 'chave_acesso' => '123',
        ]);

        $this->assertTrue($houveTransicao);
        $this->assertSame('emitida', $nota->fresh()->status);
        $this->assertNotNull($nota->fresh()->emitida_em);
    }

    /**
     * A regra que precisou ser corrigida três vezes no TreinaEdu. Quem aplica
     * um estado que já era o atual não gera transição, e por isso não dispara
     * aviso: é assim que o cliente para de receber dois e-mails da mesma nota.
     */
    public function test_fechar_uma_nota_ja_fechada_no_mesmo_estado_nao_e_transicao(): void
    {
        $nota = Nota::factory()->emitida()->create();

        $houveTransicao = app(FecharNota::class)->aplicar($nota, ['status' => 'emitida', 'numero' => '123']);

        $this->assertFalse($houveTransicao);
    }

    /**
     * Os documentos chegam DEPOIS da emissão, em evento próprio e às vezes
     * duas vezes (XML antes do PDF). Anexar link não é mudar de estado, e não
     * pode avisar ninguém de novo.
     */
    public function test_documentos_prontos_anexam_links_sem_mexer_no_status(): void
    {
        $nota = Nota::factory()->emitida()->create();

        $houveTransicao = app(FecharNota::class)->aplicar($nota, [
            'status' => 'processando',
            'pdf_url' => 'https://storage.notaas.test/n.pdf',
        ]);

        $this->assertFalse($houveTransicao);
        $this->assertSame('emitida', $nota->fresh()->status);
        $this->assertSame('https://storage.notaas.test/n.pdf', $nota->fresh()->pdf_url);
    }

    /** Nota fechada não volta a processar por causa de uma consulta atrasada. */
    public function test_o_estado_nao_regride(): void
    {
        $nota = Nota::factory()->emitida()->create();

        app(FecharNota::class)->aplicar($nota, ['status' => 'processando']);

        $this->assertSame('emitida', $nota->fresh()->status);
    }
}
