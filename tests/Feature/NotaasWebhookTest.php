<?php

namespace Tests\Feature;

use App\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotaasWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SEGREDO = 'segredo-de-teste';

    /** @param  array<string, mixed>  $corpo */
    private function assinado(array $corpo, ?string $segredo = null): array
    {
        $json = json_encode($corpo);

        return ['X-Notaas-Signature' => 'sha256='.hash_hmac('sha256', $json, $segredo ?? self::SEGREDO)];
    }

    /** @param  array<string, mixed>  $corpo */
    private function enviar(array $corpo, array $headers = [])
    {
        return $this->call(
            'POST',
            '/webhooks/notaas',
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers + ['Content-Type' => 'application/json']),
            json_encode($corpo),
        );
    }

    public function test_assinatura_invalida_e_recusada(): void
    {
        config(['fiscal.notaas.webhook_secret' => self::SEGREDO]);
        $nota = Nota::factory()->create(['notaas_invoice_id' => 'abc-123']);

        $corpo = ['event' => 'nfse.issued', 'data' => ['invoiceId' => 'abc-123']];

        // 401 e não 419: se o CSRF estivesse ativo nesta rota, seria 419, e
        // este teste também prova que a exclusão de middleware funciona.
        $this->enviar($corpo, $this->assinado($corpo, 'segredo-errado'))->assertStatus(401);

        $this->assertSame('processando', $nota->fresh()->status);
    }

    /** O estado do primeiro dia, antes de cadastrar o segredo no painel. */
    public function test_sem_segredo_configurado_o_webhook_passa(): void
    {
        config(['fiscal.notaas.webhook_secret' => null]);
        Nota::factory()->create(['notaas_invoice_id' => 'abc-123']);

        $this->enviar(['event' => 'nfse.issued', 'data' => [
            'invoiceId' => 'abc-123', 'chNFSe' => '123',
        ]])->assertOk();
    }

    public function test_o_evento_de_emissao_fecha_a_nota_pelo_id_do_emissor(): void
    {
        config(['fiscal.notaas.webhook_secret' => self::SEGREDO]);
        $nota = Nota::factory()->create(['notaas_invoice_id' => 'abc-123']);
        $chave = '32045592258063432000121000000000000126089909776545';

        $corpo = ['event' => 'nfse.issued', 'data' => [
            'invoiceId' => 'abc-123',
            'status' => 'issued',
            'chNFSe' => $chave,
            'nNFSe' => 'NFS'.$chave,
            'pdfUrl' => 'https://storage.notaas.test/n.pdf',
        ]];

        $this->enviar($corpo, $this->assinado($corpo))->assertOk();

        $nota->refresh();
        $this->assertSame('emitida', $nota->status);
        $this->assertSame($chave, $nota->numero);
        $this->assertNotNull($nota->emitida_em);
    }

    /**
     * Chega depois da emissão, e às vezes duas vezes. Só anexa links: mexer no
     * status aqui faria a nota "reabrir" e avisar o cliente de novo.
     */
    public function test_documents_ready_anexa_links_e_nao_mexe_no_status(): void
    {
        config(['fiscal.notaas.webhook_secret' => null]);
        $nota = Nota::factory()->emitida()->create(['notaas_invoice_id' => 'abc-123']);

        $this->enviar(['event' => 'nfse.documents_ready', 'data' => [
            'invoiceId' => 'abc-123', 'xmlUrl' => 'https://storage.notaas.test/n.xml',
        ]])->assertOk();

        $nota->refresh();
        $this->assertSame('emitida', $nota->status);
        $this->assertSame('https://storage.notaas.test/n.xml', $nota->xml_url);
    }

    /** Depender só do corpo ou só do header é apostar em qual eles mantêm. */
    public function test_o_tipo_do_evento_e_aceito_no_header(): void
    {
        config(['fiscal.notaas.webhook_secret' => null]);
        $nota = Nota::factory()->create(['notaas_invoice_id' => 'abc-123']);

        $this->enviar(
            ['data' => ['invoiceId' => 'abc-123', 'errorMessage' => 'E0120']],
            ['X-Notaas-Event' => 'nfse.error'],
        )->assertOk();

        $this->assertSame('erro', $nota->fresh()->status);
    }

    public function test_a_nota_tambem_e_achada_pela_referencia(): void
    {
        config(['fiscal.notaas.webhook_secret' => null]);
        $nota = Nota::factory()->create([
            'origem' => 'treinaedu', 'referencia_externa' => 'inv-9', 'notaas_invoice_id' => null,
        ]);

        $this->enviar(['event' => 'nfse.issued', 'data' => [
            'referencia' => 'inv-9', 'status' => 'issued', 'chNFSe' => '123',
        ]])->assertOk();

        $this->assertSame('emitida', $nota->fresh()->status);
    }

    /** Nota que não conhecemos não pode fazer o emissor reenfileirar para sempre. */
    public function test_nota_desconhecida_responde_ok(): void
    {
        config(['fiscal.notaas.webhook_secret' => null]);

        $this->enviar(['event' => 'nfse.issued', 'data' => ['invoiceId' => 'nao-existe']])->assertOk();
    }
}
