<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Services\Emissor\EmissorFalso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        EmissorFalso::esquecer();
        parent::tearDown();
    }

    /** Nota parada, com id do emissor, velha o bastante para ser olhada. */
    private function notaParada(array $atributos = [], ?string $idade = null): Nota
    {
        $nota = Nota::factory()->create($atributos + [
            'status' => 'processando',
            'notaas_invoice_id' => 'abc-'.fake()->unique()->numberBetween(1, 99999),
        ]);

        // `created_at` não é fillable: passá-la no create seria silenciosamente
        // ignorada, e a nota nasceria recente demais para a reconciliação.
        $nota->forceFill(['created_at' => $idade ?? now()->subHours(2)])->save();

        return $nota;
    }

    public function test_nota_que_saiu_no_emissor_e_fechada(): void
    {
        $nota = $this->notaParada();
        EmissorFalso::responderConsultaCom([
            'status' => 'emitida',
            'numero' => '32045592258063432000121000000000000126089909776545',
            'pdf_url' => 'https://storage.notaas.test/n.pdf',
        ]);

        $this->artisan('notas:reconciliar')->assertSuccessful();

        $nota->refresh();
        $this->assertSame('emitida', $nota->status);
        $this->assertSame('https://storage.notaas.test/n.pdf', $nota->pdf_url);
        $this->assertNotNull($nota->emitida_em);
    }

    public function test_nota_recusada_no_emissor_grava_o_erro(): void
    {
        $nota = $this->notaParada();
        EmissorFalso::responderConsultaCom(['status' => 'erro', 'erro' => 'E0120 inscricao do prestador']);

        $this->artisan('notas:reconciliar')->assertSuccessful();

        $this->assertSame('erro', $nota->refresh()->status);
        $this->assertStringContainsString('E0120', $nota->erro);
    }

    public function test_nota_ainda_em_processamento_fica_como_esta(): void
    {
        $nota = $this->notaParada();
        EmissorFalso::responderConsultaCom(['status' => 'processando']);

        $this->artisan('notas:reconciliar')->assertSuccessful();

        $this->assertSame('processando', $nota->refresh()->status);
    }

    /**
     * A janela existe para a reconciliação não competir com o webhook, que é
     * quem fecha a nota no caminho normal.
     */
    public function test_nota_recem_enviada_nao_e_consultada(): void
    {
        $nota = $this->notaParada(idade: now()->toDateTimeString());
        EmissorFalso::responderConsultaCom(['status' => 'emitida', 'numero' => '123']);

        $this->artisan('notas:reconciliar')->assertSuccessful();

        $this->assertSame('processando', $nota->refresh()->status);
    }

    /**
     * Sem id do emissor não há o que consultar, e a reconciliação não tem
     * licença para emitir.
     */
    public function test_nota_sem_id_do_emissor_e_deixada_de_fora(): void
    {
        $nota = Nota::factory()->create(['status' => 'processando', 'notaas_invoice_id' => null]);
        $nota->forceFill(['created_at' => now()->subHours(2)])->save();

        EmissorFalso::responderConsultaCom(['status' => 'emitida', 'numero' => '123']);

        $this->artisan('notas:reconciliar')->assertSuccessful();

        $this->assertSame('processando', $nota->refresh()->status);
    }

    /**
     * CONSULTA, nunca reemite. Reenviar uma nota que o emissor já autorizou
     * geraria uma segunda NFS-e do mesmo serviço. O emissor falso explode se
     * `enviar()` for chamado, então este teste passa por garantia e não por
     * coincidência.
     */
    public function test_a_reconciliacao_nunca_emite(): void
    {
        $this->notaParada();
        EmissorFalso::proibirEnvio();
        EmissorFalso::responderConsultaCom(['status' => 'emitida', 'numero' => '123']);

        $this->artisan('notas:reconciliar')->assertSuccessful();
    }

    public function test_o_dry_nao_muda_nada(): void
    {
        $nota = $this->notaParada();
        EmissorFalso::responderConsultaCom(['status' => 'emitida', 'numero' => '123']);

        $this->artisan('notas:reconciliar', ['--dry' => true])->assertSuccessful();

        $this->assertSame('processando', $nota->refresh()->status);
    }

    /** Nota emitida sem PDF nem XML, como a da Juliana em 21/10/2026. */
    private function emitidaSemDocumento(string $emitidaHa = '10 minutes'): Nota
    {
        return Nota::factory()->emitida()->create([
            'notaas_invoice_id' => 'abc-'.fake()->unique()->numberBetween(1, 99999),
            'emitida_em' => now()->sub($emitidaHa),
        ]);
    }

    /**
     * A consulta que fechou a nota pode chegar antes de a Notaas guardar os
     * documentos (documentsCached=false), e o nfse.documents_ready, que
     * traria os links, ela desiste de mandar depois das tentativas dela. Sem
     * ir atrás, a nota fica emitida e sem PDF para sempre.
     */
    public function test_nota_emitida_sem_documento_ganha_os_links(): void
    {
        $nota = $this->emitidaSemDocumento();
        EmissorFalso::responderConsultaCom([
            'status' => 'emitida',
            'pdf_url' => 'https://storage.notaas.test/n.pdf',
            'xml_url' => 'https://storage.notaas.test/n.xml',
        ]);

        $this->artisan('notas:reconciliar', ['--minutos' => 2])->assertSuccessful();

        $nota->refresh();
        $this->assertSame('emitida', $nota->status);
        $this->assertSame('https://storage.notaas.test/n.pdf', $nota->pdf_url);
        $this->assertSame('https://storage.notaas.test/n.xml', $nota->xml_url);
    }

    /** Ir atrás do documento só anexa links: a situação da nota não muda. */
    public function test_buscar_documento_nunca_muda_a_situacao(): void
    {
        $nota = $this->emitidaSemDocumento();
        EmissorFalso::responderConsultaCom(['status' => 'erro', 'erro' => 'falha qualquer']);

        $this->artisan('notas:reconciliar', ['--minutos' => 2])->assertSuccessful();

        $this->assertSame('emitida', $nota->refresh()->status);
        $this->assertNull($nota->erro);
    }

    /**
     * A Notaas guarda os documentos em minutos. Passado um dia, consultar a
     * cada 5 minutos não traz nada, e a nota sai da lista de quem vai atrás.
     */
    public function test_nota_emitida_sem_documento_ha_mais_de_um_dia_fica_de_fora(): void
    {
        $nota = $this->emitidaSemDocumento('2 days');
        EmissorFalso::responderConsultaCom(['status' => 'emitida', 'pdf_url' => 'https://storage.notaas.test/n.pdf']);

        $this->artisan('notas:reconciliar', ['--minutos' => 2])->assertSuccessful();

        $this->assertNull($nota->refresh()->pdf_url);
    }

    /** Recém-emitida ainda tem o webhook de documentos a caminho. */
    public function test_nota_recem_emitida_espera_o_webhook_de_documentos(): void
    {
        $nota = $this->emitidaSemDocumento('1 minute');
        EmissorFalso::responderConsultaCom(['status' => 'emitida', 'pdf_url' => 'https://storage.notaas.test/n.pdf']);

        $this->artisan('notas:reconciliar', ['--minutos' => 2])->assertSuccessful();

        $this->assertNull($nota->refresh()->pdf_url);
    }
}
