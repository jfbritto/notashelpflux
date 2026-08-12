<?php

namespace Tests\Feature;

use App\Models\Nota;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmissaoManualTest extends TestCase
{
    use RefreshDatabase;

    private User $emissora;

    protected function setUp(): void
    {
        parent::setUp();
        $this->emissora = User::factory()->create(['papel' => 'emissor']);
    }

    /** @return array<string, mixed> */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'perfil' => 'nutricao',
            'tomador_tipo' => 'pj',
            'tomador_documento' => '11.222.333/0001-81',
            'tomador_nome' => 'Clínica Exemplo Ltda',
            'tomador_email' => 'financeiro@exemplo.test',
            'tomador_cep' => '29055-450',
            'tomador_logradouro' => 'Rua Exemplo',
            'tomador_numero' => '78',
            'tomador_bairro' => 'Praia do Canto',
            'tomador_cidade' => 'Vitória',
            'tomador_uf' => 'ES',
            'tomador_ibge' => '3205309',
            'local_prestacao_nome' => 'Vitória',
            'local_prestacao_ibge' => '3205309',
            'descricao' => 'Atendimentos nutricionais - projeto X',
            'valor' => '2100.00',
        ], $extra);
    }

    public function test_visitante_nao_chega_na_tela(): void
    {
        $this->get(route('notas.create'))->assertRedirect(route('login'));
        $this->post(route('notas.store'), $this->formulario())->assertRedirect(route('login'));
    }

    public function test_emitir_pela_tela_cria_a_nota_com_o_perfil_de_nutricao(): void
    {
        $this->actingAs($this->emissora)
            ->post(route('notas.store'), $this->formulario())
            ->assertRedirect(route('notas.index'));

        $nota = Nota::first();

        $this->assertSame('manual', $nota->origem);
        $this->assertSame('nutricao', $nota->perfil);
        $this->assertSame('11222333000181', $nota->tomador_documento); // sem máscara
        $this->assertSame($this->emissora->id, $nota->criada_por);
        $this->assertNotNull($nota->notaas_invoice_id);
    }

    /**
     * A tela escolhe ENTRE os perfis do servidor, nunca os códigos em si.
     */
    public function test_a_tela_emite_nos_dois_tipos_de_servico(): void
    {
        $this->actingAs($this->emissora)->post(route('notas.store'), $this->formulario(['perfil' => 'software']));
        $this->assertSame('software', Nota::first()->perfil);
        $this->assertSame('010501', Nota::first()->perfilDeServico()['codigo_tributacao_nacional']);

        Nota::query()->delete();

        $this->actingAs($this->emissora)->post(route('notas.store'), $this->formulario(['perfil' => 'nutricao']));
        $this->assertSame('041001', Nota::first()->perfilDeServico()['codigo_tributacao_nacional']);
    }

    /**
     * Perfil inventado é recusado. Sem isso, o navegador escolheria a
     * tributação da nota.
     */
    public function test_perfil_desconhecido_e_recusado(): void
    {
        $this->actingAs($this->emissora)
            ->post(route('notas.store'), $this->formulario(['perfil' => 'imposto-zero']))
            ->assertSessionHasErrors('perfil');

        $this->assertSame(0, Nota::count());
    }

    public function test_sem_tipo_de_servico_a_nota_nao_sai(): void
    {
        $this->actingAs($this->emissora)
            ->post(route('notas.store'), $this->formulario(['perfil' => '']))
            ->assertSessionHasErrors('perfil');
    }

    /**
     * O código do serviço acompanha o tipo até dentro do corpo mandado ao
     * emissor, que é onde ele vira nota de verdade.
     */
    public function test_o_codigo_no_payload_acompanha_o_tipo_escolhido(): void
    {
        $this->actingAs($this->emissora)->post(route('notas.store'), $this->formulario(['perfil' => 'software']));

        $servico = (new \App\Services\Emissor\PayloadDaNota)->montar(Nota::first())['servico'];

        $this->assertSame('010501', $servico['codigo']);
        $this->assertSame('1.05', $servico['itemListaServico']);
        $this->assertArrayNotHasKey('nbs', $servico); // software não tem NBS
    }

    /**
     * O campo que existe porque local da prestação e município de incidência
     * do ISS divergem.
     */
    public function test_o_local_do_atendimento_pode_diferir_da_cidade_do_cliente(): void
    {
        $this->actingAs($this->emissora)->post(route('notas.store'), $this->formulario([
            'tomador_cidade' => 'Vitória', 'tomador_ibge' => '3205309',
            'local_prestacao_nome' => 'Santa Maria de Jetibá', 'local_prestacao_ibge' => '3204559',
        ]));

        $nota = Nota::first();

        $this->assertSame('3204559', $nota->local_prestacao_ibge);
        $this->assertSame('3205309', $nota->tomador_ibge);
    }

    public function test_valor_zerado_e_recusado(): void
    {
        $this->actingAs($this->emissora)
            ->post(route('notas.store'), $this->formulario(['valor' => '0']))
            ->assertSessionHasErrors('valor');

        $this->assertSame(0, Nota::count());
    }

    /** Documento inválido derruba a emissão lá na frente, na prefeitura. */
    public function test_documento_invalido_e_recusado(): void
    {
        $this->actingAs($this->emissora)
            ->post(route('notas.store'), $this->formulario(['tomador_documento' => '11222333000100']))
            ->assertSessionHasErrors('tomador_documento');
    }

    public function test_cidade_e_uf_sao_obrigatorias_porque_o_emissor_exige(): void
    {
        $this->actingAs($this->emissora)
            ->post(route('notas.store'), $this->formulario(['tomador_cidade' => '', 'tomador_uf' => '']))
            ->assertSessionHasErrors(['tomador_cidade', 'tomador_uf']);
    }

    public function test_a_tela_abre_com_a_descricao_padrao_do_perfil(): void
    {
        $this->actingAs($this->emissora)
            ->get(route('notas.create'))
            ->assertOk()
            ->assertSee('Atendimentos nutricionais');
    }

    /** Conveniência, não dependência: serviço fora do ar não trava a tela. */
    public function test_busca_de_cep_fora_do_ar_devolve_204(): void
    {
        Http::fake(['*viacep*' => Http::response([], 500)]);

        $this->actingAs($this->emissora)
            ->get(route('consultas.cep', ['cep' => '29055450']))
            ->assertNoContent();
    }

    public function test_busca_de_cep_traz_o_codigo_ibge(): void
    {
        Http::fake(['*viacep*' => Http::response([
            'logradouro' => 'Rua Exemplo', 'bairro' => 'Praia do Canto',
            'localidade' => 'Vitória', 'uf' => 'ES', 'ibge' => '3205309',
        ], 200)]);

        $this->actingAs($this->emissora)
            ->get(route('consultas.cep', ['cep' => '29055450']))
            ->assertOk()
            ->assertJson(['cidade' => 'Vitória', 'ibge' => '3205309']);
    }

    public function test_busca_de_cnpj_traz_razao_social(): void
    {
        Http::fake(['*brasilapi*' => Http::response([
            'razao_social' => 'CLINICA EXEMPLO LTDA', 'municipio' => 'VITORIA', 'uf' => 'ES',
        ], 200)]);

        $this->actingAs($this->emissora)
            ->get(route('consultas.cnpj', ['cnpj' => '11222333000181']))
            ->assertOk()
            ->assertJson(['nome' => 'CLINICA EXEMPLO LTDA']);
    }
}
