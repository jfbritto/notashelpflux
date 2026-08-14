<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Services\Emissor\PayloadDaNota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cada teste aqui corresponde a uma recusa real do emissor, já paga uma vez.
 */
class PayloadDaNotaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O prestador é o dono da chave de API. Mandar a inscrição municipal dele
     * fez a Notaas recusar com E0120.
     */
    public function test_o_prestador_nao_viaja_no_corpo(): void
    {
        $payload = (new PayloadDaNota)->montar(Nota::factory()->create());

        $this->assertArrayNotHasKey('prestador', $payload);
        $this->assertStringNotContainsString(config('fiscal.prestador.cnpj'), json_encode($payload));
    }

    /**
     * "tomador.endereco.cidade e tomador.endereco.uf são obrigatórios quando
     * endereço é informado." O código IBGE sozinho não basta.
     */
    public function test_o_endereco_do_tomador_leva_cidade_e_uf_junto_do_ibge(): void
    {
        $nota = Nota::factory()->create([
            'tomador_cidade' => 'Vitória', 'tomador_uf' => 'ES', 'tomador_ibge' => '3205309',
        ]);

        $endereco = (new PayloadDaNota)->montar($nota)['tomador']['endereco'];

        $this->assertSame('Vitória', $endereco['cidade']);
        $this->assertSame('ES', $endereco['uf']);
        $this->assertSame('3205309', $endereco['codigoMunicipio']);
    }

    /**
     * O motivo de o local da prestação ser campo da nota: atendimento em
     * Vitória, ISS devido em Santa Maria de Jetibá. O que vai no serviço é o
     * município do ATENDIMENTO.
     */
    public function test_o_local_da_prestacao_vai_no_servico_e_pode_diferir_do_prestador(): void
    {
        $nota = Nota::factory()->create([
            'perfil' => 'nutricao', 'local_prestacao_ibge' => '3205309',
        ]);

        $payload = (new PayloadDaNota)->montar($nota);

        $this->assertSame('3205309', $payload['servico']['codigoMunicipio']);
        $this->assertNotSame(config('fiscal.prestador.codigo_municipio'), $payload['servico']['codigoMunicipio']);
    }

    public function test_a_nota_de_nutricao_leva_os_codigos_do_perfil(): void
    {
        $servico = (new PayloadDaNota)->montar(Nota::factory()->create(['perfil' => 'nutricao']))['servico'];

        $this->assertSame('041001', $servico['codigo']);
        $this->assertSame('4.10', $servico['itemListaServico']);
        // A DANFSe imprime "1.2301.99.00"; a API exige os 9 dígitos crus e
        // recusou a forma pontuada na primeira emissão real (14/08/2026). O
        // config guarda a forma legível, conferível contra a nota; o payload
        // despe. Se alguém "arrumar" a normalização, este teste acusa.
        $this->assertSame('123019900', $servico['nbs']);
        $this->assertMatchesRegularExpression('/^\d{9}$/', $servico['nbs']);
    }

    /** Software não tem NBS: o campo não pode ir nulo no corpo. */
    public function test_perfil_sem_nbs_nao_manda_o_campo(): void
    {
        $servico = (new PayloadDaNota)->montar(Nota::factory()->create(['perfil' => 'software']))['servico'];

        $this->assertArrayNotHasKey('nbs', $servico);
    }

    public function test_pessoa_fisica_vai_como_cpf_e_juridica_como_cnpj(): void
    {
        $pf = (new PayloadDaNota)->montar(Nota::factory()->create([
            'tomador_tipo' => 'pf', 'tomador_documento' => '52998224725',
        ]));
        $pj = (new PayloadDaNota)->montar(Nota::factory()->create([
            'tomador_tipo' => 'pj', 'tomador_documento' => '11222333000181',
        ]));

        $this->assertSame('52998224725', $pf['tomador']['cpf']);
        $this->assertArrayNotHasKey('cnpj', $pf['tomador']);
        $this->assertSame('11222333000181', $pj['tomador']['cnpj']);
        $this->assertArrayNotHasKey('cpf', $pj['tomador']);
    }

    /** Nota manual não tem referência de origem, e o campo não pode ir vazio. */
    public function test_nota_sem_referencia_nao_manda_o_campo(): void
    {
        $payload = (new PayloadDaNota)->montar(Nota::factory()->create(['referencia_externa' => null]));

        $this->assertArrayNotHasKey('referencia', $payload);
    }
}
