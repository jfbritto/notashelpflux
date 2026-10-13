<?php

namespace Tests\Feature;

use App\Models\Aplicacao;
use App\Models\Nota;
use App\Services\Emissor\Emissor;
use App\Services\Emissor\EmissorFalso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A API dos SaaS (spec §5). O emissor é o falso (phpunit.xml) e o retorno
 * cai no Http::fake: nada aqui toca a Notaas nem um SaaS de verdade.
 */
class ApiNotasTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = 'chave-do-hotel-em-dia';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    protected function tearDown(): void
    {
        EmissorFalso::esquecer();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function corpo(array $extra = []): array
    {
        return array_replace_recursive([
            'referencia' => 'fatura-2026-10-001',
            'tomador' => [
                'tipo' => 'pj',
                'documento' => '11.222.333/0001-81',
                'nome' => 'Hotel Exemplo Ltda',
                'email' => 'financeiro@hotel.test',
                'endereco' => [
                    'logradouro' => 'Avenida Beira Mar',
                    'numero' => '100',
                    'complemento' => 'Sala 2',
                    'bairro' => 'Centro',
                    'cidade' => 'Vitória',
                    'uf' => 'es',
                    'cep' => '29055-450',
                ],
            ],
            'valor' => 199.90,
        ], $extra);
    }

    private function aplicacao(array $atributos = []): Aplicacao
    {
        return Aplicacao::factory()->comChave(self::CHAVE)->create($atributos);
    }

    private function comChave(?string $chave = self::CHAVE): static
    {
        return $this->withHeaders(array_filter(['x-api-key' => $chave]));
    }

    // ---------------------------------------------------------------- chave

    /**
     * Ausente, errada e revogada: mesma resposta, para não contar a quem
     * testa chaves qual delas já valeu um dia.
     */
    public function test_chave_ausente_errada_ou_revogada_recebe_o_mesmo_401(): void
    {
        $this->aplicacao();
        Aplicacao::factory()->comChave('chave-revogada')->revogada()->create();

        $respostas = [
            $this->comChave(null)->postJson('/api/notas', $this->corpo()),
            $this->comChave('chave-que-nao-existe')->postJson('/api/notas', $this->corpo()),
            $this->comChave('chave-revogada')->postJson('/api/notas', $this->corpo()),
            $this->comChave('chave-revogada')->getJson('/api/notas'),
        ];

        foreach ($respostas as $resposta) {
            $resposta->assertStatus(401)->assertExactJson(['message' => 'Chave de API inválida.']);
        }

        $this->assertSame(0, Nota::count());
    }

    /** O banco guarda o hash da chave, nunca ela: quem lê o banco não emite. */
    public function test_o_hash_da_chave_nao_serve_como_chave(): void
    {
        $aplicacao = $this->aplicacao();

        $this->comChave($aplicacao->chave_hash)->getJson('/api/notas')->assertStatus(401);
        $this->comChave()->getJson('/api/notas')->assertOk();
    }

    // ---------------------------------------------------------------- criar

    public function test_cria_a_nota_pelo_mesmo_caminho_da_tela_e_responde_201(): void
    {
        $this->aplicacao();

        $this->comChave()->postJson('/api/notas', $this->corpo())
            ->assertStatus(201)
            ->assertExactJson(['data' => [
                'referencia' => 'fatura-2026-10-001',
                'status' => 'processando',
                'numero' => null,
                'chave_acesso' => null,
                'pdf_url' => null,
                'xml_url' => null,
                'erro' => null,
                'emitida_em' => null,
            ]]);

        $nota = Nota::sole();
        $this->assertSame('hotelemdia', $nota->origem);
        $this->assertSame('software', $nota->perfil);
        // Enviada ao emissor como a da tela: o id dele ficou gravado.
        $this->assertSame('fake-'.$nota->id, $nota->notaas_invoice_id);
        $this->assertSame('11222333000181', $nota->tomador_documento);
        $this->assertSame('29055450', $nota->tomador_cep);
        $this->assertSame('ES', $nota->tomador_uf);
        $this->assertSame('Sala 2', $nota->tomador_complemento);
        // Sem descrição e sem competência no corpo: o padrão do perfil e o mês corrente.
        $this->assertSame(config('fiscal.perfis.software.descricao_padrao'), $nota->descricao);
        $this->assertSame(now()->format('Y-m'), $nota->competencia);
        $this->assertNull($nota->criada_por);
    }

    /**
     * O SaaS repete a chamada depois de um timeout. A segunda devolve a nota
     * da primeira, com 200, e não chega ao emissor: reenviar seria a
     * duplicata.
     */
    public function test_a_mesma_referencia_nao_vira_duas_notas(): void
    {
        $this->aplicacao();

        $this->comChave()->postJson('/api/notas', $this->corpo())->assertStatus(201);

        EmissorFalso::proibirEnvio();

        $this->comChave()->postJson('/api/notas', $this->corpo(['valor' => 999]))
            ->assertStatus(200)
            ->assertJsonPath('data.referencia', 'fatura-2026-10-001');

        $this->assertSame(1, Nota::count());
        $this->assertEquals(199.90, Nota::sole()->valor);
    }

    /**
     * A idempotência é por (origem, referência): a mesma referência vinda de
     * outro SaaS é outra cobrança, outra nota.
     */
    public function test_a_mesma_referencia_de_outra_origem_e_outra_nota(): void
    {
        $this->aplicacao();
        Aplicacao::factory()->comChave('chave-do-treinaedu')->create(['origem' => 'treinaedu']);

        $this->comChave()->postJson('/api/notas', $this->corpo())->assertStatus(201);
        $this->comChave('chave-do-treinaedu')->postJson('/api/notas', $this->corpo())->assertStatus(201);

        $this->assertSame(2, Nota::count());
    }

    /**
     * Origem e perfil saem do cadastro da aplicação. O corpo pode tentar
     * escolher código fiscal e se passar por nota manual: é ignorado.
     */
    public function test_a_aplicacao_emite_pelo_proprio_perfil_e_origem(): void
    {
        $this->aplicacao(['origem' => 'helpdiet', 'perfil' => 'desenvolvimento']);

        $this->comChave()->postJson('/api/notas', $this->corpo([
            'origem' => 'manual',
            'perfil' => 'nutricao',
            'codigo_tributacao_nacional' => '041001',
            'local_prestacao_ibge' => '3205309',
            'callback_url' => 'https://invasor.test/',
        ]))->assertStatus(201);

        $nota = Nota::sole();
        $this->assertSame('helpdiet', $nota->origem);
        $this->assertSame('desenvolvimento', $nota->perfil);
        // Local da prestação: o padrão do perfil (prestador), não o do corpo.
        $this->assertSame(config('fiscal.prestador.codigo_municipio'), $nota->local_prestacao_ibge);
        $this->assertSame(config('fiscal.prestador.nome_municipio'), $nota->local_prestacao_nome);
    }

    public function test_descricao_e_competencia_do_corpo_sao_respeitadas(): void
    {
        $this->aplicacao();

        $this->comChave()->postJson('/api/notas', $this->corpo([
            'descricao' => 'Mensalidade Hotel em Dia, outubro/2026',
            'competencia' => '2026-09',
        ]))->assertStatus(201);

        $nota = Nota::sole();
        $this->assertSame('Mensalidade Hotel em Dia, outubro/2026', $nota->descricao);
        $this->assertSame('2026-09', $nota->competencia);
    }

    /** Recusa do emissor na hora: a nota existe, com o erro, e a resposta diz. */
    public function test_recusa_imediata_do_emissor_volta_na_resposta(): void
    {
        $this->aplicacao();
        $this->app->bind(Emissor::class, fn () => new class extends EmissorFalso
        {
            public function enviar(Nota $nota): array
            {
                return ['status' => 'erro', 'erro' => 'E0120 inscrição do prestador'];
            }
        });

        $this->comChave()->postJson('/api/notas', $this->corpo())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'erro')
            ->assertJsonPath('data.erro', 'E0120 inscrição do prestador');
    }

    public function test_corpo_incompleto_recebe_422_e_nao_cria_nota(): void
    {
        $this->aplicacao();

        $this->comChave()->postJson('/api/notas', ['referencia' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tomador', 'valor']);

        $this->comChave()->postJson('/api/notas', $this->corpo(['tomador' => ['endereco' => ['cidade' => '', 'uf' => '']]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tomador.endereco.cidade', 'tomador.endereco.uf']);

        $this->assertSame(0, Nota::count());
    }

    /** CPF como `pj` sairia no campo `cnpj` do payload e a Notaas recusaria. */
    public function test_tipo_do_tomador_tem_de_bater_com_o_documento(): void
    {
        $this->aplicacao();

        $this->comChave()->postJson('/api/notas', $this->corpo(['tomador' => ['tipo' => 'pf']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tomador.documento']);

        $this->comChave()->postJson('/api/notas', $this->corpo(['tomador' => ['tipo' => 'pf', 'documento' => '529.982.247-25']]))
            ->assertStatus(201);
    }

    // ------------------------------------------------------------- consultar

    public function test_consulta_a_nota_da_propria_origem_pela_referencia(): void
    {
        $this->aplicacao();
        Nota::factory()->emitida()->create([
            'origem' => 'hotelemdia',
            'referencia_externa' => 'fatura/2026-10',
            'pdf_url' => 'https://storage.notaas.test/n.pdf',
        ]);

        $this->comChave()->getJson('/api/notas/fatura/2026-10')
            ->assertOk()
            ->assertJsonPath('data.referencia', 'fatura/2026-10')
            ->assertJsonPath('data.status', 'emitida')
            ->assertJsonPath('data.chave_acesso', '32045592258063432000121000000000000126089909776545')
            ->assertJsonPath('data.pdf_url', 'https://storage.notaas.test/n.pdf');
    }

    /**
     * Nota de outra origem não existe para esta aplicação: 404, e não 403,
     * que confirmaria a existência dela.
     */
    public function test_nota_de_outra_origem_responde_404(): void
    {
        $this->aplicacao();
        Nota::factory()->create(['origem' => 'treinaedu', 'referencia_externa' => 'inv-1']);
        Nota::factory()->create(['origem' => 'manual', 'referencia_externa' => null]);

        $this->comChave()->getJson('/api/notas/inv-1')
            ->assertStatus(404)
            ->assertJson(['message' => 'Nota não encontrada.']);
    }

    public function test_a_lista_traz_so_as_notas_da_origem_paginadas(): void
    {
        $this->aplicacao();
        Nota::factory()->count(3)->sequence(
            ['referencia_externa' => 'f-1'], ['referencia_externa' => 'f-2'], ['referencia_externa' => 'f-3'],
        )->create(['origem' => 'hotelemdia']);
        Nota::factory()->create(['origem' => 'treinaedu', 'referencia_externa' => 'inv-1']);
        Nota::factory()->create(['origem' => 'manual']);

        $this->comChave()->getJson('/api/notas')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.referencia', 'f-3')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
    }
}
