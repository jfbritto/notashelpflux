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
     *
     * `codigoMunicipio` NÃO EXISTE em `tomador.endereco` no contrato deles
     * (conferido em https://docs.notaas.com.br/endpoints, 14/08/2026): a
     * Notaas resolve o IBGE do tomador sozinha, a partir de cidade + uf. Era
     * campo morto na primeira versão; este teste agora garante que ele NÃO É
     * mandado, em vez de continuar mandando algo que nunca foi lido.
     */
    public function test_o_endereco_do_tomador_leva_cidade_e_uf_e_nao_manda_campo_inexistente(): void
    {
        $nota = Nota::factory()->create([
            'tomador_cidade' => 'Vitória', 'tomador_uf' => 'ES', 'tomador_ibge' => '3205309',
        ]);

        $endereco = (new PayloadDaNota)->montar($nota)['tomador']['endereco'];

        $this->assertSame('Vitória', $endereco['cidade']);
        $this->assertSame('ES', $endereco['uf']);
        $this->assertArrayNotHasKey('codigoMunicipio', $endereco);
    }

    /**
     * O motivo de o local da prestação ser campo da nota: atendimento em
     * Vitória, ISS devido em Santa Maria de Jetibá. O que vai no serviço é o
     * município do ATENDIMENTO, no campo `localPrestacao` (não
     * `codigoMunicipio`, que não existe no contrato — foi o nome errado que
     * saiu na nota real de 14/08/2026 com o local do PRESTADOR em vez do
     * escolhido na tela, porque a Notaas ignorou a chave desconhecida e
     * aplicou o padrão do projeto).
     */
    public function test_o_local_da_prestacao_vai_no_campo_certo_e_pode_diferir_do_prestador(): void
    {
        $nota = Nota::factory()->create([
            'perfil' => 'nutricao', 'local_prestacao_ibge' => '3205309',
        ]);

        $payload = (new PayloadDaNota)->montar($nota);

        $this->assertSame('3205309', $payload['servico']['localPrestacao']);
        $this->assertArrayNotHasKey('codigoMunicipio', $payload['servico']);
        $this->assertNotSame(config('fiscal.prestador.codigo_municipio'), $payload['servico']['localPrestacao']);
    }

    public function test_a_nota_de_nutricao_leva_os_codigos_do_perfil(): void
    {
        $servico = (new PayloadDaNota)->montar(Nota::factory()->create(['perfil' => 'nutricao']))['servico'];

        $this->assertSame('041001', $servico['codigo']);
        // NÃO existe campo para o item da LC 116 (tipo "4.10") no contrato
        // deles: só o cTribNac (`codigo`, 6 dígitos). Mandar
        // `itemListaServico` era chave morta desde o primeiro dia.
        $this->assertArrayNotHasKey('itemListaServico', $servico);
        // A DANFSe imprime "1.2301.99.00"; a API exige os 9 dígitos crus e
        // recusou a forma pontuada na primeira emissão real (14/08/2026). O
        // config guarda a forma legível, conferível contra a nota; o payload
        // despe. Se alguém "arrumar" a normalização, este teste acusa.
        $this->assertSame('123019900', $servico['nbs']);
        $this->assertMatchesRegularExpression('/^\d{9}$/', $servico['nbs']);
    }

    /**
     * Confirmado contra nota real emitida para a IJR Media Holdings LLC (RPS
     * 181, 06/07/2026, cliente de desenvolvimento sob medida): resolve a
     * pendência que existia sobre o item 1.01.
     */
    public function test_a_nota_de_desenvolvimento_leva_os_codigos_do_perfil(): void
    {
        $servico = (new PayloadDaNota)->montar(Nota::factory()->create(['perfil' => 'desenvolvimento']))['servico'];

        $this->assertSame('010101', $servico['codigo']);
        $this->assertArrayNotHasKey('itemListaServico', $servico);
        $this->assertSame('115022000', $servico['nbs']);
        $this->assertMatchesRegularExpression('/^\d{9}$/', $servico['nbs']);
    }

    /**
     * Hoje os três perfis cadastrados têm NBS (todos confirmados contra nota
     * real). O comportamento de omitir o campo quando não há NBS continua
     * existindo no código para o dia em que um perfil novo não tiver um
     * mapeamento de NBS, por isso o teste força esse caso via config em vez
     * de depender de um perfil real ficar sem NBS.
     */
    public function test_perfil_sem_nbs_nao_manda_o_campo(): void
    {
        config(['fiscal.perfis.software.nbs' => null]);

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
