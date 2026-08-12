<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Services\Emissor\NotaasEmissor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotaasEmissorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Este é o único teste que instancia o emissor real, e ainda assim com
        // HTTP falso. A chave existe só para passar da checagem de configuração.
        config([
            'fiscal.notaas.api_key' => 'chave-de-teste',
            'fiscal.notaas.base_url' => 'https://api.notaas.test/v1',
        ]);
    }

    public function test_o_id_do_emissor_e_guardado_na_hora_do_envio(): void
    {
        Http::fake(['*/emitir' => Http::response(['invoiceId' => 'abc-123', 'status' => 'queued'], 202)]);
        $nota = Nota::factory()->create();

        $retorno = (new NotaasEmissor)->enviar($nota);

        $this->assertSame('processando', $retorno['status']);
        $this->assertSame('abc-123', $nota->fresh()->notaas_invoice_id);
    }

    public function test_falha_no_envio_devolve_erro_com_a_mensagem_do_emissor(): void
    {
        Http::fake(['*/emitir' => Http::response(['message' => 'E0120 inscricao do prestador'], 400)]);

        $retorno = (new NotaasEmissor)->enviar(Nota::factory()->create());

        $this->assertSame('erro', $retorno['status']);
        $this->assertStringContainsString('E0120', $retorno['erro']);
    }

    /**
     * No padrão nacional o número É a chave de acesso, 50 dígitos, e a Notaas
     * devolve a mesma chave em dois campos, num deles com o prefixo "NFS".
     * Gravar o prefixado fez a tela de um cliente mostrar "NFS3204559...".
     */
    public function test_o_prefixo_nfs_e_removido_do_numero_e_da_chave(): void
    {
        $chave = '32045592258063432000121000000000000126089909776545';

        Http::fake(['*/invoices/*/status' => Http::response([
            'status' => 'issued',
            'chNFSe' => $chave,
            'nNFSe' => 'NFS'.$chave,
            'pdfUrl' => 'https://storage.notaas.test/n.pdf',
        ], 200)]);

        $retorno = (new NotaasEmissor)->consultar('abc-123');

        $this->assertSame('emitida', $retorno['status']);
        $this->assertSame($chave, $retorno['numero']);
        $this->assertSame($chave, $retorno['chave_acesso']);
    }

    /** Quando existe número curto de verdade, é ele que vale. */
    public function test_numero_curto_e_preferido_a_chave(): void
    {
        Http::fake(['*/invoices/*/status' => Http::response([
            'status' => 'issued',
            'chNFSe' => '32045592258063432000121000000000000126089909776545',
            'nNFSe' => '185',
        ], 200)]);

        $this->assertSame('185', (new NotaasEmissor)->consultar('abc-123')['numero']);
    }

    /** A Notaas já usou nomes diferentes; cair no terceiro campo é barato. */
    public function test_a_chave_tambem_e_lida_de_numeroNfe(): void
    {
        Http::fake(['*/invoices/*/status' => Http::response([
            'status' => 'authorized',
            'numeroNfe' => 'NFS999',
        ], 200)]);

        $this->assertSame('999', (new NotaasEmissor)->consultar('abc-123')['chave_acesso']);
    }

    public function test_recusa_do_emissor_vira_erro_com_mensagem(): void
    {
        Http::fake(['*/invoices/*/status' => Http::response([
            'status' => 'rejected',
            'errorMessage' => 'E0037 municipio nao conveniado',
        ], 200)]);

        $retorno = (new NotaasEmissor)->consultar('abc-123');

        $this->assertSame('erro', $retorno['status']);
        $this->assertStringContainsString('E0037', $retorno['erro']);
    }

    /**
     * Consulta que falha na rede não pode virar "erro": erro é recusa fiscal, e
     * marcar assim faria a nota parar de ser reconciliada.
     */
    public function test_consulta_que_nao_responde_mantem_a_nota_processando(): void
    {
        Http::fake(['*/invoices/*/status' => Http::response([], 500)]);

        $this->assertSame('processando', (new NotaasEmissor)->consultar('abc-123')['status']);
    }

    public function test_sem_chave_configurada_nao_ha_emissao(): void
    {
        config(['fiscal.notaas.api_key' => null]);
        Http::preventStrayRequests();

        $this->assertSame('erro', (new NotaasEmissor)->enviar(Nota::factory()->create())['status']);
    }
}
